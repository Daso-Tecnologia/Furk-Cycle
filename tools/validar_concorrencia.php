<?php
declare(strict_types=1);

/*
 * Valida a regra "a Recursiva manda" + exclusão entre cargas (src/Sync/ImportGuard.php)
 * contra um MySQL de TESTE. NÃO usa a produção (trava em tools/_teste_banco.php).
 * ATENÇÃO: faz TRUNCATE nas tabelas de estado das rotinas do BANCO DE TESTE.
 *
 * Uso:
 *   php tools/preparar_banco_teste.php          (uma vez: cria o esquema)
 *   php tools/validar_concorrencia.php          (usa .env.test na raiz)
 *   php tools/validar_concorrencia.php C:\caminho\.env.test
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/_teste_banco.php';

use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Http\ConflictException;
use FurkiCycle\Sync\ImportGuard;

[$env, $banco] = carregarEnvTeste($root, $argv[1] ?? null);
echo "Banco de TESTE: {$banco}\n\n";

$pdo = MySqlConnection::create($env);
$fail = 0;

function check(string $nome, bool $ok): void
{
    global $fail;
    echo ($ok ? 'OK    ' : 'FALHA ') . $nome . PHP_EOL;
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

function estado(PDO $pdo, string $tabela, string $estado): void
{
    if ($tabela === 'furk_sync_continuo_job') {
        $pdo->prepare("INSERT INTO {$tabela} (initial_date, final_date, cursor_date, estado) VALUES ('2026-01-01', '2026-01-31', '2026-01-01', :e)")
            ->execute(['e' => $estado]);
        return;
    }
    $pdo->prepare("INSERT INTO {$tabela} (estado) VALUES (:e)")->execute(['e' => $estado]);
}

function segurarLock(PDO $conexao, string $nome): void
{
    $conexao->prepare('SELECT GET_LOCK(:n, 0)')->execute(['n' => $nome]);
}

function soltarLock(PDO $conexao, string $nome): void
{
    $conexao->prepare('SELECT RELEASE_LOCK(:n)')->execute(['n' => $nome]);
}

/** @return string|null mensagem do ConflictException, ou null se não bloqueou */
function bloqueio(callable $fn): ?string
{
    try {
        $fn();
    } catch (ConflictException $erro) {
        return $erro->getMessage();
    }
    return null;
}

$admin = ['role' => 'ADMIN'];
$supervisor = ['role' => 'SUPERVISOR'];
$holder = MySqlConnection::create($env);   // outra sessão, para simular "ciclo em execução"
$prefixoLock = (string) $env->required('LOCK_PREFIX');   // mesmo prefixo usado pelo ImportGuard

// ---------------------------------------------------------------- 1
echo "== 1. Tudo ocioso\n";
limpar($pdo);
$g = new ImportGuard($env);
check('carga permitida', bloqueio(fn () => $g->requireCargaPermitida($admin)) === null);
check('Recursiva pode iniciar', bloqueio(fn () => $g->requireRecursivaPodeIniciar()) === null);
$s = $g->status($admin);
check('status: cargas_bloqueadas=false, recursiva inativa', $s['cargas_bloqueadas'] === false && $s['recursiva']['ativa'] === false && $s['mensagem'] === null);

// ---------------------------------------------------------------- 2
echo "\n== 2. Recursiva RUNNING bloqueia as cargas\n";
limpar($pdo);
estado($pdo, 'furk_sync_rotina', 'RUNNING');
$g = new ImportGuard($env);
$m = bloqueio(fn () => $g->requireCargaPermitida($admin));
check('ADMIN: bloqueado com a mensagem simples', $m === ImportGuard::MESSAGE_RECURSIVA);
$m = bloqueio(fn () => $g->requireCargaPermitida($supervisor));
check('SUPERVISOR: mensagem + "solicite a um administrador"', $m === ImportGuard::MESSAGE_RECURSIVA . ImportGuard::MESSAGE_SOLICITE_ADMIN);
check('status: ativa=true e cargas_bloqueadas=true', $g->status($admin)['recursiva']['ativa'] === true && $g->status($admin)['cargas_bloqueadas'] === true);
check('leitura manual também bloqueada (beginManualLoad)', bloqueio(fn () => (new ImportGuard($env))->beginManualLoad($admin)) === ImportGuard::MESSAGE_RECURSIVA);
check('...e o lock da leitura manual NÃO ficou preso', bloqueio(fn () => (new ImportGuard($env))->requireCargaPermitida($admin)) !== null /* ainda bloqueia pela Recursiva */ && !in_array('Leitura manual', (new ImportGuard($env))->outrasEmAndamento(), true));

