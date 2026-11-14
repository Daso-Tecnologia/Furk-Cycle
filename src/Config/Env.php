<?php

declare(strict_types=1);

namespace FurkiCycle\Config;

final class Env
{
    /**
     * Valores do .env já lidos, por caminho. Fica em memória do processo: o arquivo
     * é lido uma vez e o .env NÃO é propagado com putenv(), que não é seguro entre
     * threads no Apache do Windows (causava "variável não configurada" intermitente).
     *
     * @var array<string, array<string, string>>
     */
    private static array $cache = [];

    /** @var array<string, string> */
    private readonly array $values;

    public function __construct(?string $envPath = null)
    {
        $path = $envPath ?? dirname(__DIR__, 2) . '/.env';
        self::$cache[$path] ??= self::parseFile($path);
        $this->values = self::$cache[$path];

        $timezone = $this->optional('APP_TIMEZONE', 'America/Sao_Paulo');
        if ($timezone !== null && $timezone !== '') {
            date_default_timezone_set($timezone);
        }
    }

    /** @return array<string, string> */
    private static function parseFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '') {
                continue;
            }
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            $values[$key] = $value;
        }

        return $values;
    }

    public function required(string $name): string
    {
        $value = $this->optional($name);

        if ($value === null || $value === '') {
            throw new ConfigurationException("A variável de ambiente {$name} não foi configurada.");
        }

        return $value;
    }

    public function optional(string $name, ?string $default = null): ?string
    {
        // O ambiente real do processo (SetEnv do Apache, variável do Windows) tem
        // prioridade sobre o .env, como antes.
        $value = getenv($name);
        if ($value === false) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? $this->values[$name] ?? false;
        }
        if ($value === false || $value === '') {
            return $default;
        }
        return trim((string) $value);
    }

    public function int(string $name, int $default): int
    {
        $value = $this->optional($name);

        if ($value === null || $value === '') {
            return $default;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false) {
            throw new ConfigurationException("A variável {$name} deve ser um número inteiro.");
        }

        return $number;
    }
}
