<?php
declare(strict_types=1);

/*
 * Valida que os workers (disparados a cada minuto pelo Agendador) NÃO pegam o lock quando não há trabalho:
 * antes, uma rotina parada segurava o lock por ~1-2 s por minuto e o ImportGuard anunciava "outra sincronização
 * em andamento" (falso alarme). Usa um MySQL de TESTE (trava em tools/_teste_banco.php) e, para provar que o lock
 * não é tocado, SEGURA o lock numa outra conexão: um worker que tentasse pegá-lo falharia ou ficaria esperando.
 * Faz TRUNCATE nas tabelas de estado do BANCO DE TESTE. Nenhuma credencial Furk/GATE é repassada.
 *
 * Uso: php tools/validar_workers_sem_lock.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/_teste_banco.php';

use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Sync\SyncContinuoRepository;
use FurkiCycle\Sync\SyncContinuoService;
use FurkiCycle\Sync\SyncJobRepository;
use FurkiCycle\Sync\SyncJobService;

[$env, $banco] = carregarEnvTeste($root, $argv[1] ?? null);
echo "Banco de TESTE: {$banco}\n\n";

$pdo = MySqlConnection::create($env);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$holder = MySqlConnection::create($env);
$fail = 0;

function check(string $nome, bool $ok, string $detalhe = ''): void
{
    global $fail;
    echo ($ok ? 'OK    ' : 'FALHA ') . $nome . ($detalhe !== '' ? " - {$detalhe}" : '') . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

function limpar(PDO $pdo): void
{
    foreach (['furk_sync_rotina', 'furk_dynamic_status_job', 'furk_sales_job', 'furk_sync_job', 'furk_sync_continuo_job'] as $tabela) {
        $pdo->exec("TRUNCATE TABLE {$tabela}");
    }
}

/** @return array{0: int, 1: string} código de saída e saída padrão do worker, rodando contra o banco de TESTE */
function rodarWorker(string $script, $env): array
{
    $ambiente = getenv();
    foreach (['MYSQL_HOST', 'MYSQL_PORT', 'MYSQL_DATABASE', 'MYSQL_USERNAME', 'MYSQL_PASSWORD', 'MYSQL_CHARSET', 'APP_TIMEZONE'] as $chave) {
        $valor = $env->optional($chave);
        if ($valor !== null) {
            $ambiente[$chave] = $valor;
        }
    }
    foreach (['FURK_CLIENT_ID', 'FURK_CLIENT_SECRET', 'FURK_API_KEY', 'FURK_SALES_SCOPE', 'FURK_DYNAMIC_STATUS_SCOPE', 'GATE_DB_HOST', 'GATE_DB_PASSWORD'] as $chave) {
        $ambiente[$chave] = 'DESABILITADO';
    }
    $processo = proc_open([PHP_BINARY, dirname(__DIR__) . '/worker/' . $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $ambiente);
    $saida = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    $codigo = proc_close($processo);

    return [$codigo, $saida];
}

function segurar(PDO $conexao, string $lock): void
{
    $conexao->prepare('SELECT GET_LOCK(:n, 0)')->execute(['n' => $lock]);
}

function soltar(PDO $conexao, string $lock): void
{
    $conexao->prepare('SELECT RELEASE_LOCK(:n)')->execute(['n' => $lock]);
}

echo "== A. Workers com a rotina PARADA não tocam no lock (lock mantido por outra conexão)\n";
foreach ([
    ['sales_worker.php', 'furk_sales_job', 'furk_sales_routine', 'STOP_REQUESTED'],
    ['sales_worker.php', 'furk_sales_job', 'furk_sales_routine', 'IDLE'],
    ['dynamic_status_worker.php', 'furk_dynamic_status_job', 'furk_dynamic_status_routine', 'STOP_REQUESTED'],
    ['dynamic_status_worker.php', 'furk_dynamic_status_job', 'furk_dynamic_status_routine', 'IDLE'],
] as [$script, $tabela, $lock, $estado]) {
    limpar($pdo);
    $pdo->prepare("INSERT INTO {$tabela} (estado) VALUES (:e)")->execute(['e' => $estado]);
    segurar($holder, $lock);
    [$codigo, $saida] = rodarWorker($script, $env);
    check("{$script} com a rotina em {$estado}: sai com 0 e \"skipped\" (sem pegar o lock)", $codigo === 0 && str_contains($saida, '"skipped": true'), "código {$codigo}");
    soltar($holder, $lock);
}

echo "\n== B. Recursiva IDLE/ERROR não pega o lock\n";
foreach (['IDLE', 'ERROR'] as $estado) {
    limpar($pdo);
    $pdo->prepare('INSERT INTO furk_sync_rotina (estado) VALUES (:e)')->execute(['e' => $estado]);
    segurar($holder, 'furk_sync_rotina_routine');
    [$codigo, $saida] = rodarWorker('sync_rotina_worker.php', $env);
    check("sync_rotina_worker com a Recursiva em {$estado}: sai com 0 e \"skipped\" (nota: o lock estava ocupado por outra conexão)", $codigo === 0 && str_contains($saida, '"skipped": true') && str_contains($saida, 'Rotina parada'), "código {$codigo}");
    soltar($holder, 'furk_sync_rotina_routine');
}

echo "\n== C. Consolidada e Consolidada Contínua sem job: runCycle não lança nem pega o lock\n";
limpar($pdo);
segurar($holder, 'furk_sync_job_routine');
$job = new SyncJobService($env, $pdo, new SyncJobRepository($pdo));
$lancou = null;
try {
    $r = $job->runCycle('teste');
} catch (\Throwable $e) {
    $lancou = $e->getMessage();
}
check('Consolidada sem job pendente com o lock ocupado por outro: devolve "Nenhum job pendente" (antes lançava "já existe")', $lancou === null && str_contains((string) ($r['message'] ?? ''), 'Nenhum job pendente'), (string) $lancou);
soltar($holder, 'furk_sync_job_routine');

segurar($holder, 'furk_sync_continuo_routine');
$cont = new SyncContinuoService($env, $pdo, new SyncContinuoRepository($pdo));
$lancou = null;
try {
    $r = $cont->runCycle('teste');
} catch (\Throwable $e) {
    $lancou = $e->getMessage();
}
check('Contínua sem job com o lock ocupado por outro: devolve "Nenhum job pendente"', $lancou === null && str_contains((string) ($r['message'] ?? ''), 'Nenhum job pendente'), (string) $lancou);
soltar($holder, 'furk_sync_continuo_routine');

echo "\n== D. Com trabalho e o lock ocupado por outro worker, continua sendo recusado (exclusividade preservada)\n";
limpar($pdo);
$pdo->exec("INSERT INTO furk_sync_job (initial_date, final_date, estado) VALUES ('2026-10-01', '2026-10-02', 'QUEUED')");
segurar($holder, 'furk_sync_job_routine');
$msg = null;
try {
    $job->runCycle('teste');
} catch (\Throwable $e) {
    $msg = $e->getMessage();
}
check('Consolidada com job QUEUED e lock ocupado: "Já existe uma sincronização consolidada em execução"', $msg !== null && str_contains($msg, 'em execução'), (string) $msg);
soltar($holder, 'furk_sync_job_routine');

limpar($pdo);
echo PHP_EOL . ($fail === 0 ? 'TUDO OK' : "{$fail} FALHA(S)") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
