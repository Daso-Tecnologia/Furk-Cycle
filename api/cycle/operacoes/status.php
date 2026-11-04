<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Sync\ImportGuard;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    JsonResponse::send(['success' => false, 'message' => 'Método não permitido.'], 405);
}

try {
    $env = new Env();
    $claims = (new JwtGuard())->requireAuthenticatedUser($env);

    // Somente leitura: alimenta a faixa de aviso das telas de carga ("a Recursiva está habilitada").
    JsonResponse::send(['success' => true, 'operacoes' => (new ImportGuard($env))->status($claims)]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível consultar o estado das sincronizações.');
}
