<?php

declare(strict_types=1);

namespace FurkiCycle\Support;

/**
 * Registro de término anormal dos workers (scripts de linha de comando do Agendador).
 *
 * Sob o Agendador do Windows não há console: um erro fatal do PHP (memória, por exemplo) ou um
 * processo encerrado deixava o job "preso" sem nenhum rastro. Este registro grava, no diretório
 * temporário do sistema (nunca dentro de public/), uma linha por erro fatal ou falha do worker.
 * Nunca grava credenciais; só o nome do worker, a mensagem do PHP e o pico de memória.
 */
final class WorkerLog
{
    private const FILE = 'furk_cycle_worker.log';
    private const MAX_BYTES = 2_000_000;

    /** Registra erros FATAIS do PHP (não capturáveis por try/catch) ao final do script. */
    public static function registerShutdown(string $worker): void
    {
        $startedAt = microtime(true);
        register_shutdown_function(static function () use ($worker, $startedAt): void {
            $error = error_get_last();
            if ($error === null) {
                return;
            }
            $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
            if (!in_array($error['type'], $fatal, true)) {
                return;
            }

            self::write($worker, sprintf(
                'ERRO FATAL após %.0f s (pico de memória %.0f MB): %s em %s:%d',
                microtime(true) - $startedAt,
                memory_get_peak_usage(true) / 1048576,
                $error['message'],
                basename((string) $error['file']),
                (int) $error['line'],
            ));
        });
    }

    public static function write(string $worker, string $message): void
    {
        try {
            $file = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . self::FILE;
            if (is_file($file) && (int) filesize($file) > self::MAX_BYTES) {
                @rename($file, $file . '.1');
            }
            $line = sprintf("[%s] pid=%d %s: %s\n", date('Y-m-d H:i:s'), getmypid(), $worker, $message);
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Registrar nunca pode derrubar o worker.
        }
    }

    public static function path(): string
    {
        return rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . self::FILE;
    }
}
