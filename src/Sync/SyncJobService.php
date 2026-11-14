<?php

declare(strict_types=1);

namespace FurkiCycle\Sync;

use Closure;
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
 * Sincronização Consolidada: importa Sales, Créditos, Reembolsos e Status Dinâmicos de um período.
 *
 * O período é processado em BLOCOS de poucos dias (padrão 5; `SYNC_JOB_BLOCK_DAYS`, de 1 a 10) e, em cada
 * bloco, uma tabela por vez. A cada passo concluído o ponto de retomada (cursor + passo) é gravado em
 * `progresso`: se o worker cair, o próximo retoma de onde parou (as importações são idempotentes) em vez
 * de recomeçar do zero — antes, um período longo reiniciava para sempre e nunca terminava.
 *
 * Interromper (somente ADMIN, ver api/cycle/sync/job.php): grava STOP_REQUESTED; o worker confere
 * entre os passos, encerra limpo e deixa o job como STOP_REQUESTED (inerte para o ImportGuard com o
 * lock livre). Retomadas seguidas no mesmo ponto passam a ERROR (limite de segurança).
 */
final class SyncJobService
{
    private const LOCK = 'furk_sync_job_routine';

    /** Ordem de processamento das tabelas em cada bloco. */
    private const TABLES = ['sales', 'creditos', 'refunds', 'bilhetes'];

    private const DEFAULT_BLOCK_DAYS = 5;
    private const MIN_BLOCK_DAYS = 1;
    private const MAX_BLOCK_DAYS = 10;
    /** Data inicial mínima aceita (ano digitado pela metade, ex.: 0002, geraria um período absurdo). */
    private const MIN_DATE = '2020-01-01';

    /** Retomadas seguidas, sem concluir nenhum passo, antes de desistir (evita laço infinito). */
    private const MAX_RESUMES = 3;

    /** @var Closure(string, string, string): array<string, mixed>|null importador alternativo (testes) */
    private ?Closure $importer;

    /** Quem iniciou o job em andamento (preservado em todo `progresso`). */
    private ?string $startedBy = null;

    public function __construct(
        private readonly Env $env,
        private readonly PDO $connection,
        private readonly SyncJobRepository $jobRepository,
        ?Closure $importer = null,
    ) {
        $this->importer = $importer;
    }

    /**
     * @param string|null $startedBy nome de quem iniciou (aparece no aviso das telas: "iniciada por X às HH:MM")
     * @return array<string, mixed>
     */
    public function start(string $initialDate, string $finalDate, ?string $startedBy = null): array
    {
        $this->validateRange($initialDate, $finalDate);
        $id = $this->jobRepository->create($initialDate, $finalDate);
        $this->jobRepository->update($id, [
            'progresso' => $this->encode([
                'status' => 'na_fila',
                'tabela' => null,
                'passo' => 0,
                'passos' => count(self::TABLES),
                'iniciado_por' => $startedBy !== null && $startedBy !== '' ? mb_substr($startedBy, 0, 80) : null,
            ]),
        ]);

        return ['job' => $this->status($id)];
    }

    /**
     * Pede a interrupção do job em andamento (QUEUED/RUNNING). O worker confere o pedido entre os passos.
     *
     * @return array<string, mixed>
     */
    public function stop(): array
    {
        $job = $this->jobRepository->findPending();
        if ($job === null) {
            throw new RuntimeException('Não há sincronização consolidada em andamento para interromper.');
        }
        $this->jobRepository->update((int) $job['id'], ['estado' => 'STOP_REQUESTED']);

        return $this->status((int) $job['id']);
    }

    /** @return array<string, mixed> */
    public function status(?int $id = null): array
    {
        $job = $id !== null ? $this->jobRepository->findById($id) : $this->jobRepository->latest();

        return $job === null
            ? ['id' => null, 'estado' => 'NONE']
            : $this->hydrate($job);
    }

