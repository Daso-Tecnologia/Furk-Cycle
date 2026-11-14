<?php

declare(strict_types=1);

// Workers sao scripts de linha de comando: nunca devem executar via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/*
 * Furk Cycle — Worker da Sales Rotina Contínua
 *
 * Executa um ciclo único da rotina e encerra. Deve ser disparado pelo
 * Agendador de Tarefas do Windows (ou cron) no intervalo desejado
 * (ex.: a cada 1 minuto). O controle de frequência real está no banco
 * (proxima_execucao / heartbeat), e o GET_LOCK garante exclusividade:
 * só uma instância processa por vez, mesmo se o agendador disparar duas vezes.
 *
 * Uso:
 *   php worker/sales_worker.php
 *
 * Saída:
 *   JSON com o resumo do ciclo (ou erro).
 */

use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Sales\SalesJobRepository;
use FurkiCycle\Sales\SalesJobService;
use FurkiCycle\Support\WorkerLog;

require dirname(__DIR__) . '/vendor/autoload.php';

// Sob o Agendador não há console: falhas e erros fatais ficam em furk_cycle_worker.log (pasta temporária).
WorkerLog::registerShutdown('sales_worker');

const LOCK_NAME = 'furk_sales_routine';

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
        WorkerLog::write('sales_worker', $message);
    }
    fwrite(STDERR, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

try {
    $env = new Env();
    $connection = MySqlConnection::create($env);
    $jobRepository = new SalesJobRepository($connection);
    $service = new SalesJobService($env, $connection, $jobRepository);

    // Só pega o lock quando há trabalho. Com a rotina parada, o worker (disparado a cada minuto) segurava o lock por
    // ~1-2 s e o ImportGuard anunciava "outra sincronização em andamento" (falso alarme) para quem clicasse nessa janela.
    $peek = $jobRepository->ensureJob();
    if ((string) $peek['estado'] !== 'RUNNING') {
        out(['success' => true, 'skipped' => true, 'message' => 'Rotina parada; nada a fazer.']);
    }

    $job = $jobRepository->acquireLock(LOCK_NAME);
    $instance = gethostname() . ':' . getmypid();

    try {
        $cycle = $service->runCycle($instance);
        $jobRepository->releaseLock(LOCK_NAME);
        out(['success' => true, 'instance' => $instance, 'cycle' => $cycle]);
    } catch (\Throwable $error) {
        $jobRepository->releaseLock(LOCK_NAME);
        fail(['success' => false, 'stage' => 'cycle', 'message' => $error->getMessage()]);
    }
} catch (\Throwable $error) {
    fail(['success' => false, 'stage' => 'bootstrap', 'message' => $error->getMessage()]);
}
