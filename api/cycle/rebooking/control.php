<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Rebooking\RebookingControlRepository;

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
    $nota = trim((string) ($body['nota'] ?? ''));

    $environment = $env->optional('FURK_ENVIRONMENT', 'production') ?? 'production';
    $control = new RebookingControlRepository($env);
    $result = $control->page($environment, $page, $size, $search, $nota === '' ? null : $nota);

    JsonResponse::send([
        'success' => true,
        'environment' => $environment,
        'page' => $page,
        'size' => $size,
        'total' => (int) $result['total'],
        'rows' => $result['rows'],
    ]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível consultar a tabela de control.');
}