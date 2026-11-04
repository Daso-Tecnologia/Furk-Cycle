<?php
declare(strict_types=1);

/*
 * Desempenho E equivalência dos métodos do RebookingGateRepository contra o GATE real.
 * SOMENTE LEITURA; imprime apenas tempos e contagens (nenhum dado de passageiro).
 *
 * Contexto (26/10/2026): parâmetros enviados como NVARCHAR contra colunas VARCHAR faziam o SQL Server
 * converter a COLUNA e varrer tabelas de milhões de linhas (itinerário 10,7 s; consulta por nota 5 s).
 * Os métodos agora usam CAST(... AS VARCHAR). Este teste mede e confere que o resultado é o mesmo.
 *
 * Uso: php tools/validar_gate_desempenho.php [C:\caminho\do\projeto]
 */

$root = rtrim($argv[1] ?? dirname(__DIR__), "/\\");
require $root . '/vendor/autoload.php';

use FurkiCycle\Config\Env;
use FurkiCycle\Rebooking\RebookingGateRepository;

$repo = new RebookingGateRepository(new Env($root . '/.env'));
$fail = 0;

function check(string $nome, bool $ok, string $detalhe = ''): void
{
    global $fail;
    echo ($ok ? 'OK    ' : 'FALHA ') . $nome . ($detalhe !== '' ? " — {$detalhe}" : '') . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

function cronometrar(callable $fn): array
{
    $ini = microtime(true);
    $resultado = $fn();

    return [$resultado, (int) round((microtime(true) - $ini) * 1000)];
}

try {
    $pdo = $repo->connect();
} catch (Throwable) {
    fwrite(STDERR, "GATE inacessível (VPN?).\n");
    exit(2);
}

$onde = "WHERE v.data_nota >= DATEADD(DAY, -10, GETDATE()) AND v.cancel <> '1' AND v.produto = '02' AND f.cod_for = 'AD' AND v.forma_nota = 'B' AND v.cod_cad = 'INTERFTMS' AND v.cod_cli = '055763'";
$amostra = $pdo->query("SELECT TOP 1 v.fil_agt, v.nota, v.num_file FROM wg_ven v WITH (NOLOCK) LEFT JOIN wgfor f WITH (NOLOCK) ON v.cod_prest = f.cod_for {$onde}")->fetch();
if (!is_array($amostra)) {
    echo "Sem notas na janela atual do GATE para testar.\n";
    exit(0);
}
$fil = trim((string) $amostra['fil_agt']);
$nota = trim((string) $amostra['nota']);
$file = $amostra['num_file'] === null ? null : trim((string) $amostra['num_file']);

echo "== Métodos do sistema (tempo e equivalência)\n";

[$linha, $ms] = cronometrar(fn () => $repo->findByNota($nota));
check('findByNota() devolve a nota certa', is_array($linha) && trim((string) ($linha['nota'] ?? '')) === $nota, "{$ms} ms");
check('findByNota() < 8 s (antes ~6 s com NVARCHAR; a view ainda tem JOINs pesados)', $ms < 8000);

[$linha2, $ms] = cronometrar(fn () => $repo->rowByNota($fil, $nota, $file));
check('rowByNota() devolve a mesma nota', is_array($linha2) && trim((string) ($linha2['nota'] ?? '')) === $nota, "{$ms} ms");

[$segmentos, $ms] = cronometrar(fn () => $repo->itinerary($fil, $nota, $file));
$referencia = (int) $pdo->query(
    "SELECT COUNT(DISTINCT num_trecho) FROM wgitiner WITH (NOLOCK) WHERE fil_agt = '" . str_replace("'", "''", $fil)
    . "' AND nota = '" . str_replace("'", "''", $nota) . "'" . ($file === null ? '' : " AND num_file = '" . str_replace("'", "''", $file) . "'"),
)->fetchColumn();
check('itinerary(): mesma quantidade de trechos que a consulta de referência', count($segmentos) === $referencia, count($segmentos) . " vs {$referencia}; {$ms} ms");
check('itinerary() < 2 s (antes ~10,7 s)', $ms < 2000);

[$segmentosSemFile, $ms] = cronometrar(fn () => $repo->itinerary($fil, $nota, null));
check('itinerary() sem num_file também funciona', count($segmentosSemFile) >= count($segmentos), count($segmentosSemFile) . " trecho(s); {$ms} ms");

[$total, $ms] = cronometrar(fn () => $repo->count(''));
echo "      count('') = {$total}  ({$ms} ms)\n";
[$buscaA, $ms] = cronometrar(fn () => $repo->count('a'));
echo "      count('a') = {$buscaA}  ({$ms} ms)\n";
check("busca com curinga literal ('_') executa sem erro", $repo->count('_') >= 0);
check("busca com acento ('josé') executa sem erro", $repo->count('josé') >= 0);

[$pagina, $ms] = cronometrar(fn () => $repo->page(1, 50, '', null, false));
check('page(1,50): total coerente com count()', $pagina['total'] === $total, "{$ms} ms; linhas=" . count($pagina['rows']));

[$semAmostra, $ms] = cronometrar(fn () => $repo->page(1, 50, '', [$nota], true));
check('page() com NOT IN tipado exclui a nota da amostra', $semAmostra['total'] === $total - 1, "{$ms} ms; total={$semAmostra['total']}");

[$soAmostra, $ms] = cronometrar(fn () => $repo->page(1, 50, '', [$nota], false));
check('page() com IN tipado devolve só a nota da amostra', $soAmostra['total'] === 1, "{$ms} ms");

$g = $repo->gatePage(1, 5, '');
check('gatePage() (colunas estendidas) continua devolvendo > 30 colunas', count($g['rows'][0] ?? []) > 30);

echo PHP_EOL . ($fail === 0 ? 'GATE: DESEMPENHO E EQUIVALÊNCIA OK' : "{$fail} FALHA(S)") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
