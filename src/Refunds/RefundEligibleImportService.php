<?php

declare(strict_types=1);

namespace FurkiCycle\Refunds;

use FurkiCycle\BiInsight\BiInsightSyncService;
use FurkiCycle\Config\Env;
use FurkiCycle\Furk\EligibleCreditsClient;
use FurkiCycle\Furk\RefundEligibleClient;
use PDO;
use RuntimeException;

final class RefundEligibleImportService
{
    public function __construct(
        private readonly Env $env,
        private readonly PDO $connection,
        private readonly RefundEligibleClient $client,
        private readonly EligibleCreditsClient $creditClient,
        private readonly RefundEligibleRepository $repository,
        private readonly ?BiInsightSyncService $biInsight = null,
    ) {
    }

    /**
     * Sincroniza os reembolsos elegíveis do período informado e enriquece o
     * crédito estimado de cada bilhete.
     *
     * @return array{total: int, inserted: int, updated: int, unchanged: int, pages: int, credits_found: int, credits_missing: int}
     */
    public function import(string $initialIssueDate, string $finalIssueDate): array
    {
        $this->validateRange($initialIssueDate, $finalIssueDate);

        $size = $this->env->int('FURK_REFUND_PAGE_SIZE', 100);
        $maxPages = $this->env->int('FURK_REFUND_MAX_PAGES', 1000);
        $maxRetries = $this->env->int('FURK_REFUND_MAX_RETRIES', 3);
        $delayMs = $this->env->int('FURK_REFUND_DELAY_MS', 100);

        $summary = [
            'total' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'pages' => 0,
            'credits_found' => 0,
            'credits_missing' => 0,
        ];
        $lastPage = null;

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->fetchPage($initialIssueDate, $finalIssueDate, $page, $size, $maxRetries);
            $items = $response['data'] ?? ($response['content'] ?? []);
            if (!is_array($items)) {
                throw new RuntimeException('A Furk retornou dados em um formato inesperado. Tente novamente.');
            }

            $rows = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new RuntimeException('A Furk retornou um registro de reembolso inválido.');
                }

                $estimatedCredit = $this->estimatedCreditFor($this->ticketNumber($item));
                if ($estimatedCredit !== null) {
                    $summary['credits_found']++;
                } else {
                    $summary['credits_missing']++;
                }

                $rows[] = [$item, $estimatedCredit];
            }

            $this->connection->beginTransaction();
            try {
                foreach ($rows as [$item, $estimatedCredit]) {
                    $result = $this->repository->upsert($item, $estimatedCredit);
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

        $summary['bi_insight'] = $this->syncBiInsight('furkapi_refunds_elegiveis', $initialIssueDate, $finalIssueDate);

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

    /**
     * Busca o crédito estimado do bilhete. Uma falha na consulta de crédito (por
     * exemplo, acesso ainda não habilitado pela Furk) não interrompe a importação:
     * o reembolso é gravado com o crédito estimado nulo.
     */
    private function estimatedCreditFor(string $ticketNumber): ?string
    {
        if ($ticketNumber === '') {
            return null;
        }

        try {
            $credit = $this->creditClient->findByTicketNumber($ticketNumber);
        } catch (\Throwable) {
            return null;
        }

        if ($credit === null) {
            return null;
        }

        $value = $credit['estimatedCredit'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) || is_numeric($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $item */
    private function ticketNumber(array $item): string
    {
        $ticketId = $item['ticketID'] ?? ($item['ticket']['ticketID'] ?? null);
        if (!is_array($ticketId)) {
            return '';
        }

        $value = $ticketId['ticketNumber'] ?? null;

        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $response */
    private function totalPages(array $response, int $currentPage, int $itemCount, int $size): int
    {
        $pagination = $response['pagination'] ?? null;
        $totalPages = is_array($pagination) ? ($pagination['totalPages'] ?? null) : ($response['totalPages'] ?? null);

        if (is_int($totalPages) || is_numeric($totalPages)) {
            return max($currentPage, (int) $totalPages);
        }

        return $itemCount < $size ? $currentPage : $this->env->int('FURK_REFUND_MAX_PAGES', 1000);
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