    /**
     * Executa (ou retoma) um job pendente — usado pelo worker CLI.
     *
     * @return array<string, mixed>
     */
    public function runCycle(string $instance): array
    {
        // Só pega o lock quando há trabalho: o worker é disparado a cada minuto e, sem job, segurava o lock por ~1-2 s,
        // o que o ImportGuard anunciava como "outra sincronização em andamento" (falso alarme).
        if ($this->jobRepository->findPending() === null) {
            return ['success' => true, 'message' => 'Nenhum job pendente.', 'job' => $this->status()];
        }

        if ($this->jobRepository->acquireLock(self::LOCK) !== 1) {
            throw new RuntimeException('Já existe uma sincronização consolidada em execução.');
        }

        try {
            // Releitura já com o lock (outro worker pode ter concluído o job entre a checagem e o lock).
            $job = $this->jobRepository->findPending();
            if ($job === null) {
                return ['success' => true, 'message' => 'Nenhum job pendente.', 'job' => $this->status()];
            }

            $this->runJob($job, $instance);

            return ['success' => true, 'job' => $this->status((int) $job['id'])];
        } finally {
            $this->jobRepository->releaseLock(self::LOCK);
        }
    }

    /** @param array<string, mixed> $job */
    private function runJob(array $job, string $instance): void
    {
        $id = (int) $job['id'];
        $initial = (string) $job['initial_date'];
        $final = (string) $job['final_date'];

        $progress = $this->decode($job['progresso'] ?? null) ?? [];
        $resumo = $this->decode($job['resumo'] ?? null) ?? [];
        $blockDays = $this->clampBlockDays((int) ($progress['bloco_dias'] ?? $this->configuredBlockDays()));
        $this->startedBy = isset($progress['iniciado_por']) && is_string($progress['iniciado_por']) ? $progress['iniciado_por'] : null;

        $cursor = isset($progress['cursor']) && is_string($progress['cursor']) && $progress['cursor'] >= $initial
            ? $progress['cursor']
            : $initial;
        $passo = max(0, min(count(self::TABLES), (int) ($progress['passo'] ?? 0)));

        // Job em RUNNING sem worker vivo = o worker anterior caiu: conta uma retomada.
        $resumes = (int) ($progress['retomadas'] ?? 0);
        if ((string) $job['estado'] === 'RUNNING') {
            $resumes++;
            if ($resumes > self::MAX_RESUMES) {
                $this->jobRepository->update($id, [
                    'estado' => 'ERROR',
                    'heartbeat' => $this->now(),
                    'erro' => 'O worker caiu ' . self::MAX_RESUMES . ' vezes seguidas no mesmo ponto (bloco de '
                        . $cursor . ', tabela ' . (self::TABLES[min($passo, count(self::TABLES) - 1)]) . '). Interrompido por segurança; verifique o log do worker.',
                ]);

                return;
            }
        }

        $this->jobRepository->update($id, [
            'estado' => 'RUNNING',
            'heartbeat' => $this->now(),
            'instancia' => $instance,
            'erro' => null,
            'progresso' => $this->encode($this->progressPayload($cursor, $passo, $blockDays, $resumes, 'iniciando')),
        ]);

        while ($cursor <= $final) {
            $blockEnd = min($this->addDays($cursor, $blockDays - 1), $final);

            for (; $passo < count(self::TABLES); $passo++) {
                if ($this->stopRequested($id)) {
                    $this->jobRepository->update($id, [
                        'heartbeat' => $this->now(),
                        'progresso' => $this->encode($this->progressPayload($cursor, $passo, $blockDays, 0, 'interrompido')),
                        'resumo' => $this->encode($resumo),
                    ]);

                    return;
                }

                $table = self::TABLES[$passo];
                $this->jobRepository->update($id, [
                    'heartbeat' => $this->now(),
                    'progresso' => $this->encode($this->progressPayload($cursor, $passo, $blockDays, $resumes, 'executando', $blockEnd)),
                ]);

                try {
                    $summary = $this->importTable($table, $cursor, $blockEnd);
                } catch (\Throwable $error) {
                    $this->jobRepository->update($id, [
                        'estado' => 'ERROR',
                        'heartbeat' => $this->now(),
                        'erro' => $error->getMessage(),
                        'resumo' => $this->encode($resumo),
                    ]);

                    throw $error;
                }

                $resumo = $this->mergeSummary($resumo, $table, $summary);
                $resumes = 0;
                $this->jobRepository->update($id, [
                    'heartbeat' => $this->now(),
                    'resumo' => $this->encode($resumo),
                    'progresso' => $this->encode($this->progressPayload($cursor, $passo + 1, $blockDays, 0, 'executando', $blockEnd)),
                ]);
            }

            $cursor = $this->addDays($blockEnd, 1);
            $passo = 0;
        }

        $this->jobRepository->update($id, [
            'estado' => 'COMPLETED',
            'heartbeat' => $this->now(),
            'resumo' => $this->encode($resumo),
            'progresso' => $this->encode(array_merge(
                $this->progressPayload($final, 0, $blockDays, 0, 'concluido'),
                // Ao concluir, o "bloco" mostrado é o período inteiro (e não um bloco além do fim).
                ['bloco_inicio' => $initial, 'bloco_fim' => $final],
            )),
        ]);
    }

