<?php

declare(strict_types=1);

namespace FurkiCycle\DynamicStatus;

use FurkiCycle\BiInsight\BiInsightSyncService;
use FurkiCycle\Config\Env;
use FurkiCycle\Furk\DynamicStatusClient;
use PDO;
use RuntimeException;

final class DynamicStatusImportService
{
    private const MAX_DAYS = 30;
    private const MIN_DAYS_PER_PART = 1;
    private const MAX_DAYS_PER_PART = 10;

    /** @var \Closure(array<string, mixed>): ?string|null conferência entre partes (leitura manual: parada/tempo) */
    private ?\Closure $checkpoint = null;

    public function __construct(
        private readonly Env $env,
        private readonly PDO $connection,
        private readonly DynamicStatusClient $client,
        private readonly DynamicStatusRepository $repository,
        private readonly ?BiInsightSyncService $biInsight = null,
    ) {
    }

    /**
     * @return array{
     *     parts: int,
     *     pages: int,
     *     expected: int,
     *     tickets: int,
     *     segments: int,
     *     deleted_tickets: int,
     *     deleted_segments: int
     * }
     */
    public function import(string $initialIssueDate, string $finalIssueDate, int $daysPerPart): array
    {
        $this->validateRange($initialIssueDate, $finalIssueDate, $daysPerPart);

        $pageSize = $this->env->int('FURK_DYNAMIC_STATUS_PAGE_SIZE', 100);
        $maxRetries = $this->env->int('FURK_DYNAMIC_STATUS_MAX_RETRIES', 3);
        $delayMs = $this->env->int('FURK_DYNAMIC_STATUS_DELAY_MS', 50);

        $summary = [
            'parts' => 0,
            'pages' => 0,
            'expected' => 0,
            'tickets' => 0,
            'segments' => 0,
            'deleted_tickets' => 0,
            'deleted_segments' => 0,
        ];

        foreach ($this->periods($initialIssueDate, $finalIssueDate, $daysPerPart) as $period) {
            $items = $this->fetchAll($period['start'], $period['end'], $pageSize, $maxRetries, $delayMs);
            $expected = $items['expected'];

            $summary['parts']++;
            $summary['pages'] += $items['pages'];
            $summary['expected'] += $expected;

            foreach ($items['tickets'] as $ticket) {
                $this->repository->validate($ticket);
            }

            if ($expected > 0 && count($items['tickets']) !== $expected) {
                throw new RuntimeException("A Furk retornou {$expected} bilhetes, mas somente " . count($items['tickets']) . ' foram recebidos no período. Nenhum dado local foi apagado.');
            }

            $this->connection->beginTransaction();
            try {
                $deleted = $this->repository->deleteByIssueDateRange($period['start'], $period['end']);

                $this->repository->insertTickets($items['tickets']);
                $segments = $this->repository->insertSegmentsBatch($items['tickets']);

                $this->connection->commit();
            } catch (\Throwable $error) {
                $this->connection->rollBack();
                throw $error;
            }

            $summary['tickets'] += count($items['tickets']);
            $summary['segments'] += $segments;
            $summary['deleted_tickets'] += $deleted['tickets'];
            $summary['deleted_segments'] += $deleted['segments'];

            // Conferência entre partes (leitura manual: parada do ADMIN ou orçamento de tempo). Seguro: cada parte já
            // foi gravada por inteiro numa transação (apaga e regrava só o seu período), nunca fica pela metade.
            if ($this->checkpoint !== null && $period['end'] < $finalIssueDate) {
                $reason = ($this->checkpoint)(['pagina' => $summary['pages'], 'registros' => $summary['tickets'], 'ate' => $period['end']]);
                if ($reason !== null) {
                    $summary['parcial'] = true;
                    $summary['motivo'] = $reason;
                    $summary['ate_data'] = $period['end'];
                    break;
                }
            }
        }

        // Leitura parcial: atualiza a bi_insight só do trecho já gravado.
        $summary['bi_insight'] = $this->syncBiInsight('furkapi_bilhetes_aereos', $initialIssueDate, ($summary['parcial'] ?? false) ? (string) $summary['ate_data'] : $finalIssueDate);

        return $summary;
    }