// ---------------------------------------------------------------- 3
echo "\n== 3. Recursiva STOP_REQUESTED (ciclo pode estar terminando) ainda bloqueia\n";
limpar($pdo);
estado($pdo, 'furk_sync_rotina', 'STOP_REQUESTED');
check('bloqueado', bloqueio(fn () => (new ImportGuard($env))->requireCargaPermitida($admin)) === ImportGuard::MESSAGE_RECURSIVA);

// ---------------------------------------------------------------- 4
echo "\n== 4. Recursiva IDLE, mas com ciclo em execução (lock ocupado)\n";
limpar($pdo);
estado($pdo, 'furk_sync_rotina', 'IDLE');
segurarLock($holder, $prefixoLock . 'furk_sync_rotina_routine');
check('bloqueado enquanto o lock do ciclo estiver ocupado', bloqueio(fn () => (new ImportGuard($env))->requireCargaPermitida($admin)) === ImportGuard::MESSAGE_RECURSIVA);
soltarLock($holder, $prefixoLock . 'furk_sync_rotina_routine');
check('liberado quando o ciclo termina', bloqueio(fn () => (new ImportGuard($env))->requireCargaPermitida($admin)) === null);

// ---------------------------------------------------------------- 5
echo "\n== 5. Outra rotina RUNNING (Recursiva parada)\n";
limpar($pdo);
estado($pdo, 'furk_dynamic_status_job', 'RUNNING');
$g = new ImportGuard($env);
$m = bloqueio(fn () => $g->requireCargaPermitida($admin));
check('carga bloqueada citando a rotina de Status', $m !== null && str_contains($m, 'Status Dinâmicos'));
$m = bloqueio(fn () => $g->requireRecursivaPodeIniciar());
check('iniciar a Recursiva bloqueado', $m !== null && str_contains($m, 'Status Dinâmicos'));
check('iniciar a PRÓPRIA rotina de Status (ignorando a si mesma): permitido', bloqueio(fn () => $g->requireCargaPermitida($admin, ['furk_dynamic_status_job'])) === null);

// ---------------------------------------------------------------- 6
echo "\n== 6. Resíduo inerte: STOP_REQUESTED com o lock livre (caso real da Rotina de Status desde 14/10)\n";
limpar($pdo);
estado($pdo, 'furk_dynamic_status_job', 'STOP_REQUESTED');
$g = new ImportGuard($env);
check('NÃO bloqueia a carga', bloqueio(fn () => $g->requireCargaPermitida($admin)) === null);
check('NÃO bloqueia iniciar a Recursiva', bloqueio(fn () => $g->requireRecursivaPodeIniciar()) === null);

