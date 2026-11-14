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
    (new JwtGuard())->requireRole($claims, ['ADMIN']);

    $body = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new RuntimeException('O corpo da requisição deve ser um objeto JSON.');
    }

    $id = (int) ($body['id'] ?? 0);
    $password = (string) ($body['password'] ?? '');

    if ($id <= 0) {
        throw new RuntimeException('Usuário inválido.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('A nova senha deve ter pelo menos 8 caracteres.');
    }

    $repository = new UserRepository($env);
    $existing = $repository->findById($id);
    if ($existing === null) {
        throw new RuntimeException('Usuário não encontrado.');
    }

    $repository->resetPassword($id, $password);

    JsonResponse::send(['success' => true, 'message' => 'Senha redefinida com sucesso.']);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível redefinir a senha.');
}
