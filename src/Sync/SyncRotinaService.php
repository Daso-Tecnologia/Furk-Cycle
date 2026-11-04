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
 * Sincronização Consolidada Contínua Recursiva — rotina contínua e automática
 * que importa as 4 tabelas de dados (Sales, Créditos, Reembolsos, Status
 * Dinâmicos) em um loop indefinido, deslizando a janela para o D-1 mais recente.
 *
 * Reutiliza os mesmos Services de importação (que já atualizam a bi_insight).
 *
 * Regras de negócio (16/10/2026):
 *   - A Furk não contempla o dia corrente; a leitura sempre termina em D-1.
 *   - data_base é SEMPRE a data atual (vedada a alteração) — apenas referência
 *     do primeiro disparo; a janela é recalculada a cada ciclo usando "hoje".
 *   - A frequência é em HORAS (1 a 5), convertida para minutos na pausa.
 *   - dias_recuo (1 a 5) define o tamanho da janela: [hoje - recuo, hoje - 1].
 */
final class SyncRotinaService
{
    private const MAX_RECUO = 5;
    private const MIN_FREQUENCIA_HORAS = 1;
    private const MAX_FREQUENCIA_HORAS = 5;

    /** Ordem de processamento das tabelas em cada ciclo. */
    private const TABLES = ['sales', 'creditos', 'refunds', 'bilhetes'];

    /** Espera, em segundos, antes de tentar de novo um ciclo que falhou. */
    private const RETRY_DELAY_S = 600;

