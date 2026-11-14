<?php

declare(strict_types=1);

namespace FurkiCycle\Http;

use RuntimeException;

/**
 * A operação não pode ser executada agora por causa do estado do sistema
 * (ex.: outra sincronização em andamento). Vira HTTP 409 com a mensagem ao usuário.
 */
final class ConflictException extends RuntimeException
{
}
