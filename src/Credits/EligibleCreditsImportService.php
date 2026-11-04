<?php

declare(strict_types=1);

namespace FurkiCycle\Credits;

use FurkiCycle\BiInsight\BiInsightSyncService;
use FurkiCycle\Config\Env;
use FurkiCycle\Furk\EligibleCreditsClient;
use PDO;
use RuntimeException;

final class EligibleCreditsImportService
{
    public function __construct(
        private readonly Env $env,
        private readonly PDO $connection,
        private readonly EligibleCreditsClient $client,
        private readonly EligibleCreditRepository $repository,
        private readonly ?BiInsightSyncService $biInsight = null,
    ) {
    }

    /** @return array{total: int, inserted: int, updated: int, unchanged: int, pages: int} */
    public function import(string $initialIssueDate, string $finalIssueDate): array
    {
        $this->validateRange($initialIssueDate, $finalIssueDate);

        $size = $this->env->int('FURK_CREDITS_PAGE_SIZE', 100);
        $maxPages = $this->env->int('FURK_CREDITS_MAX_PAGES', 1000);
        $maxRetries = $this->env->int('FURK_CREDITS_MAX_RETRIES', 3);
        $delayMs = $this->env->int('FURK_CREDITS_DELAY_MS', 100);
        $summary = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'pages' => 0];
        $lastPage = null;
        $deleted = false;

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->fetchPage($initialIssueDate, $finalIssueDate, $page, $size, $maxRetries);
            $items = $response['data'] ?? [];
            if (!is_array($items)) {
                throw new RuntimeException('A Furk retornou dados em um formato inesperado. Tente novamente.');
            }

            $this->connection->beginTransaction();
            try {
                // Só apaga o período após a Furk responder OK (primeira página bem-sucedida),
                // dentro da transação. Assim, se a consulta falhar, nada é apagado.
                if (!$deleted) {
                    $this->repository->deleteByIssueDateRange($initialIssueDate, $finalIssueDate);
                    $deleted = true;
                }
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        throw new RuntimeException('A Furk retornou um registro de crédito inválido.');
                    }
                    $result = $this->repository->upsert($item);
                    $summary['total']++;
                    $summary[$result]++;
                }
                $this->connection->commit();
            } catch (\Throwable $error) {
                $this->connection->rollBack();
                throw $error;
            }

            $summary['pages'] = $page;
            $lastPage ??= $this->totalPages($response, $page, count($items), $size);

            if ($items === [] || $page >= $lastPage) {
                break;
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        if ($summary['pages'] >= $maxPages && ($lastPage === null || $lastPage > $maxPages)) {
            throw new RuntimeException('A sincronização atingiu o limite de páginas configurado. Reduza o período e tente novamente.');
        }

        $summary['bi_insight'] = $this->syncBiInsight('furkapi_creditos_elegiveis', $initialIssueDate, $finalIssueDate);

        return $summary;
    }

    /** @return array<string, mixed> */
    private function syncBiInsight(string $sourceTable, string $initial, string $final): array
    {
        $service = $this->biInsight ?? new BiInsightSyncService($this->env, $this->connection);
        try {
            return $service->sync($sourceTable, $initial, $final);
        } catch (\Throwable $error) {
            return ['error' => $error->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function fetchPage(string $initialIssueDate, string $finalIssueDate, int $page, int $size, int $maxRetries): array
    {
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->client->list($initialIssueDate, $finalIssueDate, $page, $size);
            } catch (RuntimeException $error) {
                if ($attempt === $maxRetries) {
                    throw new RuntimeException("Falha ao consultar a Furk (tentativa {$attempt}): {$error->getMessage()}");
                }
                $this->backoff($attempt);
            }
        }

        throw new RuntimeException('Não foi possível concluir a consulta à Furk. Tente novamente.');
    }

    /** @param array<string, mixed> $response */
    private function totalPages(array $response, int $currentPage, int $itemCount, int $size): int
    {
        $pagination = $response['pagination'] ?? null;
        $totalPages = is_array($pagination) ? ($pagination['totalPages'] ?? null) : ($response['totalPages'] ?? null);

        if (is_int($totalPages) || is_numeric($totalPages)) {
            return max($currentPage, (int) $totalPages);
        }

        return $itemCount < $size ? $currentPage : $this->env->int('FURK_CREDITS_MAX_PAGES', 1000);
    }

    /** Backoff exponencial com jitter: 1s, 2s, 4s, ... */
    private function backoff(int $attempt): void
    {
        $seconds = (int) round(pow(2, $attempt - 1));
        usleep(($seconds * 1_000_000) + random_int(0, 500_000));
    }

    private function validateRange(string $initialIssueDate, string $finalIssueDate): void
    {
        $initial = \DateTimeImmutable::createFromFormat('!Y-m-d', $initialIssueDate);
        $final = \DateTimeImmutable::createFromFormat('!Y-m-d', $finalIssueDate);
        if ($initial === false || $final === false || $initial->format('Y-m-d') !== $initialIssueDate || $final->format('Y-m-d') !== $finalIssueDate) {
            throw new RuntimeException('Informe as datas no formato AAAA-MM-DD.');
        }
        if ($initial > $final) {
            throw new RuntimeException('A data inicial não pode ser posterior à data final.');
        }
    }
}
