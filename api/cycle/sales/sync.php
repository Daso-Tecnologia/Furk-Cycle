<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Furk\OAuthClient;
use FurkiCycle\Furk\SalesClient;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Sales\SalesImportService;
use FurkiCycle\Sales\SalesRepository;
use FurkiCycle\Sync\ImportGuard;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    JsonResponse::send(['success' => false, 'message' => 'Método não permitido.'], 405);
}

// Importação pode demorar (MySQL remoto + paginação): não deixar o servidor cortar.
set_time_limit(0);
ignore_user_abort(true);

try {
    $env = new Env();
    $claims = (new JwtGuard())->requireAuthenticatedUser($env);
    (new JwtGuard())->requireRole($claims, ['ADMIN', 'SUPERVISOR']);

    // Carga manual: bloqueada com a Recursiva habilitada ou outra carga em andamento (409).
    $importGuard = new ImportGuard($env);
    $importGuard->beginManualLoad($claims);

    $body = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new RuntimeException('O corpo da requisição deve ser um objeto JSON.');
    }

    $initialIssueDate = $body['initialIssueDate'] ?? null;
    $finalIssueDate = $body['finalIssueDate'] ?? null;
    if (!is_string($initialIssueDate) || !is_string($finalIssueDate)) {
        throw new RuntimeException('Informe a data inicial e a data final.');
    }

    // Leitura manual cabe numa requisição: períodos longos vão para a Consolidada (400 com a orientação).
    $importGuard->assertPeriodoManual($initialIssueDate, $finalIssueDate);

    $connection = MySqlConnection::create($env);
    $client = new SalesClient($env, new OAuthClient($env, $env->required('FURK_SALES_SCOPE')));
    $repository = new SalesRepository($connection);
    $service = new SalesImportService($env, $connection, $client, $repository);

    // Registro da leitura (quem/onde/andamento) + parada pelo ADMIN + orçamento de tempo (para limpo antes do limite de 60 s da tela).
    $run = $importGuard->startManualRun('sales', $claims, $initialIssueDate, $finalIssueDate);
    $service->setCheckpoint(static fn (array $info): ?string => $run->checkpoint($info));
    try {
        $summary = $service->import($initialIssueDate, $finalIssueDate);
        $run->finish(($summary['parcial'] ?? false) ? 'PARTIAL' : 'DONE', $summary);
    } catch (\Throwable $error) {
        $run->finish('ERROR', ['erro' => $error->getMessage()]);
        throw $error;
    }

    JsonResponse::send(['success' => true, 'summary' => $summary]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível concluir a sincronização. Consulte os logs do servidor.');
}
