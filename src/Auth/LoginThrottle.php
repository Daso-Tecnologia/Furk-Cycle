<?php

declare(strict_types=1);

namespace FurkiCycle\Auth;

use Throwable;

/**
 * Limita tentativas de login malsucedidas (força bruta / password spraying).
 *
 * Guarda os contadores em arquivos no diretório temporário do servidor, sem exigir
 * migration. Se o armazenamento estiver indisponível, NÃO bloqueia o login
 * (falha aberta) e registra o problema no log.
 *
 * Duas janelas independentes:
 *  - por par (e-mail + IP): `maxAttempts` falhas em `windowSeconds`;
 *  - por IP: `maxAttemptsPerIp` falhas em `windowSeconds`, contra varredura de e-mails.
 */
final class LoginThrottle
{
    private readonly string $directory;

    public function __construct(
        private readonly int $maxAttempts = 5,
        private readonly int $maxAttemptsPerIp = 30,
        private readonly int $windowSeconds = 900,
        ?string $directory = null,
    ) {
        $this->directory = $directory ?? rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'furk_cycle_login';
    }

    /** Segundos até a próxima tentativa liberada; 0 se pode tentar. */
    public function retryAfter(string $email, string $ip): int
    {
        try {
            return max(
                $this->remaining($this->pairKey($email, $ip), $this->maxAttempts),
                $this->remaining($this->ipKey($ip), $this->maxAttemptsPerIp),
            );
        } catch (Throwable $error) {
            error_log('login-throttle: ' . $error->getMessage());
            return 0;
        }
    }

    public function recordFailure(string $email, string $ip): void
    {
        try {
            $this->append($this->pairKey($email, $ip));
            $this->append($this->ipKey($ip));
        } catch (Throwable $error) {
            error_log('login-throttle: ' . $error->getMessage());
        }
    }

    public function clear(string $email, string $ip): void
    {
        try {
            $file = $this->path($this->pairKey($email, $ip));
            if (is_file($file)) {
                @unlink($file);
            }
        } catch (Throwable $error) {
            error_log('login-throttle: ' . $error->getMessage());
        }
    }

    private function pairKey(string $email, string $ip): string
    {
        return hash('sha256', 'pair|' . strtolower(trim($email)) . '|' . $ip);
    }

    private function ipKey(string $ip): string
    {
        return hash('sha256', 'ip|' . $ip);
    }

    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $key . '.json';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Diretório de controle de login indisponível.');
        }
    }

    private function remaining(string $key, int $limit): int
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return 0;
        }
        $failures = $this->recent($this->read($file));
        if (count($failures) < $limit) {
            return 0;
        }
        // Libera quando a falha mais antiga que ainda conta sair da janela.
        $oldest = $failures[count($failures) - $limit];
        return max(1, $oldest + $this->windowSeconds - time());
    }

    private function append(string $key): void
    {
        $this->ensureDirectory();
        $handle = fopen($this->path($key), 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Não foi possível gravar o controle de login.');
        }
        try {
            flock($handle, LOCK_EX);
            $raw = stream_get_contents($handle);
            $failures = $this->recent($this->decode(is_string($raw) ? $raw : ''));
            $failures[] = time();
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($failures, JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<int, int> */
    private function read(string $file): array
    {
        $raw = @file_get_contents($file);
        return $this->decode(is_string($raw) ? $raw : '');
    }

    /** @return array<int, int> */
    private function decode(string $raw): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        return array_values(array_filter($data, static fn (mixed $value): bool => is_int($value)));
    }

    /**
     * @param array<int, int> $failures
     * @return array<int, int>
     */
    private function recent(array $failures): array
    {
        $limit = time() - $this->windowSeconds;
        $kept = array_values(array_filter($failures, static fn (int $time): bool => $time > $limit));
        sort($kept);
        return $kept;
    }
}
