<?php
declare(strict_types=1);

/*
 * Validacao do acesso ao GATE (SQL Server) e das correcoes do RebookingGateRepository.
 * SOMENTE LEITURA e SOMENTE CONTAGENS: nao imprime dados de passageiros (LGPD).
 *
 * Uso:
 *   php tools/validar_gate.php                 -> so a parte offline (formato do SQL), sem rede
 *   php tools/validar_gate.php online          -> diagnostico de rede + consultas de contagem no GATE
 *   php tools/validar_gate.php online C:\xampp\htdocs\furk-cycle-novo   -> outra raiz do projeto
 *
 * Le o .env da raiz indicada (GATE_DB_*). Nao imprime host, usuario nem senha.
 * Esta pasta tools/ NAO vai no deploy.zip.
 */

$mode = $argv[1] ?? 'offline';
$root = rtrim($argv[2] ?? dirname(__DIR__), "/\\");
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "vendor/autoload.php nao encontrado em {$root}\n");
    exit(2);
}
require $root . '/vendor/autoload.php';

use FurkiCycle\Config\Env;
use FurkiCycle\Rebooking\RebookingGateRepository;

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? 'OK    ' : 'FALHA ') . $name . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

$env = new Env($root . '/.env');
$repo = new RebookingGateRepository($env);

// ---------- offline: forma do SQL ----------
$m = new ReflectionMethod($repo, 'whereClause');
$m->setAccessible(true);
[$where, $params] = $m->invoke($repo, 'A_B%[x]', ['1', '2'], true);
check("LIKE com ESCAPE '!' em todas as 9 colunas pesquisaveis", substr_count($where, "ESCAPE '!'") === 9);
check("sem barra invertida no SQL (o PDO a trata como escape e quebra os parametros: HY093)", !str_contains($where, '\\'));
check('curingas escapados no parametro (A!_B!%![x])', $params['q0'] === '%A!_B!%![x]%');
check('NOT IN montado com parametros nomeados e tipados VARCHAR', str_contains($where, 'nota NOT IN (CAST(:n0 AS VARCHAR(50)), CAST(:n1 AS VARCHAR(50)))'));
check('LIKE com parametro tipado VARCHAR (o indice do GATE e VARCHAR)', substr_count($where, 'LIKE CAST(:q') === 9);

if ($mode !== 'online') {
    echo PHP_EOL . ($fail === 0 ? 'OFFLINE OK (use "online" com acesso ao GATE).' : "{$fail} FALHA(S)") . PHP_EOL;
    exit($fail === 0 ? 0 : 1);
}

// ---------- online: diagnostico de rede (sem imprimir host/credenciais) ----------
$host = $env->optional('GATE_DB_HOST');
$port = (int) ($env->optional('GATE_DB_PORT', '1433') ?? '1433');
echo PHP_EOL . '--- Diagnostico de rede ate o GATE ---' . PHP_EOL;
if ($host === null) {
    check('GATE_DB_HOST configurado no .env', false);
    exit(1);
}
$errno = 0;
$errstr = '';
$t0 = microtime(true);
$socket = @fsockopen($host, $port, $errno, $errstr, 8);
$elapsed = microtime(true) - $t0;
if ($socket === false) {
    echo sprintf("FALHA TCP na porta %d (%.1fs): [%d] %s", $port, $elapsed, $errno, $errstr) . PHP_EOL;
    echo 'Isto e problema de ROTA/FIREWALL/VPN (a conexao nem chega ao SQL Server), nao de usuario/senha.' . PHP_EOL;
    echo 'Leve esta saida a TI. Teste equivalente: Test-NetConnection <GATE_DB_HOST> -Port ' . $port . PHP_EOL;
    exit(1);
}
fclose($socket);
check(sprintf('TCP na porta %d alcancavel (%.1fs)', $port, $elapsed), true);

// ---------- online: consultas (somente contagem) ----------
echo PHP_EOL . '--- Consultas ao GATE (somente contagens) ---' . PHP_EOL;
try {
    $total = $repo->count('');
    check("conexao + COUNT(*) da vista dbo.Rebooking (total={$total})", $total >= 0);
    $a = $repo->count('_');
    $b = $repo->count('%');
    $c = $repo->count('[');
    echo "  busca '_' => {$a} | '%' => {$b} | '[' => {$c}" . PHP_EOL;
    echo "  (esperado: valores pequenos/zero se nenhum campo contem o caractere; o importante e NAO dar erro de SQL.)" . PHP_EOL;
    check("buscas com curinga literal executaram sem erro de SQL (ESCAPE valido no SQL Server)", true);
    check('conexao reutilizada na mesma requisicao', $repo->connect() === $repo->connect());
    $g = $repo->gatePage(1, 1, '');
    check('gatePage devolve colunas estendidas (> 30)', count($g['rows'][0] ?? []) > 30);
    $p = $repo->page(1, 1, '', null, false);
    check('page devolve as 23 colunas base', count($p['rows'][0] ?? []) === 23);
} catch (Throwable $e) {
    check('consultas ao GATE: ' . get_class($e) . ' - ' . $e->getMessage(), false);
}

echo PHP_EOL . ($fail === 0 ? 'GATE OK' : "{$fail} FALHA(S)") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