    public function __construct(
        private readonly Env $env,
        private readonly PDO $connection,
        private readonly SyncRotinaRepository $jobRepository,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $job = $this->jobRepository->ensureJob();
        $running = $job['heartbeat'] !== null
            && $this->isRunningState((string) $job['estado'])
            && $this->isHeartbeatFresh($job['heartbeat'], (int) ($job['frequencia_horas'] ?? 0));

        return [
            'config' => [
                'data_base' => date('Y-m-d'),
                'frequencia_horas' => $job['frequencia_horas'] !== null ? (int) $job['frequencia_horas'] : null,
                'dias_recuo' => $job['dias_recuo'] !== null ? (int) $job['dias_recuo'] : null,
            ],
            'estado' => $job['estado'],
            'rodando' => $running,
            'heartbeat' => $job['heartbeat'],
            'proxima_execucao' => $job['proxima_execucao'],
            'ultimo_resumo' => $this->decodeJson($job['ultimo_resumo'] ?? null),
            'duracao_media_s' => $job['duracao_media_s'] !== null ? (float) $job['duracao_media_s'] : null,
            'instancia' => $job['instancia'],
            'tabela_atual' => $job['tabela_atual'] ?? null,
            'janela_inicial' => $job['janela_inicial'] ?? null,
            'janela_final' => $job['janela_final'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function configure(int $frequenciaHoras, int $diasRecuo): array
    {
        $this->validateConfig($frequenciaHoras, $diasRecuo);
        $job = $this->jobRepository->ensureJob();

        $this->jobRepository->update((int) $job['id'], [
            'data_base' => date('Y-m-d'),
            'frequencia_horas' => $frequenciaHoras,
            'dias_recuo' => $diasRecuo,
        ]);

        return $this->status();
    }

    /** @return array<string, mixed> */
    public function start(): array
    {
        $job = $this->jobRepository->ensureJob();
        if ($job['frequencia_horas'] === null || $job['dias_recuo'] === null) {
            throw new RuntimeException('Configure a rotina antes de iniciá-la.');
        }

        $this->jobRepository->update((int) $job['id'], [
            'data_base' => date('Y-m-d'),
            'estado' => 'RUNNING',
            'proxima_execucao' => date('Y-m-d H:i:s'),
            'heartbeat' => null,
            'instancia' => gethostname(),
        ]);

        return $this->status();
    }

    /** @return array<string, mixed> */
    public function stop(): array
    {
        $job = $this->jobRepository->ensureJob();
        $this->jobRepository->update((int) $job['id'], ['estado' => 'STOP_REQUESTED']);

        return $this->status();
    }

    /**
     * Executa um ciclo completo da rotina. Usado pelo worker (CLI/agendador).
     * Importa as 4 tabelas da janela deslizante e atualiza os indicadores.
     *
     * @return array<string, mixed>
     */
    public function runCycle(string $instance): array
    {
        $job = $this->jobRepository->ensureJob();
        if ($job['frequencia_horas'] === null || $job['dias_recuo'] === null) {
            throw new RuntimeException('Configure a rotina antes de executar um ciclo.');
        }

        $estado = (string) $job['estado'];
        if ($estado === 'STOP_REQUESTED') {
            // Interrupção pedida enquanto o worker não estava no meio do ciclo:
            // encerra a rotina de forma limpa, sem importar nada.
            $this->jobRepository->update((int) $job['id'], [
                'estado' => 'IDLE',
                'proxima_execucao' => null,
                'tabela_atual' => null,
            ]);

            return ['stopped' => true];
        }
        if ($estado !== 'RUNNING') {
            // IDLE/ERROR: o agendador pode continuar chamando o worker sem
            // gerar erro em log; simplesmente não há ciclo a executar.
            return ['waiting' => true, 'estado' => $estado];
        }

        if ($job['proxima_execucao'] !== null) {
            $proxima = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $job['proxima_execucao']);
            if ($proxima !== false && $proxima->getTimestamp() > time()) {
                return ['waiting' => true, 'proxima_execucao' => $job['proxima_execucao']];
            }
        }

        [$initial, $final] = $this->window((int) $job['dias_recuo']);

        $this->jobRepository->update((int) $job['id'], [
            'data_base' => date('Y-m-d'),
            'janela_inicial' => $initial,
            'janela_final' => $final,
            'heartbeat' => date('Y-m-d H:i:s'),
        ]);

        $startedAt = microtime(true);
        $summary = [];
        try {
            foreach (self::TABLES as $table) {
                $this->jobRepository->update((int) $job['id'], ['tabela_atual' => $table]);
                $summary[$table] = $this->importTable($table, $initial, $final);
            }
        } catch (\Throwable $error) {
            // Falha pontual: mantém a rotina RUNNING e agenda nova tentativa em alguns minutos
            // (o job não fica "preso" e, com a Furk fora do ar, não martela a API a cada minuto).
            $this->jobRepository->update((int) $job['id'], [
                'heartbeat' => date('Y-m-d H:i:s'),
                'proxima_execucao' => date('Y-m-d H:i:s', time() + self::RETRY_DELAY_S),
                'tabela_atual' => null,
            ]);
            throw $error;
        }
        $duration = round(microtime(true) - $startedAt, 2);

        // Releitura do estado: o usuário pode ter clicado em "Interromper"
        // durante o ciclo. A parada é honrada ao fim do ciclo atual.
        $current = $this->jobRepository->ensureJob();
        $stopRequested = (string) $current['estado'] === 'STOP_REQUESTED';

        if ($stopRequested) {
            $this->jobRepository->update((int) $job['id'], [
                'heartbeat' => date('Y-m-d H:i:s'),
                'proxima_execucao' => null,
                'ultimo_resumo' => $this->encodeJson($summary),
                'duracao_media_s' => $duration,
                'tabela_atual' => null,
                'estado' => 'IDLE',
                'instancia' => $instance,
            ]);

            return [
                'janela' => ['inicial' => $initial, 'final' => $final],
                'duracao_s' => $duration,
                'summary' => $summary,
                'stopped' => true,
            ];
        }

        $frequencia = (int) $job['frequencia_horas'];
        $proxima = date('Y-m-d H:i:s', time() + ($frequencia * 3600));

        $this->jobRepository->update((int) $job['id'], [
            'heartbeat' => date('Y-m-d H:i:s'),
            'proxima_execucao' => $proxima,
            'ultimo_resumo' => $this->encodeJson($summary),
            'duracao_media_s' => $duration,
            'tabela_atual' => null,
            'estado' => 'RUNNING',
            'instancia' => $instance,
        ]);

        return [
            'janela' => ['inicial' => $initial, 'final' => $final],
            'duracao_s' => $duration,
            'summary' => $summary,
        ];
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

        throw new RuntimeException("Tabela não suportada na rotina: {$table}.");
    }

    /**
     * Janela deslizante: sempre termina em D-1 (a Furk não contempla o dia
     * corrente) e tem o tamanho definido por `dias_recuo` (1 a 5 dias).
     *
     * @return array{0: string, 1: string}
     */
    private function window(int $diasRecuo): array
    {
        $today = new DateTimeImmutable('today');
        $final = $today->modify('-1 days');
        $initial = $final->modify('-' . ($diasRecuo - 1) . ' days');

        return [$initial->format('Y-m-d'), $final->format('Y-m-d')];
    }

    private function validateConfig(int $frequenciaHoras, int $diasRecuo): void
    {
        if ($frequenciaHoras < self::MIN_FREQUENCIA_HORAS || $frequenciaHoras > self::MAX_FREQUENCIA_HORAS) {
            throw new RuntimeException('A frequência deve estar entre ' . self::MIN_FREQUENCIA_HORAS . ' e ' . self::MAX_FREQUENCIA_HORAS . ' horas.');
        }
        if ($diasRecuo < 1 || $diasRecuo > self::MAX_RECUO) {
            throw new RuntimeException('O recuo deve ser entre 1 e ' . self::MAX_RECUO . ' dias.');
        }
    }

    private function isRunningState(string $estado): bool
    {
        return $estado === 'RUNNING';
    }

    private function isHeartbeatFresh(string $heartbeat, int $frequenciaHoras): bool
    {
        $frequencia = max($frequenciaHoras, self::MIN_FREQUENCIA_HORAS);
        $threshold = time() - ($frequencia * 2 * 3600);
        try {
            $heartbeatTime = (new DateTimeImmutable($heartbeat, new \DateTimeZone(date_default_timezone_get())))->getTimestamp();
        } catch (\Exception) {
            return false;
        }

        return $heartbeatTime >= $threshold;
    }

    /** @return array<string, mixed>|null */
    private function decodeJson(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
