<?php
declare(strict_types=1);

/*
 * Valida a Sincronização Consolidada em BLOCOS (src/Sync/SyncJobService.php): blocos e ordem das tabelas,
 * Interromper entre os passos, retomada pelo cursor após queda, limite de retomadas, erro do importador,
 * exclusividade do lock, e a regra do ImportGuard para job órfão. Usa um importador FALSO (nunca chama a Furk)
 * e um MySQL de TESTE (trava em tools/_teste_banco.php). Faz TRUNCATE em furk_sync_job do BANCO DE TESTE.
 *
 * Uso: php tools/validar_consolidada.php        (usa .env.test na raiz)
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/_teste_banco.php';

use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Support\WorkerLog;
use FurkiCycle\Sync\ImportGuard;
use FurkiCycle\Sync\SyncJobRepository;
use FurkiCycle\Sync\SyncJobService;

[$env, $banco] = carregarEnvTeste($root, $argv[1] ?? null);
echo "Banco de TESTE: {$banco}\n\n";

$pdo = MySqlConnection::create($env);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$fail = 0;

function check(string $nome, bool $ok, string $detalhe = ''): void
{
    global $fail;
    echo ($ok ? 'OK    ' : 'FALHA ') . $nome . ($detalhe !== '' ? " - {$detalhe}" : '') . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

// Esquema (idempotente) e limpeza.
$sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($root . '/database/009_furk_sync_job.sql'));
foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $comando) {
    $pdo->exec($comando);
}
$pdo->exec('TRUNCATE TABLE furk_sync_job');

$repo = new SyncJobRepository($pdo);

/** @return array{0: SyncJobService, 1: ArrayObject<int, string>} serviço com importador falso e a lista de chamadas (objeto compartilhado) */
function servico(PDO $pdo, $env, ?Closure $durante = null, ?string $falhaEm = null): array
{
    $chamadas = new ArrayObject();
    $importer = function (string $tabela, string $ini, string $fim) use ($chamadas, $durante, $falhaEm): array {
        $chamadas[] = "{$tabela} {$ini}..{$fim}";
        if ($durante !== null) {
            $durante(count($chamadas));
        }
        if ($falhaEm !== null && "{$tabela} {$ini}..{$fim}" === $falhaEm) {
            throw new RuntimeException('Falha simulada da Furk');
        }

        return ['total' => 10, 'inserted' => 4, 'updated' => 6, 'pages' => 1, 'bi_insight' => ['ignorado' => true]];
    };
    $service = new SyncJobService($env, $pdo, new SyncJobRepository($pdo), $importer);

    return [$service, $chamadas];
}

function job(PDO $pdo, int $id): array
{
    return $pdo->query("SELECT * FROM furk_sync_job WHERE id = {$id}")->fetch();
}

function lockLivre(PDO $pdo): bool
{
    return (int) $pdo->query("SELECT IS_FREE_LOCK('furk_sync_job_routine')")->fetchColumn() === 1;
}

echo "== A. blocos de 3 dias (SYNC_JOB_BLOCK_DAYS=3)\n";
putenv('SYNC_JOB_BLOCK_DAYS=3');
[$svc, $chamadas] = servico($pdo, $env);
$id = $repo->create('2026-10-01', '2026-10-10');
$svc->runCycle('teste:1');
$chamadas = $chamadas->getArrayCopy();
$esperado = [];
foreach ([['2026-10-01', '2026-10-03'], ['2026-10-04', '2026-10-06'], ['2026-10-07', '2026-10-09'], ['2026-10-10', '2026-10-10']] as [$i, $f]) {
    foreach (['sales', 'creditos', 'refunds', 'bilhetes'] as $t) {
        $esperado[] = "{$t} {$i}..{$f}";
    }
}
$j = job($pdo, $id);
check('16 chamadas, na ordem bloco -> tabela (sales, creditos, refunds, bilhetes)', $chamadas === $esperado, count($chamadas) . ' chamadas');
check('estado COMPLETED e lock liberado', $j['estado'] === 'COMPLETED' && lockLivre($pdo));
$resumo = json_decode((string) $j['resumo'], true);
check('resumo soma os blocos (sales.total = 4 blocos x 10)', ($resumo['sales']['total'] ?? null) === 40 && !isset($resumo['sales']['bi_insight']));
check('percent 100 no status', $svc->status($id)['percent'] === 100);

