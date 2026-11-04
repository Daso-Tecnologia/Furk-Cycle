<?php

declare(strict_types=1);

namespace FurkiCycle\Sales;

use DateTimeImmutable;
use DateTimeZone;
use FurkiCycle\Config\Env;
use FurkiCycle\Furk\OAuthClient;
use FurkiCycle\Furk\SalesClient;
use PDO;
use RuntimeException;

final class SalesJobService
{
    private const MAX_RECUO = 5;
    private const MIN_FREQUENCIA = 30;

    public function __construct(
        private readonly Env $env,
        private readonly PDO $connection,
        private readonly SalesJobRepository $jobRepository,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $job = $this->jobRepository->ensureJob();
        $running = $job['heartbeat'] !== null
            && $this->isRunningState((string) $job['estado'])
            && $this->isHeartbeatFresh($job['heartbeat'], (int) ($job['frequencia_min'] ?? 0));

        return [
            'config' => [
                'data_base' => $job['data_base'] ?? null,
                'frequencia_min' => $job['frequencia_min'] !== null ? (int) $job['frequencia_min'] : null,
                'dias_recuo' => $job['dias_recuo'] !== null ? (int) $job['dias_recuo'] : null,
                'dias_final' => $job['dias_final'] !== null ? (int) $job['dias_final'] : 1,
            ],
            'estado' => $job['estado'],
            'rodando' => $running,
            'heartbeat' => $job['heartbeat'],
            'proxima_execucao' => $job['proxima_execucao'],
            'ultimo_resumo' => $this->decodeJson($job['ultimo_resumo'] ?? null),
            'duracao_media_s' => $job['duracao_media_s'] !== null ? (float) $job['duracao_media_s'] : null,
            'instancia' => $job['instancia'],
        ];
    }

    /** @return array<string, mixed> */
    public function configure(string $dataBase, int $frequenciaMin, int $diasRecuo, int $diasFinal = 1): array
    {
        $this->validateConfig($dataBase, $frequenciaMin, $diasRecuo, $diasFinal);
        $job = $this->jobRepository->ensureJob();

        $this->jobRepository->update((int) $job['id'], [
            'data_base' => $dataBase,
            'frequencia_min' => $frequenciaMin,
            'dias_recuo' => $diasRecuo,
            'dias_final' => $diasFinal,
        ]);

        return $this->status();
    }

    /** @return array<string, mixed> */
    public function start(): array
    {
        $job = $this->jobRepository->ensureJob();
        if ($job['data_base'] === null || $job['frequencia_min'] === null || $job['dias_recuo'] === null) {
            throw new RuntimeException('Configure a rotina antes de iniciá-la.');
        }

        $this->jobRepository->update((int) $job['id'], [
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
     * Executa um ciclo completo. Usado pelo worker (CLI/agendador).
     * Não dispara por HTTP síncrono; a interface apenas agenda.
     *
     * @return array<string, mixed>
     */
    public function runCycle(string $instance): array
    {
        $job = $this->jobRepository->ensureJob();
        if ($job['data_base'] === null || $job['frequencia_min'] === null || $job['dias_recuo'] === null) {
            throw new RuntimeException('Configure a rotina antes de executar um ciclo.');
        }
        if ((string) $job['estado'] !== 'RUNNING') {
            throw new RuntimeException('A rotina não está em execução.');
        }

        if ($job['proxima_execucao'] !== null) {
            $proxima = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $job['proxima_execucao']);
            if ($proxima !== false && $proxima->getTimestamp() > time()) {
                return ['waiting' => true, 'proxima_execucao' => $job['proxima_execucao']];
            }
        }

        [$initial, $final] = $this->window((int) $job['dias_recuo'], (int) ($job['dias_final'] ?? 1));

        $scope = $this->env->required('FURK_SALES_SCOPE');
        $oauth = new OAuthClient($this->env, $scope);
        $client = new SalesClient($this->env, $oauth);
        $repository = new SalesRepository($this->connection);
        $service = new SalesImportService($this->env, $this->connection, $client, $repository);

        $startedAt = microtime(true);
        $summary = $service->import($initial, $final);
        $duration = round(microtime(true) - $startedAt, 2);

        $frequencia = (int) $job['frequencia_min'];
        $proxima = date('Y-m-d H:i:s', time() + ($frequencia * 60));

        $this->jobRepository->update((int) $job['id'], [
            'heartbeat' => date('Y-m-d H:i:s'),
            'proxima_execucao' => $proxima,
            'ultimo_resumo' => $this->encodeJson($summary),
            'duracao_media_s' => $duration,
            'estado' => 'RUNNING',
            'instancia' => $instance,
        ]);

        return [
            'janela' => ['inicial' => $initial, 'final' => $final],
            'duracao_s' => $duration,
            'summary' => $summary,
        ];
    }

    /** @return array{0: string, 1: string} */
    private function window(int $diasRecuo, int $diasFinal): array
    {
        $today = new DateTimeImmutable('today');
        $final = $today->modify('-' . $diasFinal . ' days');
        $initial = $final->modify('-' . ($diasRecuo - 1) . ' days');
        return [$initial->format('Y-m-d'), $final->format('Y-m-d')];
    }

    private function validateConfig(string $dataBase, int $frequenciaMin, int $diasRecuo, int $diasFinal): void
    {
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', $dataBase);
        if ($base === false || $base->format('Y-m-d') !== $dataBase) {
            throw new RuntimeException('Informe a data base no formato AAAA-MM-DD.');
        }
        if ($frequenciaMin < self::MIN_FREQUENCIA) {
            throw new RuntimeException('A frequência mínima é de ' . self::MIN_FREQUENCIA . ' minuto.');
        }
        if ($diasRecuo < 1 || $diasRecuo > self::MAX_RECUO) {
            throw new RuntimeException('O recuo deve ser entre 1 e ' . self::MAX_RECUO . ' dias.');
        }
        if ($diasFinal < 1 || $diasFinal > self::MAX_RECUO) {
            throw new RuntimeException('A janela deve terminar entre D-1 e D-' . self::MAX_RECUO . ' (a partir da data corrente).');
        }
    }

    private function isRunningState(string $estado): bool
    {
        return $estado === 'RUNNING';
    }

    private function isHeartbeatFresh(string $heartbeat, int $frequenciaMin): bool
    {
        $frequencia = max($frequenciaMin, self::MIN_FREQUENCIA);
        $threshold = time() - ($frequencia * 2 * 60);
        try {
            $heartbeatTime = (new DateTimeImmutable($heartbeat, new DateTimeZone(date_default_timezone_get())))->getTimestamp();
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
