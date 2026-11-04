<?php

declare(strict_types=1);

namespace FurkiCycle\Http;

use FurkiCycle\Auth\AuthenticationException;
use FurkiCycle\Auth\AuthorizationException;
use FurkiCycle\Config\ConfigurationException;
use JsonException;
use PDOException;
use RuntimeException;
use Throwable;

final class JsonResponse
{
    /** @param array<string, mixed> $body */
    public static function send(array $body, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Traduz uma exceção em resposta HTTP sem vazar detalhes internos.
     *
     * - autenticação ausente/inválida → 401; perfil sem permissão → 403;
     * - operação bloqueada pelo estado do sistema (ConflictException) → 409;
     * - JSON inválido e erros de regra de negócio (RuntimeException) → 400, com a
     *   mensagem já escrita para o usuário;
     * - banco de dados, configuração e qualquer erro inesperado → 500 com a mensagem
     *   genérica da rota; o detalhe vai somente para o log do servidor.
     */
    public static function fromThrowable(Throwable $error, string $fallbackMessage): never
    {
        if ($error instanceof AuthenticationException) {
            self::send(['success' => false, 'message' => $error->getMessage()], 401);
        }
        if ($error instanceof AuthorizationException) {
            self::send(['success' => false, 'message' => $error->getMessage()], 403);
        }
        if ($error instanceof ConflictException) {
            self::send(['success' => false, 'message' => $error->getMessage()], 409);
        }
        if ($error instanceof JsonException) {
            self::send(['success' => false, 'message' => 'O corpo da requisição não contém JSON válido.'], 400);
        }
        if (
            $error instanceof RuntimeException
            && !$error instanceof PDOException
            && !$error instanceof ConfigurationException
        ) {
            self::send(['success' => false, 'message' => $error->getMessage()], 400);
        }

        error_log(sprintf(
            '%s: %s [%s]',
            basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'api')),
            $error->getMessage(),
            get_class($error),
        ));
        self::send(['success' => false, 'message' => $fallbackMessage], 500);
    }
}