echo "\n== B. Interromper entre os passos\n";
$pdo->exec('TRUNCATE TABLE furk_sync_job');
putenv('SYNC_JOB_BLOCK_DAYS=3');
$ref = null;
[$svc, $chamadas] = servico($pdo, $env, function (int $n) use (&$ref): void {
    if ($n === 3) {
        $ref->stop(); // o administrador clica em Interromper durante o 3o passo
    }
});
$ref = $svc;
$id = $repo->create('2026-10-01', '2026-10-10');
$svc->runCycle('teste:1');
$chamadas = $chamadas->getArrayCopy();
$j = job($pdo, $id);
$p = json_decode((string) $j['progresso'], true);
check('parou antes do 4o passo (3 chamadas)', count($chamadas) === 3, count($chamadas) . ' chamadas');
check('estado STOP_REQUESTED com progresso "interrompido" (cursor 2026-10-01, passo 3)', $j['estado'] === 'STOP_REQUESTED' && ($p['status'] ?? '') === 'interrompido' && ($p['cursor'] ?? '') === '2026-10-01' && ($p['passo'] ?? -1) === 3);
check('lock liberado apos interromper', lockLivre($pdo));
[$svc2, $chamadas2] = servico($pdo, $env);
$r = $svc2->runCycle('teste:2');
$chamadas2 = $chamadas2->getArrayCopy();
check('novo ciclo NAO retoma job interrompido ("Nenhum job pendente")', $chamadas2 === [] && str_contains((string) ($r['message'] ?? ''), 'Nenhum job pendente'));

echo "\n== C. retomada pelo cursor depois de queda\n";
$pdo->exec('TRUNCATE TABLE furk_sync_job');
$pdo->prepare("INSERT INTO furk_sync_job (initial_date, final_date, estado, progresso, resumo, heartbeat) VALUES ('2026-10-01','2026-10-10','RUNNING', :p, :r, :h)")
    ->execute(['p' => json_encode(['cursor' => '2026-10-04', 'passo' => 2, 'bloco_dias' => 3, 'retomadas' => 0]), 'r' => json_encode(['sales' => ['total' => 30]]), 'h' => date('Y-m-d H:i:s')]);
$id = (int) $pdo->lastInsertId();
[$svc, $chamadas] = servico($pdo, $env);
$svc->runCycle('teste:3');
$chamadas = $chamadas->getArrayCopy();
check('primeira chamada = refunds do bloco 04..06 (retoma do passo, nao do zero)', ($chamadas[0] ?? '') === 'refunds 2026-10-04..2026-10-06', $chamadas[0] ?? '(nenhuma)');
check('nao refez o que ja estava pronto (sem chamadas de 01..03)', count(array_filter($chamadas, fn ($c) => str_contains($c, '2026-10-01'))) === 0);
check('total = 2 (restante do bloco 04) + 8 (blocos 07 e 10) = 10 chamadas', count($chamadas) === 10, count($chamadas) . ' chamadas');
$j = job($pdo, $id);
$resumo = json_decode((string) $j['resumo'], true);
check('COMPLETED e resumo anterior preservado/somado (sales.total 30 + 20)', $j['estado'] === 'COMPLETED' && ($resumo['sales']['total'] ?? 0) === 50, (string) ($resumo['sales']['total'] ?? '?'));

echo "\n== D. limite de retomadas seguidas no mesmo ponto\n";
$pdo->exec('TRUNCATE TABLE furk_sync_job');
$pdo->prepare("INSERT INTO furk_sync_job (initial_date, final_date, estado, progresso, heartbeat) VALUES ('2026-10-01','2026-10-10','RUNNING', :p, NOW())")
    ->execute(['p' => json_encode(['cursor' => '2026-10-04', 'passo' => 1, 'bloco_dias' => 3, 'retomadas' => 3])]);
$id = (int) $pdo->lastInsertId();
[$svc, $chamadas] = servico($pdo, $env);
$svc->runCycle('teste:4');
$chamadas = $chamadas->getArrayCopy();
$j = job($pdo, $id);
check('4a retomada vira ERROR sem processar nada', $j['estado'] === 'ERROR' && $chamadas === [] && str_contains((string) $j['erro'], 'caiu'), (string) $j['erro']);
check('lock liberado', lockLivre($pdo));

echo "\n== E. falha do importador\n";
$pdo->exec('TRUNCATE TABLE furk_sync_job');
[$svc, $chamadas] = servico($pdo, $env, null, 'creditos 2026-10-01..2026-10-03');
$id = $repo->create('2026-10-01', '2026-10-10');
$lancou = false;
try {
    $svc->runCycle('teste:5');
} catch (RuntimeException $e) {
    $lancou = str_contains($e->getMessage(), 'Falha simulada');
}
$j = job($pdo, $id);
check('excecao propagada, job em ERROR com a mensagem, lock liberado', $lancou && $j['estado'] === 'ERROR' && str_contains((string) $j['erro'], 'Falha simulada') && lockLivre($pdo));

