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
    $name = trim((string) ($body['name'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $role = trim((string) ($body['role'] ?? ''));
    $isActive = (bool) ($body['is_active'] ?? true);

    if ($id <= 0) {
        throw new RuntimeException('Usuário inválido.');
    }
    if ($name === '') {
        throw new RuntimeException('Informe o nome do usuário.');
    }
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Informe um e-mail válido.');
    }

    $repository = new UserRepository($env);
    $existing = $repository->findById($id);
    if ($existing === null) {
        throw new RuntimeException('Usuário não encontrado.');
    }

    $repository->assertEmailUnique($email, $id);
    $repository->update($id, $name, $email, $role, $isActive);

    JsonResponse::send(['success' => true, 'message' => 'Usuário atualizado com sucesso.']);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível atualizar o usuário.');
}
