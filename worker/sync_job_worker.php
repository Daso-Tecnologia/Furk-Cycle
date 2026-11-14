<?php

declare(strict_types=1);

// Workers sao scripts de linha de comando: nunca devem executar via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/*
 * Furk Cycle — Worker da Sincronização Consolidada
 *
 * Executa um job pendente de sincronização (todas as tabelas de dados) e encerra.
 * Deve ser disparado pelo Agendador de Tarefas do Windows (ou cron) — o job só
 * processa quando existe um com estado QUEUED/RUNNING.
 *
 * Uso:
 *   php worker/sync_job_worker.php
 *
 * Saída:
 *   JSON com o resultado do ciclo (ou erro).
 */

use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Support\WorkerLog;
use FurkiCycle\Sync\SyncJobRepository;
use FurkiCycle\Sync\SyncJobService;

require dirname(__DIR__) . '/vendor/autoload.php';

set_time_limit(0);

// Sob o Agendador não há console: erros fatais do PHP ficam registrados em furk_cycle_worker.log (pasta temporária).
WorkerLog::registerShutdown('sync_job_worker');

function out(array $data): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

function fail(array $data): never
{
    $message = (string) ($data['message'] ?? 'falha');
    // "Já existe ... em execução" é o caminho normal quando outro worker está processando: não é falha.
    if (!str_contains($message, 'em execução')) {
        WorkerLog::write('sync_job_worker', $message);
    }
    fwrite(STDERR, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

try {
    $env = new Env();
    $connection = MySqlConnection::create($env);
    $jobRepository = new SyncJobRepository($connection);
    $service = new SyncJobService($env, $connection, $jobRepository);

    $instance = gethostname() . ':' . getmypid();
    $result = $service->runCycle($instance);

    out(['success' => true, 'instance' => $instance, 'result' => $result]);
} catch (\Throwable $error) {
    fail(['success' => false, 'message' => $error->getMessage()]);
}
