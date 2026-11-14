<?php

declare(strict_types=1);

use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Http\JsonResponse;
use FurkiCycle\Users\UserRepository;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    JsonResponse::send(['success' => false, 'message' => 'Método não permitido.'], 405);
}

try {
    $env = new Env();
    $claims = (new JwtGuard())->requireAuthenticatedUser($env);
    (new JwtGuard())->requireRole($claims, ['ADMIN', 'SUPERVISOR']);

    $repository = new UserRepository($env);
    $users = $repository->all();

    JsonResponse::send([
        'success' => true,
        'users' => $users,
    ]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível listar os usuários.');
}
