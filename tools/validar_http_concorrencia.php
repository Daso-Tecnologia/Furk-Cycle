<?php
declare(strict_types=1);

/*
 * Teste HTTP da Fase 1 de concorrência: sobe o servidor embutido do PHP (127.0.0.1) com as variáveis
 * apontando para o MySQL de TESTE e exercita os endpoints reais (409/403/200/400).
 *
 * Segurança: o processo filho recebe as variáveis MYSQL_*, JWT_*, LOCK_PREFIX do .env.test (que
 * têm prioridade sobre qualquer .env) e as credenciais Furk/GATE são SOBRESCRITAS por "DESABILITADO",
 * então nenhuma chamada externa real é possível. Faz TRUNCATE nas tabelas de estado do BANCO DE TESTE.
 *
 * Uso: php tools/validar_http_concorrencia.php [C:\caminho\.env.test]
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/_teste_banco.php';

use Firebase\JWT\JWT;
use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Sync\ImportGuard;

[$env, $banco] = carregarEnvTeste($root, $argv[1] ?? null);
echo "Banco de TESTE: {$banco}\n\n";

$porta = 8102;
$base = "http://127.0.0.1:{$porta}";
$prefixoLock = $env->required('LOCK_PREFIX');
$segredo = $env->required('JWT_SECRET');
$emissor = $env->required('JWT_ISSUER');

// ---- ambiente do servidor filho ----
$ambiente = getenv();
foreach (['MYSQL_HOST', 'MYSQL_PORT', 'MYSQL_DATABASE', 'MYSQL_USERNAME', 'MYSQL_PASSWORD', 'MYSQL_CHARSET', 'LOCK_PREFIX', 'JWT_SECRET', 'JWT_ISSUER', 'APP_TIMEZONE'] as $chave) {
    $valor = $env->optional($chave);
    if ($valor !== null) {
        $ambiente[$chave] = $valor;
    }
}
foreach ([
    'FURK_CLIENT_ID', 'FURK_CLIENT_SECRET', 'FURK_API_KEY', 'FURK_REBOOKING_CLIENT_ID', 'FURK_REBOOKING_CLIENT_SECRET',
    'FURK_REBOOKING_API_KEY', 'FURK_REFUND_CLIENT_ID', 'FURK_REFUND_CLIENT_SECRET', 'FURK_REFUND_API_KEY',
    'FURK_WEBHOOK_IDENTIFICATION_TOKEN', 'GATE_DB_HOST', 'GATE_DB_USER', 'GATE_DB_PASSWORD',
] as $chave) {
    $ambiente[$chave] = 'DESABILITADO';
}
$ambiente['APP_DEBUG'] = 'false';

$pdo = MySqlConnection::create($env);
$holder = MySqlConnection::create($env);
$fail = 0;

function check(string $nome, bool $ok, string $detalhe = ''): void
{
    global $fail;
    echo ($ok ? 'OK    ' : 'FALHA ') . $nome . ($ok || $detalhe === '' ? '' : " — {$detalhe}") . PHP_EOL;
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
        $pdo->prepare("INSERT INTO {$tabela} (initial_date, final_date, cursor_date, estado) VALUES ('2026-01-01', '2026-01-31', '2026-01-01', :e)")->execute(['e' => $estado]);
        return;
    }
    $pdo->prepare("INSERT INTO {$tabela} (estado) VALUES (:e)")->execute(['e' => $estado]);
}

function token(string $perfil, string $segredo, string $emissor): string
{
    return JWT::encode(['sub' => '1', 'role' => $perfil, 'iss' => $emissor, 'exp' => time() + 600], $segredo, 'HS256');
}

/** @return array{0: int, 1: array<string, mixed>} */
function http(string $metodo, string $url, ?string $token, ?array $corpo = null): array
{
    $ch = curl_init($url);
    $cabecalhos = ['Content-Type: application/json'];
    if ($token !== null) {
        $cabecalhos[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => $cabecalhos,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_THROW_ON_ERROR));
    }
    $resposta = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($resposta, true);

    return [$status, is_array($json) ? $json : []];
}

