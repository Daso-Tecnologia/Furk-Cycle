<?php

declare(strict_types=1);

namespace FurkiCycle\Config;

use RuntimeException;

/**
 * Configuração de ambiente ausente ou inválida. É falha do servidor (HTTP 500):
 * o detalhe vai para o log e nunca para o usuário final.
 */
final class ConfigurationException extends RuntimeException
{
}
