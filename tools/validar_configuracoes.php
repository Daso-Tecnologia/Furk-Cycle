<?php
declare(strict_types=1);

/*
 * Valida as regras editáveis do Rebooking (RebookingGateFilter + SettingsRepository).
 *  - Parte A (offline): validação/normalização da entrada e leitura tolerante.
 *  - Parte B (banco de TESTE, trava em tools/_teste_banco.php): grava, histórico, "só registra o que mudou",
 *    padrões quando a tabela não existe. Pulada se não houver .env.test.
 *
 * Uso: php tools/validar_configuracoes.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/_teste_banco.php';

use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Settings\RebookingGateFilter;
use FurkiCycle\Settings\SettingsRepository;

$fail = 0;
function check(string $nome, bool $ok, string $detalhe = ''): void
{
    global $fail;
    echo ($ok ? 'OK    ' : 'FALHA ') . $nome . ($detalhe !== '' ? " - {$detalhe}" : '') . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}
function rejeita(array $entrada): bool
{
    try {
        RebookingGateFilter::fromInput($entrada);
    } catch (RuntimeException) {
        return true;
    }

    return false;
}

$base = ['diasJanela' => 10, 'clientes' => '055763', 'fornecedores' => 'AD', 'produtos' => '02', 'formasNota' => 'B', 'cadastros' => 'INTERFTMS'];

echo "== Parte A: filtro (offline) ==\n";
check('padrões = filtros originais da view', RebookingGateFilter::defaults()->toArray() === [
    'diasJanela' => 10, 'clientes' => ['055763'], 'fornecedores' => ['AD'], 'produtos' => ['02'], 'formasNota' => ['B'], 'cadastros' => ['INTERFTMS'],
]);
check('entrada padrão == defaults', RebookingGateFilter::fromInput($base)->toArray() === RebookingGateFilter::defaults()->toArray());
$lista = RebookingGateFilter::fromInput(['clientes' => " 055763 ,ab;  ab\n cd"] + $base);
check('lista: separadores, caixa alta e duplicados', $lista->clientes === ['055763', 'AB', 'CD']);
check('lista como array', RebookingGateFilter::fromInput(['fornecedores' => ['ad', 'g3']] + $base)->fornecedores === ['AD', 'G3']);
check('dias: 1 e 180 aceitos', !rejeita(['diasJanela' => 1] + $base) && !rejeita(['diasJanela' => '180'] + $base));
foreach ([0, 181, -5, 'abc', '', null, '10.5', '1e2', [10]] as $ruim) {
    check('dias inválido rejeitado: ' . json_encode($ruim), rejeita(['diasJanela' => $ruim] + $base));
}
check('lista vazia rejeitada', rejeita(['clientes' => '  '] + $base) && rejeita(['produtos' => []] + $base));
foreach (["AD'; DROP TABLE x--", 'A D%', 'AD)', str_repeat('A', 21), 'AÇ'] as $ruim) {
    check('código perigoso rejeitado: ' . substr($ruim, 0, 18), rejeita(['fornecedores' => $ruim] + $base));
}
check('mais de 20 códigos rejeitado', rejeita(['clientes' => implode(',', range(1, 21))] + $base));
check('item não-texto na lista rejeitado', rejeita(['clientes' => [['x']]] + $base));
$armazenado = RebookingGateFilter::fromStorage([
    RebookingGateFilter::KEY_DIAS => '999', RebookingGateFilter::KEY_CLIENTES => '', RebookingGateFilter::KEY_FORNECEDORES => 'G3',
]);
check('armazenamento inválido volta ao padrão, válido é usado',
    $armazenado->diasJanela === 10 && $armazenado->clientes === ['055763'] && $armazenado->fornecedores === ['G3']);
check('toStorage/fromStorage ida e volta', RebookingGateFilter::fromStorage($lista->toStorage())->toArray() === $lista->toArray());

echo "\n== Parte B: banco de TESTE ==\n";
if (!is_file($root . '/.env.test')) {
    echo "PULADA (sem .env.test)\n";
} else {
    [$envTeste, $banco] = carregarEnvTeste($root);
    echo "Banco de teste: {$banco}\n";
    $pdo = MySqlConnection::create($envTeste);

    $repo = new SettingsRepository($envTeste);
    $pdo->exec('DROP TABLE IF EXISTS furk_configuracao_historico');
    $pdo->exec('DROP TABLE IF EXISTS furk_configuracao');
    check('sem tabela: devolve padrões', $repo->rebookingGateFilter()->toArray() === RebookingGateFilter::defaults()->toArray());
    check('sem tabela: histórico vazio', $repo->history() === []);

    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($root . '/database/012_furk_configuracao.sql'));
    foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $comando) {
        $pdo->exec($comando);
    }

    check('tabelas criadas: padrões', $repo->rebookingGateFilter()->toArray() === RebookingGateFilter::defaults()->toArray());
    check('migration é idempotente (2ª execução sem erro)', (function () use ($pdo, $sql): bool {
        try {
            foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $comando) {
                $pdo->exec($comando);
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    })());

    $novo = RebookingGateFilter::fromInput(['diasJanela' => 30, 'fornecedores' => 'AD, G3'] + $base);
    $mudancas = $repo->saveRebookingGateFilter($novo, 1);
    check('salvar: 2 chaves alteradas (dias e fornecedores)', count($mudancas) === 2, json_encode(array_column($mudancas, 'chave')));
    check('ler após salvar', $repo->rebookingGateFilter()->toArray() === $novo->toArray());
    check('salvar igual: nenhuma alteração', $repo->saveRebookingGateFilter($novo, 1) === []);
    $hist = $repo->history();
    check('histórico: 2 linhas com de/para', count($hist) === 2
        && in_array('10', array_column($hist, 'valor_anterior'), true) && in_array('30', array_column($hist, 'valor_novo'), true));

    $repo->saveRebookingGateFilter(RebookingGateFilter::defaults(), 1);
    check('restaurar padrão grava histórico e volta aos padrões',
        $repo->rebookingGateFilter()->toArray() === RebookingGateFilter::defaults()->toArray() && count($repo->history()) === 4);

    // Edição manual inválida no banco não derruba o sistema.
    $pdo->exec("UPDATE furk_configuracao SET valor = 'lixo' WHERE chave = 'rebooking.gate.dias_janela'");
    check('valor corrompido no banco: usa o padrão', $repo->rebookingGateFilter()->diasJanela === 10);

    // Limpa só o que este teste criou.
    $pdo->exec('DROP TABLE IF EXISTS furk_configuracao_historico');
    $pdo->exec('DROP TABLE IF EXISTS furk_configuracao');
}

echo PHP_EOL . ($fail === 0 ? 'TUDO OK' : "{$fail} FALHA(S)") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
