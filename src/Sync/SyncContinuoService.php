<?php

declare(strict_types=1);

namespace FurkiCycle\Sync;

use DateTimeImmutable;
use FurkiCycle\Config\Env;
use FurkiCycle\Credits\EligibleCreditRepository;
use FurkiCycle\Credits\EligibleCreditsImportService;
use FurkiCycle\DynamicStatus\DynamicStatusImportService;
use FurkiCycle\DynamicStatus\DynamicStatusRepository;
use FurkiCycle\Furk\DynamicStatusClient;
use FurkiCycle\Furk\EligibleCreditsClient;
use FurkiCycle\Furk\OAuthClient;
use FurkiCycle\Furk\RefundEligibleClient;
use FurkiCycle\Furk\SalesClient;
use FurkiCycle\Refunds\RefundEligibleImportService;
use FurkiCycle\Refunds\RefundEligibleRepository;
use FurkiCycle\Sales\SalesImportService;
use FurkiCycle\Sales\SalesRepository;
use PDO;
use RuntimeException;

/**
 * Sincronização Consolidada Contínua — varredura de períodos longos em blocos.
 *
 * Processa um período grande (ex.: um ano) em blocos de N dias, reutilizando os
 * mesmos Services de importação (Sales, Créditos, Refunds, Status Dinâmicos).
 * A cada bloco concluído, grava o `cursor_date` (Próxima data a processar) — se
 * a execução cair no meio, o bloco em andamento é refeito (importações são
 * idempotentes) e nada se perde.
 *
 * Estado/erros: erro FATAL (conexão/autenticação) para a varredura; erro
 * pontual de um bloco é registrado no log e a varredura segue (permite
 * "Reprocessar falhos"). Circuito de segurança: N blocos seguidos com erro.
 */
final class SyncContinuoService
{
    private const LOCK = 'furk_sync_continuo_routine';

    /** Ordem de processamento das tabelas em cada bloco. */
    private const TABLES = ['sales', 'creditos', 'refunds', 'bilhetes'];

    private const MAX_BLOCK_DAYS = 10;
    /** Data inicial mínima aceita: protege de um ano digitado pela metade (ex.: 0002 geraria ~150 mil blocos). */
    private const MIN_DATE = '2020-01-01';
    private const MIN_BLOCK_DAYS = 1;
    private const MAX_CONSECUTIVE_ERROR_BLOCKS = 3;

    public function __construct(
        private readonly Env $env,
        private readonly PDO $connection,
        private readonly SyncContinuoRepository $repository,
    ) {
    }

    /** @return array<string, mixed> */
    public function start(string $initialDate, string $finalDate, int $blockDays): array
    {
        $this->validate($initialDate, $finalDate, $blockDays);

        if ($this->repository->findActive() !== null) {
            throw new RuntimeException('Já existe uma sincronização contínua em andamento. Interrompa-a antes de iniciar outra.');
        }

        $final = min($finalDate, $this->yesterday());
        if ($final < $initialDate) {
            throw new RuntimeException('O período informado está no futuro (a leitura vai até ontem, D-1).');
        }

        $id = $this->repository->create($initialDate, $final, $blockDays);

        return ['job' => $this->status($id)];
    }

    /** @return array<string, mixed> */
    public function stop(): array
    {
        $job = $this->repository->findActive();
        if ($job === null) {
            throw new RuntimeException('Não há sincronização contínua em andamento para interromper.');
        }
        $this->repository->update((int) $job['id'], ['estado' => 'STOP_REQUESTED']);

        return $this->status((int) $job['id']);
    }

    /** @return array<string, mixed> */
    public function resume(): array
    {
        $job = $this->repository->latest();
        // STOP_REQUESTED pode ficar pendente se o worker não estiver rodando no
        // momento do "Interromper". Nesse caso, retomar deve limpar o pedido de
        // parada e seguir do cursor (o worker passa a processar o job normalmente).
        if ($job === null || !in_array((string) $job['estado'], ['PAUSED', 'ERROR', 'STOP_REQUESTED'], true)) {
            throw new RuntimeException('Não há sincronização contínua pausada ou com erro para retomar.');
        }
        $this->repository->update((int) $job['id'], ['estado' => 'RUNNING', 'erro' => null]);

        return $this->status((int) $job['id']);
    }

    /** Agenda "reprocessar falhos" (executado pelo worker, fora do HTTP). */
    /** @return array<string, mixed> */
    public function requestRetryFailed(): array
    {
        $job = $this->repository->latest();
        if ($job === null) {
            throw new RuntimeException('Nenhuma sincronização contínua encontrada.');
        }
        $this->repository->update((int) $job['id'], ['retentar' => 1]);

        return $this->status((int) $job['id']);
    }

