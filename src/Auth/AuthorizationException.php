<?php

declare(strict_types=1);

namespace FurkiCycle\Auth;

use RuntimeException;

/** Usuário autenticado, mas sem perfil para a operação (HTTP 403). */
final class AuthorizationException extends RuntimeException
{
}