    private function stopRequested(int $id): bool
    {
        $current = $this->jobRepository->findById($id);

        return $current !== null && (string) $current['estado'] === 'STOP_REQUESTED';
    }

    /** @return array<string, mixed> */
    private function progressPayload(string $cursor, int $passo, int $blockDays, int $resumes, string $status, ?string $blockEnd = null): array
    {
        $table = self::TABLES[min($passo, count(self::TABLES) - 1)];

        return [
            'tabela' => $status === 'concluido' ? null : $table,
            'status' => $status,
            'cursor' => $cursor,
            'passo' => $passo,
            'passos' => count(self::TABLES),
            'bloco_inicio' => $cursor,
            'bloco_fim' => $blockEnd ?? $this->addDays($cursor, $blockDays - 1),
            'bloco_dias' => $blockDays,
            'retomadas' => $resumes,
            'iniciado_por' => $this->startedBy,
        ];
    }

    /** @return array<string, mixed> */
    private function importTable(string $table, string $initial, string $final): array
    {
        if ($this->importer !== null) {
            return ($this->importer)($table, $initial, $final);
        }

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
                return (new DynamicStatusImportService(
                    $this->env,
                    $this->connection,
                    new DynamicStatusClient($this->env, new OAuthClient($this->env, $this->env->required('FURK_DYNAMIC_STATUS_SCOPE'))),
                    new DynamicStatusRepository($this->connection),
                ))->import($initial, $final, 1);
        }

        throw new RuntimeException("Tabela não suportada na sincronização consolidada: {$table}.");
    }

    /**
     * Soma os contadores numéricos de cada tabela (os blocos são importados separadamente).
     *
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
        $progress = $this->decode($job['progresso'] ?? null);

        return [
            'id' => (int) $job['id'],
            'initial_date' => $job['initial_date'] ?? null,
            'final_date' => $job['final_date'] ?? null,
            'estado' => $job['estado'],
            'progresso' => $progress,
            'percent' => $this->percent((string) ($job['initial_date'] ?? ''), (string) ($job['final_date'] ?? ''), (string) $job['estado'], $progress),
            'resumo' => $this->decode($job['resumo'] ?? null),
            'erro' => $job['erro'] ?? null,
            'heartbeat' => $job['heartbeat'] ?? null,
            'instancia' => $job['instancia'] ?? null,
            'updated_at' => $job['updated_at'] ?? null,
            'created_at' => $job['created_at'] ?? null,
        ];
    }

    /** @param array<string, mixed>|null $progress */
    private function percent(string $initial, string $final, string $estado, ?array $progress): int
    {
        if ($estado === 'COMPLETED') {
            return 100;
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $initial);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $final);
        $cursorText = is_array($progress) && isset($progress['cursor']) ? (string) $progress['cursor'] : $initial;
        $cursor = DateTimeImmutable::createFromFormat('!Y-m-d', $cursorText);
        if ($start === false || $end === false || $cursor === false) {
            return 0;
        }
        $total = (int) $start->diff($end)->days + 1;
        if ($total <= 0) {
            return 0;
        }
        $blockDays = is_array($progress) ? (int) ($progress['bloco_dias'] ?? 1) : 1;
        $fraction = is_array($progress) ? ((int) ($progress['passo'] ?? 0)) / count(self::TABLES) : 0.0;
        $done = (int) $start->diff($cursor)->days + $fraction * $blockDays;

        return (int) min(99, max(0, floor($done / $total * 100)));
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

    private function configuredBlockDays(): int
    {
        return $this->env->int('SYNC_JOB_BLOCK_DAYS', self::DEFAULT_BLOCK_DAYS);
    }

    private function clampBlockDays(int $days): int
    {
        return max(self::MIN_BLOCK_DAYS, min(self::MAX_BLOCK_DAYS, $days));
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

    private function validateRange(string $initialDate, string $finalDate): void
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
    }
}