    /** @return array<string, mixed> */
    public function status(?int $id = null): array
    {
        $job = $id !== null ? $this->repository->findById($id) : $this->repository->latest();
        if ($job === null) {
            return ['id' => null, 'estado' => 'NONE', 'logs' => []];
        }

        $hydrated = $this->hydrate($job);
        $hydrated['logs'] = array_map(
            fn (array $row): array => [
                'id' => (int) $row['id'],
                'bloco_inicio' => $row['bloco_inicio'],
                'bloco_fim' => $row['bloco_fim'],
                'tabela' => $row['tabela'],
                'status' => $row['status'],
                'resumo' => $this->decode($row['resumo'] ?? null),
                'erro' => $row['erro'] ?? null,
                'duracao_s' => $row['duracao_s'] !== null ? (float) $row['duracao_s'] : null,
                'created_at' => $row['created_at'] ?? null,
            ],
            $this->repository->recentLogs((int) $job['id'], 50),
        );

        return $hydrated;
    }

    /**
     * Executa um ciclo do worker: reprocessa faltantes (se pedido) e varre os
     * blocos até concluir, pausar/parar ou por erro fatal.
     *
     * @return array<string, mixed>
     */
    public function runCycle(string $instance): array
    {
        // Só pega o lock quando há trabalho: o worker é disparado a cada minuto e, sem job, segurava o lock por ~1-2 s,
        // o que o ImportGuard anunciava como "outra sincronização em andamento" (falso alarme).
        if ($this->repository->findWorkable() === null) {
            return ['success' => true, 'message' => 'Nenhum job pendente.', 'job' => $this->status()];
        }

        if ($this->repository->acquireLock(self::LOCK) !== 1) {
            throw new RuntimeException('Já existe uma sincronização contínua em execução.');
        }

        // Releitura já com o lock (outro worker pode ter concluído o job entre a checagem e o lock).
        $job = $this->repository->findWorkable();
        if ($job === null) {
            $this->repository->releaseLock(self::LOCK);

            return ['success' => true, 'message' => 'Nenhum job pendente.', 'job' => $this->status()];
        }

        $id = (int) $job['id'];

        try {
            if ((int) ($job['retentar'] ?? 0) === 1) {
                $this->repository->update($id, ['retentar' => 0, 'heartbeat' => $this->now()]);
                $this->processFailed($id);
            }

            if (in_array((string) $job['estado'], ['QUEUED', 'RUNNING', 'STOP_REQUESTED'], true)) {
                $this->runBlocks($id, $job, $instance);
            }
        } catch (\Throwable $error) {
            // Erro inesperado fora do try por-tabela (ex.: falha de conexão ao
            // atualizar o cursor/bloco): marca como ERROR (retomável) e restaura
            // as rotinas, evitando job "preso" em RUNNING/STOP_REQUESTED.
            $this->repository->update($id, [
                'estado' => 'ERROR',
                'erro' => $error->getMessage(),
                'heartbeat' => $this->now(),
            ]);
            $this->restoreRoutines($id);
            throw $error;
        } finally {
            $this->repository->releaseLock(self::LOCK);
        }

        return ['success' => true, 'job' => $this->status($id)];
    }