echo "\n== F. exclusividade e stop sem job\n";
$pdo->exec('TRUNCATE TABLE furk_sync_job');
$outra = MySqlConnection::create($env);
$outra->query("SELECT GET_LOCK('furk_sync_job_routine', 0)");
[$svc, $chamadas] = servico($pdo, $env);
$repo->create('2026-10-01', '2026-10-02');
$msg = null;
try {
    $svc->runCycle('teste:6');
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
}
check('segundo worker recusado enquanto o lock esta ocupado', $msg !== null && str_contains($msg, 'em execução') && count($chamadas) === 0);
$outra->query("SELECT RELEASE_LOCK('furk_sync_job_routine')");
$pdo->exec('TRUNCATE TABLE furk_sync_job');
$msg = null;
try {
    $svc->stop();
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
}
check('stop() sem job em andamento: mensagem clara', $msg !== null && str_contains($msg, 'Não há sincronização'));

echo "\n== G. ImportGuard: job orfao e mensagem\n";
$guard = new ImportGuard($env);
$pdo->exec('TRUNCATE TABLE furk_sync_job');
$pdo->exec("INSERT INTO furk_sync_job (initial_date, final_date, estado, heartbeat) VALUES ('2026-10-01','2026-10-10','RUNNING', '" . date('Y-m-d H:i:s', time() - 3600) . "')");
check('RUNNING sem batimento ha 1 h e lock livre NAO bloqueia (orfao)', !in_array('Sincronização Consolidada', $guard->outrasEmAndamento(), true));
$pdo->exec('TRUNCATE TABLE furk_sync_job');
$pdo->exec("INSERT INTO furk_sync_job (initial_date, final_date, estado, heartbeat) VALUES ('2026-10-01','2026-10-10','RUNNING', '" . date('Y-m-d H:i:s', time() - 120) . "')");
check('RUNNING com batimento recente bloqueia', in_array('Sincronização Consolidada', $guard->outrasEmAndamento(), true));
$pdo->exec('TRUNCATE TABLE furk_sync_job');
$pdo->exec("INSERT INTO furk_sync_job (initial_date, final_date, estado) VALUES ('2026-10-01','2026-10-10','QUEUED')");
check('QUEUED bloqueia', in_array('Sincronização Consolidada', $guard->outrasEmAndamento(), true));
$pdo->exec('TRUNCATE TABLE furk_sync_job');
$pdo->exec("INSERT INTO furk_sync_job (initial_date, final_date, estado, heartbeat) VALUES ('2026-10-01','2026-10-10','STOP_REQUESTED', NOW())");
check('STOP_REQUESTED (interrompido) NAO bloqueia', !in_array('Sincronização Consolidada', $guard->outrasEmAndamento(), true));

$pdo->exec('TRUNCATE TABLE furk_sync_job');
$pdo->exec("INSERT INTO furk_sync_job (initial_date, final_date, estado, heartbeat) VALUES ('2026-10-01','2026-10-10','RUNNING', '" . date('Y-m-d H:i:s') . "')");
$texto = null;
try {
    $guard->requireCargaPermitida([]);
} catch (\FurkiCycle\Http\ConflictException $e) {
    $texto = $e->getMessage();
}
check('mensagem de bloqueio cita a rotina e diz como interromper', $texto !== null && str_contains($texto, 'Sincronização Consolidada') && str_contains($texto, 'Aguarde terminar') && str_contains($texto, 'interrompê-la'));

