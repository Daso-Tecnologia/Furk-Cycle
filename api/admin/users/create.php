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

    $name = trim((string) ($body['name'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $role = trim((string) ($body['role'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($name === '') {
        throw new RuntimeException('Informe o nome do usuário.');
    }
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Informe um e-mail válido.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('A senha deve ter pelo menos 8 caracteres.');
    }

    $repository = new UserRepository($env);
    $repository->assertEmailUnique($email);
    $id = $repository->create($name, $email, $role, $password);

    JsonResponse::send(['success' => true, 'id' => $id, 'message' => 'Usuário criado com sucesso.']);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível criar o usuário.');
}
