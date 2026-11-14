<?php

declare(strict_types=1);

// Workers sao scripts de linha de comando: nunca devem executar via HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

/*
 * Furki Cycle — Preenchimento dos GAPS (períodos faltantes)
 *
 * Roda via CLI (fora do HTTP), sem limite de tempo do servidor. Importa os
 * períodos faltantes das 4 tabelas de dados (Sales, Bilhetes/Status, Créditos,
 * Refunds) e imprime o resumo de cada bloco.
 *
 * USO:
 *   php worker/preencher_gaps.php
 *
 * IMPORTANTE:
 *   - Interrompa as rotinas contínuas (Sales e Status Dinâmicos) antes de rodar.
 *   - Execute na raiz do projeto (onde está o .env e o vendor/autoload.php).
 */

use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Furk\OAuthClient;
use FurkiCycle\Furk\SalesClient;
use FurkiCycle\Sales\SalesRepository;
use FurkiCycle\Sales\SalesImportService;
use FurkiCycle\Furk\EligibleCreditsClient;
use FurkiCycle\Credits\EligibleCreditRepository;
use FurkiCycle\Credits\EligibleCreditsImportService;
use FurkiCycle\Furk\RefundEligibleClient;
use FurkiCycle\Refunds\RefundEligibleRepository;
use FurkiCycle\Refunds\RefundEligibleImportService;
use FurkiCycle\Furk\DynamicStatusClient;
use FurkiCycle\DynamicStatus\DynamicStatusRepository;
use FurkiCycle\DynamicStatus\DynamicStatusImportService;

require dirname(__DIR__) . '/vendor/autoload.php';

set_time_limit(0);

// ---------------------------------------------------------------------------
// Períodos faltantes (blocos de até 10 dias) — gerados a partir dos gaps
// levantados em 14/10/2026.
// ---------------------------------------------------------------------------

/** @var array<int, array{0:string,1:string}> */
$sales = [
    ['2026-01-06', '2026-01-10'],
    ['2026-01-14', '2026-01-23'],
    ['2026-01-24', '2026-01-31'],
    ['2026-02-04', '2026-02-13'],
    ['2026-02-14', '2026-02-23'],
    ['2026-02-24', '2026-02-28'],
    ['2026-03-01', '2026-03-10'],
    ['2026-03-11', '2026-03-20'],
    ['2026-03-21', '2026-03-30'],
    ['2026-03-31', '2026-04-09'],
    ['2026-04-10', '2026-04-19'],
    ['2026-04-20', '2026-04-29'],
    ['2026-04-30', '2026-05-09'],
    ['2026-05-10', '2026-05-19'],
    ['2026-05-20', '2026-05-29'],
    ['2026-05-30', '2026-06-08'],
    ['2026-06-09', '2026-06-18'],
    ['2026-06-19', '2026-06-28'],
    ['2026-06-29', '2026-07-08'],
    ['2026-07-09', '2026-07-18'],
    ['2026-07-19', '2026-07-28'],
    ['2026-07-29', '2026-07-31'],
    ['2026-09-10', '2026-09-19'],
    ['2026-09-20', '2026-09-29'],
    ['2026-09-30', '2026-09-30'],
    ['2026-10-01', '2026-10-07'],
];

/** @var array<int, array{0:string,1:string}> */
$bilhetes = [
    ['2026-09-16', '2026-09-19'],
    ['2026-09-21', '2026-09-27'],
    ['2026-09-30', '2026-10-01'],
];

/** @var array<int, array{0:string,1:string}> */
$creditos = [
    ['2026-01-01', '2026-01-01'],
    ['2026-03-21', '2026-03-21'],
    ['2026-05-30', '2026-05-31'],
    ['2026-09-26', '2026-10-02'],
    ['2026-10-04', '2026-10-04'],
    ['2026-10-06', '2026-10-08'],
    ['2026-10-10', '2026-10-10'],
];

/** @var array<int, array{0:string,1:string}> */
$refunds = [
    ['2026-01-01', '2026-01-01'],
    ['2026-03-21', '2026-03-21'],
    ['2026-05-30', '2026-05-31'],
    ['2026-09-12', '2026-09-12'],
    ['2026-09-16', '2026-09-18'],
    ['2026-09-22', '2026-10-01'],
    ['2026-10-02', '2026-10-02'],
    ['2026-10-04', '2026-10-04'],
    ['2026-10-06', '2026-10-13'],
];

// ---------------------------------------------------------------------------

function out(string $message): void
{
    echo $message . PHP_EOL;
}

/** @param callable():array<string,mixed> $fn */
function runPeriod(string $label, callable $fn): void
{
    out('>> ' . $label);
    try {
        $summary = $fn();
        out('   OK  ' . json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (\Throwable $error) {
        out('   ERRO ' . $error->getMessage());
    }
}

$env = new Env();
$connection = MySqlConnection::create($env);

out('=== Preenchendo GAPS (Sales) ===');
$salesClient = new SalesClient($env, new OAuthClient($env, $env->required('FURK_SALES_SCOPE')));
$salesRepo = new SalesRepository($connection);
$salesService = new SalesImportService($env, $connection, $salesClient, $salesRepo);
foreach ($sales as [$ini, $fim]) {
    runPeriod("SALES {$ini} -> {$fim}", static fn () => $salesService->import($ini, $fim));
}

out('=== Preenchendo GAPS (Bilhetes / Status Dinâmicos) ===');
$scope = $env->required('FURK_DYNAMIC_STATUS_SCOPE');
$dsClient = new DynamicStatusClient($env, new OAuthClient($env, $scope));
$dsRepo = new DynamicStatusRepository($connection);
$dsService = new DynamicStatusImportService($env, $connection, $dsClient, $dsRepo);
foreach ($bilhetes as [$ini, $fim]) {
    runPeriod("BILHETES {$ini} -> {$fim}", static fn () => $dsService->import($ini, $fim, 1));
}

out('=== Preenchendo GAPS (Créditos) ===');
$creditsClient = new EligibleCreditsClient($env, new OAuthClient($env));
$creditsRepo = new EligibleCreditRepository($connection);
$creditsService = new EligibleCreditsImportService($env, $connection, $creditsClient, $creditsRepo);
foreach ($creditos as [$ini, $fim]) {
    runPeriod("CREDITOS {$ini} -> {$fim}", static fn () => $creditsService->import($ini, $fim));
}

out('=== Preenchendo GAPS (Refunds) ===');
$refundClient = new RefundEligibleClient($env);
$creditClient = new EligibleCreditsClient($env, new OAuthClient($env));
$refundRepo = new RefundEligibleRepository($connection);
$refundService = new RefundEligibleImportService($env, $connection, $refundClient, $creditClient, $refundRepo);
foreach ($refunds as [$ini, $fim]) {
    runPeriod("REFUNDS {$ini} -> {$fim}", static fn () => $refundService->import($ini, $fim));
}

out('=== FIM ===');