echo "\n== G2. aviso em portugues claro (quem, quando, onde) e telas donas\n";
$pdo->exec('TRUNCATE TABLE furk_sync_job');
[$svc, $chamadas] = servico($pdo, $env);
$startRet = $svc->start('2026-10-01', '2026-10-02', 'Usuario Teste');
$prog = json_decode((string) job($pdo, (int) $startRet['job']['id'])['progresso'], true);
check('start() grava quem iniciou e o estado "na_fila"', ($prog['iniciado_por'] ?? '') === 'Usuario Teste' && ($prog['status'] ?? '') === 'na_fila');
$s = $guard->status(['role' => 'ADMIN']);
check('aviso da fila: cita a rotina, "iniciada por Usuario Teste" e a hora', str_contains((string) $s['aviso'], 'iniciada por Usuario Teste') && preg_match('/às \d{2}:\d{2}/', (string) $s['aviso']) === 1, (string) $s['aviso']);
check('aviso diz quando libera e, para ADMIN, como interromper', str_contains((string) $s['aviso'], 'liberada quando terminar') && str_contains((string) $s['aviso'], 'pode interromper'));
$sSup = $guard->status(['role' => 'SUPERVISOR']);
check('SUPERVISOR nao ve a dica de interromper', !str_contains((string) $sSup['aviso'], 'interromper a rotina'));
check('paginas donas inclui sync-consolidada.html', in_array('sync-consolidada.html', $s['paginas'], true));
check('"mensagem" curta (usada no HTTP 409) continua existindo', str_contains((string) $s['mensagem'], 'Aguarde terminar'));
$importCh = new ArrayObject();
$svc->runCycle('teste:G2');
$j = job($pdo, (int) $startRet['job']['id']);
$prog = json_decode((string) $j['progresso'], true);
check('iniciado_por preservado durante e apos o processamento', ($prog['iniciado_por'] ?? '') === 'Usuario Teste' && $j['estado'] === 'COMPLETED');
check('ao concluir o bloco exibido e o periodo inteiro (2026-10-01..2026-10-02)', ($prog['bloco_inicio'] ?? '') === '2026-10-01' && ($prog['bloco_fim'] ?? '') === '2026-10-02', ($prog['bloco_inicio'] ?? '') . '..' . ($prog['bloco_fim'] ?? ''));
$sOk = $guard->status(['role' => 'ADMIN']);
check('concluido: sem aviso e sem bloqueio', $sOk['aviso'] === null && $sOk['cargas_bloqueadas'] === false);

echo "\n== H. registro de falhas do worker\n";
$marca = 'teste-' . bin2hex(random_bytes(4));
WorkerLog::write('validar_consolidada', $marca);
check('WorkerLog grava linha com worker, pid e mensagem', is_file(WorkerLog::path()) && str_contains((string) file_get_contents(WorkerLog::path()), $marca));

echo "\n== I. padrao de 5 dias quando a variavel nao existe\n";
putenv('SYNC_JOB_BLOCK_DAYS');
$pdo->exec('TRUNCATE TABLE furk_sync_job');
[$svc, $chamadas] = servico($pdo, $env);
$repo->create('2026-10-01', '2026-10-12');
$svc->runCycle('teste:7');
$chamadas = $chamadas->getArrayCopy();
check('blocos 01..05, 06..10, 11..12 (padrao 5 dias)', ($chamadas[0] ?? '') === 'sales 2026-10-01..2026-10-05' && ($chamadas[4] ?? '') === 'sales 2026-10-06..2026-10-10' && ($chamadas[8] ?? '') === 'sales 2026-10-11..2026-10-12', ($chamadas[0] ?? '') . ' | ' . ($chamadas[4] ?? '') . ' | ' . ($chamadas[8] ?? ''));

echo "\n== J. ano digitado pela metade (0002) e datas anteriores a 2020 sao recusadas\n";
$pdo->exec('TRUNCATE TABLE furk_sync_job');
[$svc] = servico($pdo, $env);
$continuo = new FurkiCycle\Sync\SyncContinuoService($env, $pdo, new FurkiCycle\Sync\SyncContinuoRepository($pdo));
$pdo->exec('TRUNCATE TABLE furk_sync_continuo_job');
foreach (['0002-03-31', '0020-03-29', '2019-12-31'] as $dataRuim) {
    foreach (['Consolidada simples' => fn () => $svc->start($dataRuim, '2026-10-02', null), 'Consolidada Contínua' => fn () => $continuo->start($dataRuim, '2026-10-02', 5)] as $nome => $acao) {
        try {
            $acao();
            check("{$nome} recusa {$dataRuim}", false);
        } catch (RuntimeException $e) {
            check("{$nome} recusa {$dataRuim}", str_contains($e->getMessage(), '4 dígitos'), $e->getMessage());
        }
    }
}
check('nenhum job foi criado pelas datas recusadas', (int) $pdo->query('SELECT COUNT(*) FROM furk_sync_job')->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM furk_sync_continuo_job')->fetchColumn() === 0);
$ok = $continuo->start('2025-03-29', '2025-03-30', 5);
check('Consolidada Contínua aceita 29/03/2025 a 30/03/2025 (item do grid de releituras)', ($ok['job']['initial_date'] ?? '') === '2025-03-29' && ($ok['job']['final_date'] ?? '') === '2025-03-30');
$pdo->exec('TRUNCATE TABLE furk_sync_continuo_job');

$pdo->exec('TRUNCATE TABLE furk_sync_job');
echo PHP_EOL . ($fail === 0 ? 'TUDO OK' : "{$fail} FALHA(S)") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