    /** @param array<string, mixed> $job */
    private function runBlocks(int $id, array $job, string $instance): void
    {
        // Se há um pedido de parada pendente (STOP_REQUESTED), honra-o antes de
        // qualquer coisa: pausa o job e restaura as rotinas contínuas, sem iniciar
        // a varredura. (Antes isso era perdido porque o estado era sobrescrito
        // para RUNNING logo abaixo.)
        $current = $this->repository->findById($id);
        if ($current !== null && (string) $current['estado'] === 'STOP_REQUESTED') {
            $this->repository->update($id, ['estado' => 'PAUSED', 'heartbeat' => $this->now()]);
            $this->restoreRoutines($id);

            return;
        }

        // Pausa as rotinas contínuas na primeira execução (guarda o estado anterior).
        if ($this->decode($job['rotinas_pausadas'] ?? null) === null) {
            $snapshot = $this->repository->pauseRoutines();
            $this->repository->update($id, ['rotinas_pausadas' => $this->encode($snapshot)]);
        }

        $this->repository->update($id, [
            'estado' => 'RUNNING',
            'heartbeat' => $this->now(),
            'instancia' => $instance,
            'erro' => null,
        ]);

        $resumo = $this->decode($job['resumo'] ?? null) ?? [];
        $consecutiveErrorBlocks = 0;

        while (true) {
            $current = $this->repository->findById($id);
            if ($current === null) {
                return;
            }

            if ((string) $current['estado'] === 'STOP_REQUESTED') {
                $this->repository->update($id, ['estado' => 'PAUSED', 'heartbeat' => $this->now()]);
                $this->restoreRoutines($id);

                return;
            }

            $cursor = (string) $current['cursor_date'];
            $cap = min((string) $current['final_date'], $this->yesterday());

            if ($cursor > $cap) {
                $this->repository->update($id, [
                    'estado' => 'COMPLETED',
                    'heartbeat' => $this->now(),
                    'bloco_inicio' => null,
                    'bloco_fim' => null,
                    'tabela_atual' => null,
                ]);
                $this->restoreRoutines($id);

                return;
            }

            $blockEnd = min($this->addDays($cursor, (int) $current['block_days'] - 1), $cap);
            $this->repository->update($id, [
                'bloco_inicio' => $cursor,
                'bloco_fim' => $blockEnd,
                'heartbeat' => $this->now(),
            ]);

            $fatal = null;
            $blockHadError = false;

            foreach (self::TABLES as $table) {
                $this->repository->update($id, [
                    'tabela_atual' => $table,
                    'heartbeat' => $this->now(),
                    'progresso' => $this->encode([
                        'tabela' => $table,
                        'bloco_inicio' => $cursor,
                        'bloco_fim' => $blockEnd,
                        'status' => 'executando',
                    ]),
                ]);

                $startedAt = microtime(true);
                try {
                    $summary = $this->importTable($table, $cursor, $blockEnd);
                    $this->repository->addLog($id, $cursor, $blockEnd, $table, 'OK', $summary, null, round(microtime(true) - $startedAt, 2));
                    $resumo = $this->mergeSummary($resumo, $table, $summary);
                } catch (\Throwable $error) {
                    $this->repository->addLog($id, $cursor, $blockEnd, $table, 'ERRO', null, $error->getMessage(), round(microtime(true) - $startedAt, 2));
                    $blockHadError = true;
                    if ($this->isFatal($error)) {
                        $fatal = $error;
                        break;
                    }
                }
                $this->repository->update($id, ['resumo' => $this->encode($resumo)]);
            }

            if ($fatal !== null) {
                $this->repository->update($id, [
                    'estado' => 'ERROR',
                    'erro' => $fatal->getMessage(),
                    'heartbeat' => $this->now(),
                ]);
                $this->restoreRoutines($id);

                return;
            }

            $consecutiveErrorBlocks = $blockHadError ? $consecutiveErrorBlocks + 1 : 0;
            if ($consecutiveErrorBlocks >= self::MAX_CONSECUTIVE_ERROR_BLOCKS) {
                $this->repository->update($id, [
                    'estado' => 'ERROR',
                    'erro' => 'Muitos blocos consecutivos com erro — interrompido por segurança.',
                    'heartbeat' => $this->now(),
                ]);
                $this->restoreRoutines($id);

                return;
            }

            $this->repository->update($id, [
                'cursor_date' => $this->addDays($blockEnd, 1),
                'heartbeat' => $this->now(),
                'resumo' => $this->encode($resumo),
            ]);
        }
    }

    /** Reprocessa os registros com status ERRO no log do job (executado pelo worker). */
    private function processFailed(int $id): void
    {
        foreach ($this->repository->failedLogs($id) as $entry) {
            $startedAt = microtime(true);
            try {
                $summary = $this->importTable((string) $entry['tabela'], (string) $entry['bloco_inicio'], (string) $entry['bloco_fim']);
                $this->repository->updateLog((int) $entry['id'], [
                    'status' => 'OK',
                    'resumo' => $this->encode($summary),
                    'erro' => null,
                    'duracao_s' => round(microtime(true) - $startedAt, 2),
                ]);
            } catch (\Throwable $error) {
                $this->repository->updateLog((int) $entry['id'], [
                    'erro' => $error->getMessage(),
                    'duracao_s' => round(microtime(true) - $startedAt, 2),
                ]);
            }
            $this->repository->update($id, ['heartbeat' => $this->now()]);
        }
    }

    /** @return array<string, mixed> */
    private function importTable(string $table, string $initial, string $final): array
    {
        switch ($table) {
            case 'sales':
                return (new SalesImportService(
                    $this->env,
                    $this->connection,
                    new SalesClient($this->env, new OAuthClient($this->env, $this->env->required('FURK_SALES_SCOPE'))),
                    new SalesRepository($this->connection),
                ))->import($initial, $final);

            case 'creditos':
                return (new EligibleCreditsImportService(
                    $this->env,
                    $this->connection,
                    new EligibleCreditsClient($this->env, new OAuthClient($this->env)),
                    new EligibleCreditRepository($this->connection),
                ))->import($initial, $final);

            case 'refunds':
                return (new RefundEligibleImportService(
                    $this->env,
                    $this->connection,
                    new RefundEligibleClient($this->env),
                    new EligibleCreditsClient($this->env, new OAuthClient($this->env)),
                    new RefundEligibleRepository($this->connection),
                ))->import($initial, $final);

            case 'bilhetes':
                $scope = $this->env->required('FURK_DYNAMIC_STATUS_SCOPE');

                return (new DynamicStatusImportService(
                    $this->env,
                    $this->connection,
                    new DynamicStatusClient($this->env, new OAuthClient($this->env, $scope)),
                    new DynamicStatusRepository($this->connection),
                ))->import($initial, $final, 1);
        }

        throw new RuntimeException("Tabela não suportada na sincronização contínua: {$table}.");
    }

