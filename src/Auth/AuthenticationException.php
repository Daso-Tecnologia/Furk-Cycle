<?php

declare(strict_types=1);

namespace FurkiCycle\Auth;

use RuntimeException;

/** Token ausente, inválido ou expirado (HTTP 401). */
final class AuthenticationException extends RuntimeException
{
}
