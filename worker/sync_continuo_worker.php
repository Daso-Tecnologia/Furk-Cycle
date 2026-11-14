<?php

declare(strict_types=1);

// Workers sao scripts de linha de comando: nunca devem executar via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/*
 * Furk Cycle — Worker da Sincronização Consolidada Contínua
 *
 * Varredura de períodos longos em blocos (com ponto de retomada/cursor).
 * Processa o job enquanto ele estiver QUEUED/RUNNING, até concluir, pausar/parar
 * ou por erro fatal. Deve ser disparado pelo Agendador de Tarefas do Windows
 * (ou cron). Só um worker processa por vez (GET_LOCK interno).
 *
 * Uso:
 *   php worker/sync_continuo_worker.php
 *
 * Saída:
 *   JSON com o resultado do ciclo (ou erro).
 */

use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Support\WorkerLog;
use FurkiCycle\Sync\SyncContinuoRepository;
use FurkiCycle\Sync\SyncContinuoService;

require dirname(__DIR__) . '/vendor/autoload.php';

// Sob o Agendador não há console: falhas e erros fatais ficam em furk_cycle_worker.log (pasta temporária).
WorkerLog::registerShutdown('sync_continuo_worker');

set_time_limit(0);

function out(array $data): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

function fail(array $data): never
{
    $message = (string) ($data['message'] ?? 'falha');
    // Outro worker em execução é o caminho normal: não é falha.
    if (!str_contains($message, 'em execução')) {
        WorkerLog::write('sync_continuo_worker', $message);
    }
    fwrite(STDERR, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

try {
    $env = new Env();
    $connection = MySqlConnection::create($env);
    $repository = new SyncContinuoRepository($connection);
    $service = new SyncContinuoService($env, $connection, $repository);

    $instance = gethostname() . ':' . getmypid();
    $result = $service->runCycle($instance);

    out(['success' => true, 'instance' => $instance, 'result' => $result]);
} catch (\Throwable $error) {
    fail(['success' => false, 'message' => $error->getMessage()]);
}