// ---- sobe o servidor ----
$processo = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$porta}", '-t', $root . '/public', $root . '/public/router.php'],
    [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
    $pipes,
    $root,
    $ambiente,
);
if (!is_resource($processo)) {
    fwrite(STDERR, "Não foi possível subir o servidor de teste.\n");
    exit(2);
}
$pronto = false;
for ($i = 0; $i < 40 && !$pronto; $i++) {
    usleep(250000);
    [$s] = http('GET', $base . '/status', null);
    $pronto = $s === 200;
}
check('servidor de teste no ar (127.0.0.1:' . $porta . ')', $pronto);
if (!$pronto) {
    proc_terminate($processo);
    exit(1);
}

$admin = token('ADMIN', $segredo, $emissor);
$super = token('SUPERVISOR', $segredo, $emissor);
$msg = ImportGuard::MESSAGE_RECURSIVA;
$msgSup = $msg . ImportGuard::MESSAGE_SOLICITE_ADMIN;

try {
    // ------------------------------------------------------------ A
    echo "\n== A. Recursiva RUNNING\n";
    limpar($pdo);
    estado($pdo, 'furk_sync_rotina', 'RUNNING');

    foreach ([
        'Créditos (leitura manual)' => ['/api/cycle/credits/sync.php', []],
        'Reembolsos (leitura manual)' => ['/api/cycle/refunds/sync.php', []],
        'Sales Leitura (leitura manual)' => ['/api/cycle/sales/sync.php', []],
        'Status Leitura (leitura manual)' => ['/api/cycle/dynamic-status/import.php', []],
        'Status Rotina: iniciar' => ['/api/cycle/dynamic-status/job.php', ['action' => 'start']],
        'Sales Rotina: iniciar' => ['/api/cycle/sales/job.php', ['action' => 'start']],
        'Consolidada: iniciar' => ['/api/cycle/sync/job.php', ['action' => 'start', 'initialDate' => '2026-10-01', 'finalDate' => '2026-10-02']],
        'Consolidada Contínua: iniciar' => ['/api/cycle/sync/continuo.php', ['action' => 'start', 'initialDate' => '2026-10-01', 'finalDate' => '2026-10-10']],
        'Consolidada Contínua: retomar' => ['/api/cycle/sync/continuo.php', ['action' => 'resume']],
        'Consolidada Contínua: reprocessar falhos' => ['/api/cycle/sync/continuo.php', ['action' => 'retryFailed']],
    ] as $nome => [$caminho, $corpo]) {
        [$status, $json] = http('POST', $base . $caminho, $admin, $corpo);
        check("ADMIN — {$nome} → 409 com a mensagem simples", $status === 409 && ($json['message'] ?? '') === $msg, "HTTP {$status}");
    }
    [$status, $json] = http('POST', $base . '/api/cycle/credits/sync.php', $super, []);
    check('SUPERVISOR — Créditos → 409 + "solicite a um administrador"', $status === 409 && ($json['message'] ?? '') === $msgSup, "HTTP {$status}");

    [$status, $json] = http('GET', $base . '/api/cycle/operacoes/status.php', $super);
    check('GET operacoes/status (qualquer perfil) → 200, bloqueadas, mensagem do supervisor',
        $status === 200 && ($json['operacoes']['cargas_bloqueadas'] ?? false) === true && ($json['operacoes']['recursiva']['ativa'] ?? false) === true && ($json['operacoes']['mensagem'] ?? '') === $msgSup,
        "HTTP {$status}");

    // opções que DEVEM continuar livres com a Recursiva ativa
    [$status] = http('POST', $base . '/api/cycle/webhook/events.php', $super, []);
    check('Webhook (eventos) LIVRE com a Recursiva ativa → 200', $status === 200, "HTTP {$status}");
    [$status] = http('POST', $base . '/api/cycle/rebooking/control.php', $super, []);
    check('Rebooking (grid de controle) LIVRE com a Recursiva ativa → 200', $status === 200, "HTTP {$status}");
    [$status] = http('POST', $base . '/api/admin/users/list.php', $super, []);
    check('Usuários (listar) LIVRE → 200', $status === 200, "HTTP {$status}");

    // interromper / ver status das rotinas NÃO são bloqueados
    [$status] = http('GET', $base . '/api/cycle/dynamic-status/job.php', $admin);
    check('Status Rotina: ver status LIVRE → 200', $status === 200, "HTTP {$status}");
    [$status] = http('POST', $base . '/api/cycle/dynamic-status/job.php', $admin, ['action' => 'stop']);
    check('Status Rotina: interromper LIVRE → 200', $status === 200, "HTTP {$status}");

    // ------------------------------------------------------------ B
    echo "\n== B. Só ADMIN mexe na Recursiva\n";
    limpar($pdo);
    [$status, $json] = http('POST', $base . '/api/cycle/sync/rotina.php', $super, ['action' => 'configure', 'frequenciaHoras' => 2, 'diasRecuo' => 4]);
    check('SUPERVISOR — configurar → 403', $status === 403 && str_contains((string) ($json['message'] ?? ''), 'Somente administradores'), "HTTP {$status}");
    [$status] = http('POST', $base . '/api/cycle/sync/rotina.php', $super, ['action' => 'start']);
    check('SUPERVISOR — iniciar → 403', $status === 403, "HTTP {$status}");
    [$status] = http('POST', $base . '/api/cycle/sync/rotina.php', $super, ['action' => 'stop']);
    check('SUPERVISOR — interromper → 403', $status === 403, "HTTP {$status}");
    [$status] = http('GET', $base . '/api/cycle/sync/rotina.php', $super);
    check('SUPERVISOR — ver status continua permitido → 200', $status === 200, "HTTP {$status}");
    [$status] = http('POST', $base . '/api/cycle/sync/rotina.php', $admin, ['action' => 'configure', 'frequenciaHoras' => 2, 'diasRecuo' => 4]);
    check('ADMIN — configurar → 200', $status === 200, "HTTP {$status}");
    [$status, $json] = http('POST', $base . '/api/cycle/sync/rotina.php', $admin, ['action' => 'start']);
    check('ADMIN — iniciar (nada em andamento) → 200 e RUNNING', $status === 200 && ($json['job']['estado'] ?? '') === 'RUNNING', "HTTP {$status}");
    [$status, $json] = http('POST', $base . '/api/cycle/sync/rotina.php', $admin, ['action' => 'stop']);
    check('ADMIN — interromper → 200 e STOP_REQUESTED', $status === 200 && ($json['job']['estado'] ?? '') === 'STOP_REQUESTED', "HTTP {$status}");

    // ------------------------------------------------------------ C
    echo "\n== C. Recursiva parada: as cargas passam pela regra\n";
    limpar($pdo);
    estado($pdo, 'furk_sync_rotina', 'IDLE');
    foreach ([
        'Créditos' => '/api/cycle/credits/sync.php',
        'Reembolsos' => '/api/cycle/refunds/sync.php',
        'Sales Leitura' => '/api/cycle/sales/sync.php',
        'Status Leitura' => '/api/cycle/dynamic-status/import.php',
    ] as $nome => $caminho) {
        [$status, $json] = http('POST', $base . $caminho, $admin, []);
        check("{$nome}: passa pela regra (e para na validação das datas, sem chamar a Furk) → 400", $status === 400 && str_contains((string) ($json['message'] ?? ''), 'data'), "HTTP {$status}");
    }

    // ------------------------------------------------------------ D
    echo "\n== D. Duas leituras manuais ao mesmo tempo (via HTTP)\n";
    limpar($pdo);
    $holder->prepare('SELECT GET_LOCK(:n, 0)')->execute(['n' => $prefixoLock . 'furk_carga_manual']);
    [$status, $json] = http('POST', $base . '/api/cycle/credits/sync.php', $admin, []);
    check('com outra leitura manual em andamento → 409 citando "Leitura manual"', $status === 409 && str_contains((string) ($json['message'] ?? ''), 'Leitura manual'), "HTTP {$status}");
    [$status, $json] = http('POST', $base . '/api/cycle/sync/rotina.php', $admin, ['action' => 'start']);
    check('iniciar a Recursiva com leitura manual em andamento → 409', $status === 409 && str_contains((string) ($json['message'] ?? ''), 'Leitura manual'), "HTTP {$status}");
    $holder->prepare('SELECT RELEASE_LOCK(:n)')->execute(['n' => $prefixoLock . 'furk_carga_manual']);
    [$status] = http('POST', $base . '/api/cycle/credits/sync.php', $admin, []);
    check('liberada a leitura manual → volta a passar pela regra (400 de validação)', $status === 400, "HTTP {$status}");

    // ------------------------------------------------------------ E
    echo "\n== E. Outra rotina RUNNING impede iniciar a Recursiva\n";
    limpar($pdo);
    estado($pdo, 'furk_sales_job', 'RUNNING');
    [$status, $json] = http('POST', $base . '/api/cycle/sync/rotina.php', $admin, ['action' => 'configure', 'frequenciaHoras' => 2, 'diasRecuo' => 4]);
    [$status, $json] = http('POST', $base . '/api/cycle/sync/rotina.php', $admin, ['action' => 'start']);
    check('Sales Rotina RUNNING → iniciar a Recursiva = 409 citando Sales', $status === 409 && str_contains((string) ($json['message'] ?? ''), 'Sales'), "HTTP {$status}");

    // ------------------------------------------------------------ G
    echo "\n== G. Consolidada: Interromper (somente ADMIN)\n";
    limpar($pdo);
    $pdo->exec("INSERT INTO furk_sync_job (initial_date, final_date, estado, heartbeat) VALUES ('2026-10-01', '2026-10-10', 'RUNNING', NOW())");
    [$status, $json] = http('POST', $base . '/api/cycle/sync/job.php', $super, ['action' => 'stop']);
    check('SUPERVISOR — interromper a Consolidada → 403', $status === 403, "HTTP {$status}");
    [$status] = http('POST', $base . '/api/cycle/sync/job.php', null, ['action' => 'stop']);
    check('sem token — interromper a Consolidada → 401', $status === 401, "HTTP {$status}");
    $estadoAntes = (string) $pdo->query('SELECT estado FROM furk_sync_job ORDER BY id DESC LIMIT 1')->fetchColumn();
    check('as recusas não alteraram o job (continua RUNNING)', $estadoAntes === 'RUNNING', $estadoAntes);
    [$status, $json] = http('POST', $base . '/api/cycle/sync/job.php', $admin, ['action' => 'stop']);
    check('ADMIN — interromper a Consolidada → 200 e STOP_REQUESTED', $status === 200 && ($json['job']['estado'] ?? '') === 'STOP_REQUESTED', "HTTP {$status}");
    [$status, $json] = http('POST', $base . '/api/cycle/sync/job.php', $admin, ['action' => 'stop']);
    check('ADMIN — interromper sem job em andamento → 400 com mensagem clara', $status === 400 && str_contains((string) ($json['message'] ?? ''), 'Não há sincronização'), "HTTP {$status}");
    [$status, $json] = http('GET', $base . '/api/cycle/sync/job.php', $super);
    check('SUPERVISOR — ver o andamento continua permitido → 200 (com percent e progresso)', $status === 200 && array_key_exists('percent', $json['job'] ?? []), "HTTP {$status}");
    // com a Recursiva ativa, interromper a Consolidada NÃO é bloqueado (parar é sempre permitido)
    limpar($pdo);
    $pdo->exec("INSERT INTO furk_sync_job (initial_date, final_date, estado, heartbeat) VALUES ('2026-10-01', '2026-10-10', 'RUNNING', NOW())");
    estado($pdo, 'furk_sync_rotina', 'RUNNING');
    [$status, $json] = http('POST', $base . '/api/cycle/sync/job.php', $admin, ['action' => 'stop']);
    check('com a Recursiva ativa, interromper a Consolidada continua livre → 200', $status === 200, "HTTP {$status}");

    // ------------------------------------------------------------ H
    echo "\n== H. Leituras manuais: Interromper (somente ADMIN)\n";
    limpar($pdo);
    $pdo->exec('TRUNCATE TABLE furk_carga_manual');
    $agora = date('Y-m-d H:i:s');
    $pdo->exec("INSERT INTO furk_carga_manual (tela, estado, iniciado_em, heartbeat) VALUES ('sales', 'RUNNING', '{$agora}', '{$agora}')");
    [$status] = http('POST', $base . '/api/cycle/operacoes/interromper.php', $super, []);
    check('SUPERVISOR — interromper leitura manual → 403', $status === 403, "HTTP {$status}");
    [$status] = http('POST', $base . '/api/cycle/operacoes/interromper.php', null, []);
    check('sem token — interromper leitura manual → 401', $status === 401, "HTTP {$status}");
    [$status] = http('GET', $base . '/api/cycle/operacoes/interromper.php', $admin);
    check('GET → 405', $status === 405, "HTTP {$status}");
    $estadoAntes = (string) $pdo->query('SELECT estado FROM furk_carga_manual ORDER BY id DESC LIMIT 1')->fetchColumn();
    check('as recusas não alteraram a leitura (continua RUNNING)', $estadoAntes === 'RUNNING', $estadoAntes);
    [$status, $json] = http('POST', $base . '/api/cycle/operacoes/interromper.php', $admin, []);
    $estadoDepois = (string) $pdo->query('SELECT estado FROM furk_carga_manual ORDER BY id DESC LIMIT 1')->fetchColumn();
    check('ADMIN — interromper leitura de Vendas → 200 e STOP_REQUESTED', $status === 200 && $estadoDepois === 'STOP_REQUESTED', "HTTP {$status} / {$estadoDepois}");

    $pdo->exec('TRUNCATE TABLE furk_carga_manual');
    $pdo->exec("INSERT INTO furk_carga_manual (tela, estado, iniciado_em, heartbeat) VALUES ('creditos', 'RUNNING', '{$agora}', '{$agora}')");
    [$status, $json] = http('POST', $base . '/api/cycle/operacoes/interromper.php', $admin, []);
    check('ADMIN — interromper Créditos → 409 explicando por que não dá (período seria apagado e ficaria incompleto)', $status === 409 && str_contains((string) ($json['message'] ?? ''), 'não pode ser interrompida'), "HTTP {$status}");

    $pdo->exec('TRUNCATE TABLE furk_carga_manual');
    [$status, $json] = http('POST', $base . '/api/cycle/operacoes/interromper.php', $admin, []);
    check('ADMIN — sem leitura em andamento → 400 com mensagem clara', $status === 400 && str_contains((string) ($json['message'] ?? ''), 'Não há leitura manual'), "HTTP {$status}");

    // Limite de período das leituras manuais (400 com a orientação), antes de chamar a Furk.
    limpar($pdo);
    estado($pdo, 'furk_sync_rotina', 'IDLE');
    [$status, $json] = http('POST', $base . '/api/cycle/sales/sync.php', $admin, ['initialIssueDate' => '2026-01-01', 'finalIssueDate' => '2026-10-01']);
    check('Sales Leitura com 274 dias → 400 "use a Consolidada" (sem chamar a Furk)', $status === 400 && str_contains((string) ($json['message'] ?? ''), 'Consolidada'), "HTTP {$status}");

    // ------------------------------------------------------------ F
    echo "\n== F. Segurança básica preservada\n";
    [$status] = http('POST', $base . '/api/cycle/credits/sync.php', null, []);
    check('sem token → 401', $status === 401, "HTTP {$status}");
    [$status] = http('GET', $base . '/api/cycle/operacoes/status.php', null);
    check('status sem token → 401', $status === 401, "HTTP {$status}");
} finally {
    limpar($pdo);
    proc_terminate($processo);
    proc_close($processo);
}

echo "\n" . ($fail === 0 ? 'TODOS OS TESTES HTTP PASSARAM' : "{$fail} FALHA(S)") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
