<?php
declare(strict_types=1);

/*
 * Valida o Deploy 2 (leituras manuais): registro (furk_carga_manual), Interromper, orçamento de tempo, leitura parcial de
 * vendas, limite de período e aviso detalhado. Usa um MySQL de TESTE (trava em tools/_teste_banco.php) e uma busca de
 * páginas FALSA (nunca chama a Furk). Faz TRUNCATE/DROP em furk_carga_manual e apaga as vendas "TST*" do BANCO DE TESTE.
 *
 * Uso: php tools/validar_leitura_manual.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/_teste_banco.php';

use FurkiCycle\Furk\OAuthClient;
use FurkiCycle\Furk\SalesClient;
use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Sales\SalesImportService;
use FurkiCycle\Sales\SalesRepository;
use FurkiCycle\Sync\ImportGuard;
use FurkiCycle\Sync\ManualLoadRegistry;
use FurkiCycle\Sync\ManualRun;

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

function criarTabela(PDO $pdo, string $root): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($root . '/database/013_furk_carga_manual.sql'));
    foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $comando) {
        $pdo->exec($comando);
    }
}

criarTabela($pdo, $root);
$pdo->exec('TRUNCATE TABLE furk_carga_manual');
$registry = new ManualLoadRegistry($pdo);

echo "== A. Registro: iniciar, andamento, parar, finalizar\n";
$id = $registry->begin('sales', 7, 'Usuario Teste', '2026-10-01', '2026-10-05');
check('begin() devolve um id', is_int($id) && $id > 0);
$ativa = $registry->active();
check('active() devolve a leitura (tela, usuário, período)', $ativa !== null && $ativa['tela'] === 'sales' && $ativa['usuario_nome'] === 'Usuario Teste' && $ativa['periodo_inicial'] === '2026-10-01');
$registry->touch($id, ['pagina' => 3, 'registros' => 300]);
check('touch() grava o andamento', str_contains((string) $registry->active()['progresso'], '"registros":300'));
check('stopRequested() é falso antes do pedido', $registry->stopRequested($id) === false);
$r = $registry->requestStop();
check('requestStop() em Sales marca STOP_REQUESTED', $r !== null && ($r['nao_interrompivel'] ?? false) === false && $registry->stopRequested($id) === true);
check('active() continua devolvendo a leitura enquanto interrompe', $registry->active() !== null && $registry->active()['estado'] === 'STOP_REQUESTED');
$registry->finish($id, 'STOPPED', ['total' => 300]);
check('finish() tira a leitura de "em andamento"', $registry->active() === null);

$idC = $registry->begin('creditos', 7, 'Usuario Teste', '2026-10-01', '2026-10-05');
$r = $registry->requestStop();
check('requestStop() em Créditos NÃO é permitido (apaga o período antes de regravar)', ($r['nao_interrompivel'] ?? false) === true && $registry->stopRequested($idC) === false);
$registry->finish($idC, 'DONE');
check('requestStop() sem leitura em andamento devolve null', $registry->requestStop() === null);

echo "\n== B. Linha órfã e limpeza\n";
$pdo->exec('TRUNCATE TABLE furk_carga_manual');
$pdo->exec("INSERT INTO furk_carga_manual (tela, estado, iniciado_em, heartbeat) VALUES ('sales', 'RUNNING', '" . date('Y-m-d H:i:s', time() - 900) . "', '" . date('Y-m-d H:i:s', time() - 600) . "')");
check('RUNNING sem batimento há 10 min NÃO conta como em andamento (requisição morreu)', $registry->active() === null);
$novo = $registry->begin('status', 1, 'X', '2026-10-01', '2026-10-02');
$antigo = $pdo->query('SELECT estado FROM furk_carga_manual ORDER BY id ASC LIMIT 1')->fetchColumn();
check('begin() marca a linha órfã como ERROR', $antigo === 'ERROR' && $novo !== null);
$pdo->exec("INSERT INTO furk_carga_manual (tela, estado, iniciado_em, heartbeat, finalizado_em) VALUES ('sales', 'DONE', '2026-09-01 10:00:00', '2026-09-01 10:01:00', '" . date('Y-m-d H:i:s', time() - 8 * 86400) . "')");
$registry->begin('status', 1, 'X', '2026-10-01', '2026-10-02');
$restantes = (int) $pdo->query("SELECT COUNT(*) FROM furk_carga_manual WHERE finalizado_em IS NOT NULL AND finalizado_em < '" . date('Y-m-d H:i:s', time() - 7 * 86400) . "'")->fetchColumn();
check('begin() apaga registros finalizados há mais de 7 dias', $restantes === 0);

echo "\n== C. Sem a tabela (migration 013 ainda não executada): tudo segue funcionando\n";
$pdo->exec('DROP TABLE furk_carga_manual');
$semTabela = new ManualLoadRegistry($pdo);
$erro = null;
try {
    $idN = $semTabela->begin('sales', 1, 'X', '2026-10-01', '2026-10-02');
    $semTabela->touch(1, ['a' => 1]);
    $parar = $semTabela->stopRequested(1);
    $semTabela->finish(1, 'DONE');
    $ativaN = $semTabela->active();
    $pedido = $semTabela->requestStop();
} catch (\Throwable $e) {
    $erro = $e->getMessage();
}
check('begin/touch/stop/finish/active/requestStop não lançam e devolvem "sem registro"', $erro === null && $idN === null && $parar === false && $ativaN === null && $pedido === null, (string) $erro);
$run = new ManualRun($semTabela, null, 45, 'sales');
check('ManualRun sem registro só aplica o orçamento de tempo (continua dentro dele)', $run->checkpoint(['pagina' => 1]) === null);
criarTabela($pdo, $root);

echo "\n== D. ManualRun: parada pedida × orçamento de tempo\n";
$pdo->exec('TRUNCATE TABLE furk_carga_manual');
$idR = $registry->begin('sales', 1, 'X', '2026-10-01', '2026-10-05');
$run = new ManualRun($registry, $idR, 45, 'sales');
check('dentro do orçamento e sem pedido: continua (null)', $run->checkpoint(['pagina' => 1, 'registros' => 100, 'ate' => '2026-10-01']) === null);
$estourado = new ManualRun($registry, $idR, 10, 'sales', microtime(true) - 11);
check('orçamento estourado: devolve "tempo"', $estourado->checkpoint(['pagina' => 2]) === 'tempo');
$registry->requestStop();
check('parada pedida: devolve "interrompido" (tem prioridade)', $estourado->checkpoint(['pagina' => 3]) === 'interrompido' && $run->checkpoint(['pagina' => 3]) === 'interrompido');
$registry->finish($idR, 'PARTIAL');

echo "\n== E. Vendas em páginas com conferência (importador falso, 2 vendas por página, 4 páginas)\n";
putenv('FURK_SALES_PAGE_SIZE=2');
putenv('FURK_SALES_DELAY_MS=0');
$pdo->exec("DELETE FROM furkapi_sales WHERE ticket_number LIKE 'TST%'");
$pagina = function (string $ini, string $fim, int $page, int $size): array {
    $dia = sprintf('2026-10-%02d', $page);
    $itens = [];
    foreach ([1, 2] as $n) {
        $itens[] = ['ticketID' => ['airlineCode' => 'XA', 'ticketNumber' => sprintf('TST%02d%02d', $page, $n), 'issueDate' => $dia], 'totalValue' => 10];
    }

    return ['data' => $itens, 'totalPages' => 4];
};
$novoServico = function () use ($env, $pdo, $pagina): SalesImportService {
    $s = new SalesImportService($env, $pdo, new SalesClient($env, new OAuthClient($env, 'X')), new SalesRepository($pdo));

    return $s->setPageFetcher($pagina);
};
$s = $novoServico()->import('2026-10-01', '2026-10-04');
check('sem conferência: lê as 4 páginas e não é parcial', $s['total'] === 8 && $s['pages'] === 4 && !isset($s['parcial']), json_encode([$s['total'], $s['pages']]));

$chamadas = [];
$s = $novoServico()->setCheckpoint(function (array $info) use (&$chamadas): ?string {
    $chamadas[] = $info;

    return $info['pagina'] === 2 ? 'tempo' : null;
})->import('2026-10-01', '2026-10-04');
check('orçamento esgotado na página 2: parcial, motivo "tempo", até 2026-10-02, 4 vendas', ($s['parcial'] ?? false) === true && $s['motivo'] === 'tempo' && $s['ate_data'] === '2026-10-02' && $s['total'] === 4 && $s['pages'] === 2, json_encode($s));
check('a conferência recebe página, registros e data', ($chamadas[0] ?? []) === ['pagina' => 1, 'registros' => 2, 'ate' => '2026-10-01']);

$s = $novoServico()->setCheckpoint(fn (array $info): ?string => $info['pagina'] === 1 ? 'interrompido' : null)->import('2026-10-01', '2026-10-04');
check('parada na página 1: parcial, motivo "interrompido", até 2026-10-01', ($s['parcial'] ?? false) === true && $s['motivo'] === 'interrompido' && $s['ate_data'] === '2026-10-01' && $s['total'] === 2);

$visto = 0;
$s = $novoServico()->setCheckpoint(function () use (&$visto): ?string {
    $visto++;

    return null;
})->import('2026-10-01', '2026-10-04');
check('a conferência NÃO roda na última página (3 chamadas para 4 páginas)', $visto === 3 && !isset($s['parcial']), (string) $visto);
$gravadas = (int) $pdo->query("SELECT COUNT(*) FROM furkapi_sales WHERE ticket_number LIKE 'TST%'")->fetchColumn();
check('o que foi lido ficou gravado (8 vendas de teste, sem duplicar nas releituras)', $gravadas === 8, (string) $gravadas);

// Parada pedida por OUTRA requisição (o ADMIN clicando em Interromper) enquanto a leitura roda.
$pdo->exec('TRUNCATE TABLE furk_carga_manual');
$idS = $registry->begin('sales', 1, 'X', '2026-10-01', '2026-10-04');
$runS = new ManualRun($registry, $idS, 45, 'sales');
$s = $novoServico()->setCheckpoint(function (array $info) use ($runS, $registry): ?string {
    if ($info['pagina'] === 1) {
        $registry->requestStop(); // simula o clique do ADMIN após a 1a página
    }

    return $runS->checkpoint($info);
})->import('2026-10-01', '2026-10-04');
check('Interromper do ADMIN durante a leitura: para ao fim da página seguinte, informa até onde foi', ($s['parcial'] ?? false) === true && $s['motivo'] === 'interrompido' && $s['pages'] === 1, json_encode([$s['pages'], $s['motivo'] ?? null]));
$runS->finish('PARTIAL', $s);
$pdo->exec("DELETE FROM furkapi_sales WHERE ticket_number LIKE 'TST%'");
putenv('FURK_SALES_PAGE_SIZE');
putenv('FURK_SALES_DELAY_MS');

echo "\n== F. ImportGuard: limite de período, registro e aviso\n";
$guard = new ImportGuard($env);
putenv('MANUAL_LOAD_MAX_DAYS=90');
$msg = null;
try {
    $guard->assertPeriodoManual('2026-07-01', '2026-09-28'); // 90 dias
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
}
check('90 dias é permitido', $msg === null);
try {
    $guard->assertPeriodoManual('2026-07-01', '2026-09-29'); // 91 dias
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
}
check('91 dias é recusado com a orientação (Consolidada / Consolidada Contínua)', $msg !== null && str_contains($msg, '91 dias') && str_contains($msg, 'Consolidada'), (string) $msg);
putenv('MANUAL_LOAD_MAX_DAYS');

$pdo->exec('TRUNCATE TABLE furk_carga_manual');
$pdo->exec('DELETE FROM users WHERE id = 99');
try {
    $pdo->exec("INSERT INTO users (id, name, email, password_hash, role, is_active) VALUES (99, 'Teste Silva', 'teste.silva@example.test', 'x', 'ADMIN', 1)");
} catch (\Throwable) {
    // esquema mínimo de teste pode variar: sem nome, o aviso diz só "iniciada às HH:MM"
}
$prefixo = $env->required('LOCK_PREFIX');
$holder->prepare('SELECT GET_LOCK(:n, 0)')->execute(['n' => $prefixo . 'furk_carga_manual']);
$run = $guard->startManualRun('sales', ['sub' => '99', 'role' => 'ADMIN'], '2026-10-01', '2026-10-26');
$run->checkpoint(['pagina' => 12, 'registros' => 1200, 'ate' => '2026-10-09']);
$s = $guard->status(['role' => 'ADMIN']);
check('aviso: leitura manual de Vendas, período, registros até agora', str_contains((string) $s['aviso'], 'leitura manual de Vendas') && str_contains((string) $s['aviso'], '01/10 a 26/10') && str_contains((string) $s['aviso'], '1200 registros'), (string) $s['aviso']);
check('aviso: iniciada às HH:MM (e por quem, quando o nome existe)', preg_match('/iniciada (por Teste Silva )?às \d{2}:\d{2}/', (string) $s['aviso']) === 1);
check('leitura_manual.interrompivel = true para Vendas (RUNNING)', ($s['leitura_manual']['interrompivel'] ?? false) === true && $s['leitura_manual']['tela'] === 'sales');
check('telas "donas" NÃO incluem as telas de leitura manual (precisam da faixa)', !in_array('sales-leitura.html', $s['paginas'], true));
$run->finish('DONE');
$s2 = $guard->status(['role' => 'ADMIN']);
check('com o lock ainda ocupado e sem registro ativo, o aviso cai no texto genérico', str_contains((string) $s2['aviso'], 'Há uma leitura manual em andamento.'));
$holder->prepare('SELECT RELEASE_LOCK(:n)')->execute(['n' => $prefixo . 'furk_carga_manual']);

$holder->prepare('SELECT GET_LOCK(:n, 0)')->execute(['n' => $prefixo . 'furk_carga_manual']);
$runC = $guard->startManualRun('creditos', ['sub' => '99', 'role' => 'ADMIN'], '2026-10-01', '2026-10-05');
$s3 = $guard->status(['role' => 'ADMIN']);
check('Créditos/Reembolsos: aviso diz o que é, mas NÃO oferece Interromper', str_contains((string) $s3['aviso'], 'leitura manual de Créditos') && ($s3['leitura_manual']['interrompivel'] ?? true) === false);
$runC->finish('DONE');
$holder->prepare('SELECT RELEASE_LOCK(:n)')->execute(['n' => $prefixo . 'furk_carga_manual']);
$pdo->exec('DELETE FROM users WHERE id = 99');

$pdo->exec('TRUNCATE TABLE furk_carga_manual');
echo PHP_EOL . ($fail === 0 ? 'TUDO OK' : "{$fail} FALHA(S)") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
