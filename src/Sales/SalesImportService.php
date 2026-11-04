<?php

declare(strict_types=1);

namespace FurkiCycle\Sales;

use FurkiCycle\BiInsight\BiInsightSyncService;
use FurkiCycle\Config\Env;
use FurkiCycle\Furk\SalesClient;
use PDO;
use RuntimeException;

final class SalesImportService
{
    /** @var \Closure(array<string, mixed>): ?string|null conferência entre páginas (leitura manual: parada/tempo) */
    private ?\Closure $checkpoint = null;

    /** @var \Closure(string, string, int, int): array<string, mixed>|null busca de página alternativa (testes) */
    private ?\Closure $pageFetcher = null;

    public function __construct(
        private readonly Env $env,
        private readonly PDO $connection,
        private readonly SalesClient $client,
        private readonly SalesRepository $repository,
        private readonly ?BiInsightSyncService $biInsight = null,
    ) {
    }

    /**
     * Ponto de conferência chamado ao fim de cada página (se ainda houver mais): devolve null para continuar ou o
     * motivo da parada ('interrompido' | 'tempo'). Seguro porque as vendas só são regravadas (nunca apagadas) por página.
     *
     * @param \Closure(array<string, mixed>): ?string|null $checkpoint
     */
    public function setCheckpoint(?\Closure $checkpoint): self
    {
        $this->checkpoint = $checkpoint;

        return $this;
    }

    /** @param \Closure(string, string, int, int): array<string, mixed>|null $fetcher */
    public function setPageFetcher(?\Closure $fetcher): self
    {
        $this->pageFetcher = $fetcher;

        return $this;
    }

    /**
     * Sincroniza as vendas do período informado na base local.
     *
     * @return array{total: int, inserted: int, updated: int, unchanged: int, pages: int}
     */
    public function import(string $initialIssueDate, string $finalIssueDate): array
    {
        $this->validateRange($initialIssueDate, $finalIssueDate);

        $size = $this->env->int('FURK_SALES_PAGE_SIZE', 100);
        $maxPages = $this->env->int('FURK_SALES_MAX_PAGES', 1000);
        $maxRetries = $this->env->int('FURK_SALES_MAX_RETRIES', 3);
        $delayMs = $this->env->int('FURK_SALES_DELAY_MS', 100);

        $summary = [
            'total' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'pages' => 0,
        ];
        $lastPage = null;
        $until = null;

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->fetchPage($initialIssueDate, $finalIssueDate, $page, $size, $maxRetries);
            $items = $response['data'] ?? ($response['content'] ?? []);
            if (!is_array($items)) {
                throw new RuntimeException('A Furk retornou as vendas em um formato inesperado. Tente novamente.');
            }

            $this->connection->beginTransaction();
            try {
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        throw new RuntimeException('A Furk retornou um registro de venda inválido.');
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
            $until = $this->latestIssueDate($items) ?? $until;

            if ($items === [] || $page >= $lastPage) {
                break;
            }

            if ($this->checkpoint !== null) {
                $reason = ($this->checkpoint)(['pagina' => $page, 'registros' => $summary['total'], 'ate' => $until]);
                if ($reason !== null) {
                    // Leitura parcial: o que foi gravado fica (é só regravação); informa até onde foi.
                    $summary['parcial'] = true;
                    $summary['motivo'] = $reason;
                    $summary['ate_data'] = $until;
                    break;
                }
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        if ($summary['pages'] >= $maxPages && ($lastPage === null || $lastPage > $maxPages)) {
            throw new RuntimeException('A sincronização atingiu o limite de páginas configurado. Reduza o período e tente novamente.');
        }

        // Leitura parcial: atualiza a bi_insight só do trecho já gravado.
        $summary['bi_insight'] = $this->syncBiInsight('furkapi_sales', $initialIssueDate, ($summary['parcial'] ?? false) && isset($summary['ate_data']) ? max($initialIssueDate, (string) $summary['ate_data']) : $finalIssueDate);

        return $summary;
    }

    /** @param array<int, mixed> $items maior data de emissão (AAAA-MM-DD) entre as vendas da página */
    private function latestIssueDate(array $items): ?string
    {
        $latest = null;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $raw = $item['ticketID']['issueDate'] ?? ($item['ticket']['ticketID']['issueDate'] ?? null);
            if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) === 1) {
                $day = substr($raw, 0, 10);
                if ($latest === null || $day > $latest) {
                    $latest = $day;
                }
            }
        }

        return $latest;
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
                if ($this->pageFetcher !== null) {
                    return ($this->pageFetcher)($initialIssueDate, $finalIssueDate, $page, $size);
                }

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

        return $itemCount < $size ? $currentPage : $this->env->int('FURK_SALES_MAX_PAGES', 1000);
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
