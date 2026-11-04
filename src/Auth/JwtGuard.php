<?php

declare(strict_types=1);

namespace FurkiCycle\Auth;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use FurkiCycle\Config\Env;
use Throwable;

final class JwtGuard
{
    /**
     * @return array<string, mixed>
     * @throws AuthenticationException token ausente, inválido ou expirado
     */
    public function requireAuthenticatedUser(Env $env): array
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($authorization) || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            throw new AuthenticationException('Autenticação obrigatória.');
        }

        $secret = $env->required('JWT_SECRET');
        $issuer = $env->required('JWT_ISSUER');

        try {
            $claims = (array) JWT::decode($matches[1], new Key($secret, 'HS256'));
        } catch (ExpiredException) {
            throw new AuthenticationException('Sessão expirada. Faça login novamente.');
        } catch (Throwable) {
            // Assinatura inválida, token malformado, etc.: sem detalhes para o cliente.
            throw new AuthenticationException('Token inválido.');
        }

        if (($claims['iss'] ?? null) !== $issuer) {
            throw new AuthenticationException('Token inválido.');
        }

        // Todo token emitido pelo sistema tem expiração; sem ela o token valeria para sempre.
        if (!isset($claims['exp']) || !isset($claims['sub'], $claims['role'])) {
            throw new AuthenticationException('Token inválido.');
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<int, string> $allowedRoles
     * @throws AuthorizationException perfil sem permissão
     */
    public function requireRole(array $claims, array $allowedRoles): void
    {
        $role = $claims['role'] ?? null;
        if (!is_string($role) || !in_array($role, $allowedRoles, true)) {
            throw new AuthorizationException('Seu perfil não tem permissão para executar esta operação.');
        }
    }
}
