<?php

declare(strict_types=1);

namespace FurkiCycle\Auth;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Closure;
use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use Throwable;

final class JwtGuard
{
    /**
     * @param ?Closure(string): ?string $roleResolver USO EM TESTES: recebe o id do usuário e devolve o perfil
     *                                                ATUAL (ou null se desativado/removido). Em produção fica null
     *                                                e a consulta vai ao banco (com cache curto).
     */
    public function __construct(private readonly ?Closure $roleResolver = null)
    {
    }

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

        // Reconfirma o usuário: desativado ou removido perde o acesso (no máximo após SESSION_RECHECK_SECONDS,
        // padrão 30 s) e o perfil que vale é o ATUAL do banco, não o que está no token.
        $claims['role'] = $this->currentRole($env, (string) $claims['sub']);

        return $claims;
    }

    /**
     * Perfil atual do usuário. Consulta o banco no máximo 1 vez a cada N segundos por usuário
     * (SESSION_RECHECK_SECONDS, padrão 30; 0 = sempre consulta). Só usuários ATIVOS entram no cache;
     * usuário desativado/removido nunca é cacheado e apaga a entrada que existir.
     *
     * @throws AuthenticationException usuário desativado ou removido
     */
    private function currentRole(Env $env, string $userId): string
    {
        if ($this->roleResolver !== null) {
            $role = ($this->roleResolver)($userId);
            if (!is_string($role) || $role === '') {
                throw new AuthenticationException('Sessão encerrada. Faça login novamente.');
            }

            return $role;
        }

        $ttl = max(0, $env->int('SESSION_RECHECK_SECONDS', 30));
        $directory = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'furk_cycle_session';
        $file = $directory . DIRECTORY_SEPARATOR . hash('sha256', $userId) . '.json';

        if ($ttl > 0 && is_file($file)) {
            $data = json_decode((string) @file_get_contents($file), true);
            $now = time();
            if (
                is_array($data)
                && is_string($data['role'] ?? null) && $data['role'] !== ''
                && is_int($data['ts'] ?? null)
                && $data['ts'] <= $now + 5
                && $now - $data['ts'] < $ttl
            ) {
                return $data['role'];
            }
        }

        $statement = MySqlConnection::create($env)->prepare('SELECT role FROM users WHERE id = :id AND is_active = 1');
        $statement->execute(['id' => $userId]);
        $role = $statement->fetchColumn();
        if (!is_string($role) || $role === '') {
            if (is_file($file)) {
                @unlink($file);
            }
            throw new AuthenticationException('Sessão encerrada. Faça login novamente.');
        }

        if ($ttl > 0) {
            try {
                if (!is_dir($directory)) {
                    @mkdir($directory, 0700, true);
                }
                @file_put_contents($file, json_encode(['role' => $role, 'ts' => time()]), LOCK_EX);
            } catch (Throwable $error) {
                error_log('jwt-guard: cache de sessao indisponivel: ' . $error->getMessage());
            }
        }

        return $role;
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
