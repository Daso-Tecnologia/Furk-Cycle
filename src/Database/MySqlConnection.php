<?php

declare(strict_types=1);

namespace FurkiCycle\Database;

use FurkiCycle\Config\Env;
use PDO;

final class MySqlConnection
{
    public static function create(Env $env): PDO
    {
        $charset = $env->optional('MYSQL_CHARSET', 'utf8mb4');
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $env->required('MYSQL_HOST'),
            $env->int('MYSQL_PORT', 3306),
            $env->required('MYSQL_DATABASE'),
            $charset,
        );

        $pdo = new PDO(
            $dsn,
            $env->required('MYSQL_USERNAME'),
            $env->required('MYSQL_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );

        self::applySessionTimezone($pdo, $env->optional('APP_TIMEZONE', 'America/Sao_Paulo'));

        return $pdo;
    }

    /**
     * Conexão ao banco estrangeiro (ex.: exemplo_bi_insight).
     * Por padrão usa o mesmo host/porta/usuário/senha do banco de controle
     * (MYSQL_*). Se o banco estrangeiro estiver em outro host, defina
     * FOREIGN_DB_HOST / FOREIGN_DB_PORT (e, se preciso, FOREIGN_DB_USERNAME /
     * FOREIGN_DB_PASSWORD) no .env.
     */
    public static function createForeign(Env $env): PDO
    {
        $charset = $env->optional('FOREIGN_DB_CHARSET', 'utf8mb4');
        $host = $env->optional('FOREIGN_DB_HOST', $env->required('MYSQL_HOST'));
        $port = (int) $env->optional('FOREIGN_DB_PORT', (string) $env->int('MYSQL_PORT', 3306));
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $host,
            $port,
            $env->required('FOREIGN_DB_NAME'),
            $charset,
        );

        $pdo = new PDO(
            $dsn,
            $env->optional('FOREIGN_DB_USERNAME', $env->required('MYSQL_USERNAME')),
            $env->optional('FOREIGN_DB_PASSWORD', $env->required('MYSQL_PASSWORD')),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );

        self::applySessionTimezone($pdo, $env->optional('APP_TIMEZONE', 'America/Sao_Paulo'));

        return $pdo;
    }

    private static function applySessionTimezone(PDO $pdo, string $timezone): void
    {
        if ($timezone === '') {
            return;
        }
        try {
            $zone = new \DateTimeZone($timezone);
            $offset = (new \DateTimeImmutable('now', $zone))->getOffset();
            $sign = $offset < 0 ? '-' : '+';
            $offset = abs($offset);
            $offsetStr = sprintf('%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
            $pdo->exec('SET time_zone = ' . $pdo->quote($offsetStr));
        } catch (\Throwable) {
            // Sem timezone válida, mantém a do servidor.
        }
    }
}