    /**
     * Ponto de conferência chamado ao fim de cada parte (se ainda houver mais): null continua; senão, o motivo da parada.
     *
     * @param \Closure(array<string, mixed>): ?string|null $checkpoint
     */
    public function setCheckpoint(?\Closure $checkpoint): self
    {
        $this->checkpoint = $checkpoint;

        return $this;
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

    /**
     * @return array{tickets: array<int, array<string, mixed>>, pages: int, expected: int}
     */
    private function fetchAll(string $start, string $end, int $pageSize, int $maxRetries, int $delayMs): array
    {
        $tickets = [];
        $expected = 0;
        $pages = 0;
        $page = 1;
        $totalPages = 1;

        do {
            $response = $this->fetchPage($start, $end, $page, $pageSize, $maxRetries);
            $items = $this->items($response);
            $tickets = [...$tickets, ...$items];
            $expected = max($expected, (int) ($response['totalElements'] ?? 0));
            $totalPages = max($page, (int) ($response['totalPages'] ?? ($items === [] ? $page : $page + 1)));
            $pages = $page;
            $page++;

            if ($page <= $totalPages && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
        } while ($page <= $totalPages);

        return ['tickets' => $tickets, 'pages' => $pages, 'expected' => $expected];
    }

    /** @return array<string, mixed> */
    private function fetchPage(string $start, string $end, int $page, int $size, int $maxRetries): array
    {
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->client->list($start, $end, $page, $size);
            } catch (RuntimeException $error) {
                if ($attempt === $maxRetries) {
                    throw new RuntimeException("Falha ao consultar a Furk (tentativa {$attempt}): {$error->getMessage()}");
                }
                $this->backoff($attempt);
            }
        }

        throw new RuntimeException('Não foi possível concluir a consulta à Furk. Tente novamente.');
    }

    /** @param array<string, mixed> $response @return array<int, array<string, mixed>> */
    private function items(array $response): array
    {
        $items = $response['data'] ?? $response['content'] ?? null;
        if (!is_array($items)) {
            throw new RuntimeException('A Furk retornou uma resposta sem a lista de dados. Tente novamente.');
        }

        $result = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /** @param array<int, array<string, mixed>> $items */
    private function periods(string $start, string $end, int $daysPerPart): array
    {
        $result = [];
        $current = new \DateTimeImmutable($start);
        $last = new \DateTimeImmutable($end);

        while ($current <= $last) {
            $periodEnd = $current->modify('+' . ($daysPerPart - 1) . ' days');
            if ($periodEnd > $last) {
                $periodEnd = $last;
            }
            $result[] = ['start' => $current->format('Y-m-d'), 'end' => $periodEnd->format('Y-m-d')];
            $current = $periodEnd->modify('+1 day');
        }

        return $result;
    }

    /** Backoff exponencial com jitter: 1s, 2s, 4s, ... */
    private function backoff(int $attempt): void
    {
        $seconds = (int) round(pow(2, $attempt - 1));
        usleep(($seconds * 1_000_000) + random_int(0, 500_000));
    }

    private function validateRange(string $initialIssueDate, string $finalIssueDate, int $daysPerPart): void
    {
        $initial = \DateTimeImmutable::createFromFormat('!Y-m-d', $initialIssueDate);
        $final = \DateTimeImmutable::createFromFormat('!Y-m-d', $finalIssueDate);
        if ($initial === false || $final === false || $initial->format('Y-m-d') !== $initialIssueDate || $final->format('Y-m-d') !== $finalIssueDate) {
            throw new RuntimeException('Informe as datas no formato AAAA-MM-DD.');
        }
        if ($initial > $final) {
            throw new RuntimeException('A data inicial não pode ser posterior à data final.');
        }
        if ($initial->diff($final)->days + 1 > self::MAX_DAYS) {
            throw new RuntimeException('O período máximo é de ' . self::MAX_DAYS . ' dias.');
        }
        if ($daysPerPart < self::MIN_DAYS_PER_PART || $daysPerPart > self::MAX_DAYS_PER_PART) {
            throw new RuntimeException('Dias por parte deve estar entre ' . self::MIN_DAYS_PER_PART . ' e ' . self::MAX_DAYS_PER_PART . '.');
        }
    }
}