// ---------------------------------------------------------------- 7
echo "\n== 7. Consolidada e Consolidada Contínua\n";
limpar($pdo);
estado($pdo, 'furk_sync_job', 'QUEUED');
$g = new ImportGuard($env);
check('Consolidada QUEUED bloqueia iniciar a Recursiva', bloqueio(fn () => $g->requireRecursivaPodeIniciar()) !== null);
$ignorarContinuo = ['furk_dynamic_status_job', 'furk_sales_job', 'furk_sync_continuo_job'];
check('Consolidada QUEUED bloqueia a Consolidada Contínua', bloqueio(fn () => $g->requireCargaPermitida($admin, $ignorarContinuo)) !== null);
limpar($pdo);
estado($pdo, 'furk_dynamic_status_job', 'RUNNING');
estado($pdo, 'furk_sales_job', 'RUNNING');
check('Contínua ignora Status/Sales RUNNING (ela as pausa por desenho)', bloqueio(fn () => (new ImportGuard($env))->requireCargaPermitida($admin, $ignorarContinuo)) === null);
limpar($pdo);
estado($pdo, 'furk_sync_continuo_job', 'RUNNING');
check('Contínua RUNNING bloqueia iniciar a Recursiva', bloqueio(fn () => (new ImportGuard($env))->requireRecursivaPodeIniciar()) !== null);
check('...mas a própria Contínua ignora a si mesma (serviço dela decide)', bloqueio(fn () => (new ImportGuard($env))->requireCargaPermitida($admin, $ignorarContinuo)) === null);

// ---------------------------------------------------------------- 8
echo "\n== 8. Duas leituras manuais ao mesmo tempo\n";
limpar($pdo);
$a = new ImportGuard($env);
$b = new ImportGuard($env);
check('A inicia a leitura manual', bloqueio(fn () => $a->beginManualLoad($admin)) === null);
$m = bloqueio(fn () => $b->beginManualLoad($admin));
check('B é recusado ("outra leitura manual em andamento")', $m !== null && str_contains($m, 'outra leitura manual') || $m !== null && str_contains($m, 'Leitura manual'));
$m = bloqueio(fn () => (new ImportGuard($env))->requireRecursivaPodeIniciar());
check('iniciar a Recursiva também é recusado enquanto A roda', $m !== null && str_contains($m, 'Leitura manual'));
$a->releaseManualLock();
check('B consegue depois que A termina', bloqueio(fn () => $b->beginManualLoad($admin)) === null);
$b->releaseManualLock();

// ---------------------------------------------------------------- 9
echo "\n== 9. Queda do PHP no meio da leitura manual (o lock deve cair sozinho)\n";
limpar($pdo);
$cmd = [PHP_BINARY, __DIR__ . '/_segurar_lock.php'];
if (isset($argv[1])) {
    $cmd[] = $argv[1];
}
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$travou = false;
if (is_resource($proc)) {
    stream_set_blocking($pipes[1], false);
    $limite = microtime(true) + 20;
    $saida = '';
    while (microtime(true) < $limite) {
        $saida .= (string) fread($pipes[1], 100);
        if (str_contains($saida, 'LOCKED')) {
            $travou = true;
            break;
        }
        usleep(100000);
    }
}
check('processo auxiliar segurou o lock', $travou);
if ($travou) {
    $m = bloqueio(fn () => (new ImportGuard($env))->beginManualLoad($admin));
    check('enquanto vivo, outra leitura manual é recusada', $m !== null);
    proc_terminate($proc);                       // simula a queda/morte do PHP (sem liberar o lock)
    $liberou = false;
    $limite = microtime(true) + 15;
    while (microtime(true) < $limite) {
        if (bloqueio(fn () => (new ImportGuard($env))->beginManualLoad($admin)) === null) {
            $liberou = true;
            break;
        }
        usleep(500000);
    }
    check('após a morte do processo, o lock é liberado sozinho', $liberou);
}
if (is_resource($proc)) {
    proc_close($proc);
}

// ---------------------------------------------------------------- 10
echo "\n== 10. Status para a interface\n";
limpar($pdo);
estado($pdo, 'furk_sync_rotina', 'RUNNING');
$s = (new ImportGuard($env))->status($supervisor);
check('estrutura completa do status', isset($s['recursiva']['estado'], $s['recursiva']['ativa'], $s['outras_em_andamento'], $s['cargas_bloqueadas'], $s['mensagem']));
check('mensagem do supervisor pede um administrador', str_contains((string) $s['mensagem'], 'Solicite a um administrador'));

limpar($pdo);
echo "\n" . ($fail === 0 ? 'TODOS OS CENÁRIOS PASSARAM' : "{$fail} FALHA(S)") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
