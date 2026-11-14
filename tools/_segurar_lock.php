<?php
declare(strict_types=1);

/*
 * Auxiliar do teste de "queda do PHP": adquire o lock da leitura manual e FICA PARADO
 * (sem liberar) até ser encerrado à força pelo processo de teste. Não rodar manualmente.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/_teste_banco.php';

use FurkiCycle\Sync\ImportGuard;

[$env] = carregarEnvTeste($root, $argv[1] ?? null);
$guard = new ImportGuard($env);
$guard->beginManualLoad(['role' => 'ADMIN']);
echo "LOCKED\n";
fflush(STDOUT);
sleep(120);
