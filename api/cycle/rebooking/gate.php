<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Rebooking\RebookingGateRepository;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    JsonResponse::send(['success' => false, 'message' => 'Método não permitido.'], 405);
}

try {
    $env = new Env();
    $claims = (new JwtGuard())->requireAuthenticatedUser($env);
    (new JwtGuard())->requireRole($claims, ['ADMIN', 'SUPERVISOR']);

    $body = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new RuntimeException('O corpo da requisição deve ser um objeto JSON.');
    }

    $page = max(1, (int) ($body['page'] ?? 1));
    $size = max(1, min(500, (int) ($body['size'] ?? 50)));
    $search = trim((string) ($body['search'] ?? ''));

    $gate = new RebookingGateRepository($env);
    $result = $gate->gatePage($page, $size, $search);

    JsonResponse::send([
        'success' => true,
        'page' => $page,
        'size' => $size,
        'total' => (int) $result['total'],
        'rows' => $result['rows'],
    ]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível consultar o Gate. Verifique a VPN e tente novamente.');
}
