<?php

declare(strict_types=1);

use FurkiCycle\Auth\AuthenticationException;
use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Http\JsonResponse;
use Firebase\JWT\JWT;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    JsonResponse::send(['success' => false, 'message' => 'Método não permitido.'], 405);
}

try {
    $env = new Env();

    // Só renova com um token AINDA VÁLIDO (valida assinatura/emissor/exp/perfil).
    // Qualquer falha de token (inclusive expirado) responde 401 → o front desloga.
    $claims = (new JwtGuard())->requireAuthenticatedUser($env);

    // Reconfirma no banco: usuário desativado ou removido não renova a sessão, e o
    // perfil do novo token é o perfil ATUAL (mudanças de perfil passam a valer na renovação).
    $connection = MySqlConnection::create($env);
    $stmt = $connection->prepare('SELECT role FROM users WHERE id = :id AND is_active = 1');
    $stmt->execute(['id' => (string) $claims['sub']]);
    $currentRole = $stmt->fetchColumn();
    if (!is_string($currentRole) || $currentRole === '') {
        throw new AuthenticationException('Sessão expirada. Faça login novamente.');
    }

    $newClaims = [
        'sub' => (string) $claims['sub'],
        'role' => $currentRole,
        'iss' => $env->required('JWT_ISSUER'),
        'exp' => time() + $env->int('JWT_EXPIRATION_SECONDS', 3600),
    ];
    $token = JWT::encode($newClaims, $env->required('JWT_SECRET'), 'HS256');

    JsonResponse::send(['success' => true, 'token' => $token]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível renovar a sessão.');
}