    private function restoreRoutines(int $id): void
    {
        $job = $this->repository->findById($id);
        $snapshot = $job !== null ? $this->decode($job['rotinas_pausadas'] ?? null) : null;
        if ($snapshot !== null) {
            $this->repository->restoreRoutines($snapshot);
            $this->repository->update($id, ['rotinas_pausadas' => null]);
        }
    }

    /** Considera FATAL a falha sistêmica (conexão/autenticação). */
    private function isFatal(\Throwable $error): bool
    {
        if ($error instanceof \PDOException) {
            return true;
        }

        return (bool) preg_match('/conect|connection|OAuth|token|autentic|HTTP 401|HTTP 403/i', $error->getMessage());
    }

    /**
     * @param array<string, mixed> $acc
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function mergeSummary(array $acc, string $table, array $summary): array
    {
        if (!isset($acc[$table]) || !is_array($acc[$table])) {
            $acc[$table] = [];
        }
        foreach ($summary as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $acc[$table][$key] = ($acc[$table][$key] ?? 0) + $value;
            }
        }

        return $acc;
    }

    /** @param array<string, mixed> $job @return array<string, mixed> */
    private function hydrate(array $job): array
    {
        $initial = (string) $job['initial_date'];
        $cursor = (string) $job['cursor_date'];
        $cap = min((string) $job['final_date'], $this->yesterday());

        return [
            'id' => (int) $job['id'],
            'initial_date' => $job['initial_date'],
            'final_date' => $job['final_date'],
            'cursor_date' => $job['cursor_date'],
            'cap_date' => $cap,
            'block_days' => (int) $job['block_days'],
            'bloco_inicio' => $job['bloco_inicio'] ?? null,
            'bloco_fim' => $job['bloco_fim'] ?? null,
            'tabela_atual' => $job['tabela_atual'] ?? null,
            'estado' => $job['estado'],
            'percent' => $this->percent($initial, $cursor, $cap),
            'progresso' => $this->decode($job['progresso'] ?? null),
            'resumo' => $this->decode($job['resumo'] ?? null),
            'erro' => $job['erro'] ?? null,
            'heartbeat' => $job['heartbeat'] ?? null,
            'instancia' => $job['instancia'] ?? null,
            'updated_at' => $job['updated_at'] ?? null,
            'created_at' => $job['created_at'] ?? null,
        ];
    }

    private function percent(string $initial, string $cursor, string $cap): int
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $initial);
        $capDate = DateTimeImmutable::createFromFormat('!Y-m-d', $cap);
        $cursorDate = DateTimeImmutable::createFromFormat('!Y-m-d', min($cursor, $cap));
        if ($start === false || $capDate === false || $cursorDate === false) {
            return 0;
        }
        $total = (int) $start->diff($capDate)->days + 1;
        if ($total <= 0) {
            return 0;
        }
        $done = (int) $start->diff($cursorDate)->days;

        return (int) min(100, max(0, round($done / $total * 100)));
    }

    private function validate(string $initialDate, string $finalDate, int $blockDays): void
    {
        $initial = DateTimeImmutable::createFromFormat('!Y-m-d', $initialDate);
        $final = DateTimeImmutable::createFromFormat('!Y-m-d', $finalDate);
        if ($initial === false || $final === false || $initial->format('Y-m-d') !== $initialDate || $final->format('Y-m-d') !== $finalDate) {
            throw new RuntimeException('Informe as datas no formato AAAA-MM-DD.');
        }
        if ($initial > $final) {
            throw new RuntimeException('A data inicial não pode ser posterior à data final.');
        }
        if ($initialDate < self::MIN_DATE) {
            throw new RuntimeException('Confira o ano da data inicial: use 4 dígitos, a partir de 01/01/2020.');
        }
        if ($blockDays < self::MIN_BLOCK_DAYS || $blockDays > self::MAX_BLOCK_DAYS) {
            throw new RuntimeException('O tamanho do bloco deve ser entre ' . self::MIN_BLOCK_DAYS . ' e ' . self::MAX_BLOCK_DAYS . ' dias.');
        }
    }

    private function yesterday(): string
    {
        return date('Y-m-d', strtotime('-1 day'));
    }

    private function addDays(string $date, int $days): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false) {
            throw new RuntimeException("Data inválida: {$date}.");
        }

        return $parsed->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** @return array<string, mixed>|null */
    private function decode(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
