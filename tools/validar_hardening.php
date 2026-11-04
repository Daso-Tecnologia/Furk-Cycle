<?php
declare(strict_types=1);

/*
 * Validacao offline do hardening de seguranca de 21/10/2026.
 * NAO acessa banco, GATE nem Furk. Nao grava nada na pasta do projeto (usa o diretorio temporario).
 *
 * Uso (a partir da raiz do projeto OU de qualquer lugar apontando a raiz):
 *   php tools/validar_hardening.php
 *   php tools/validar_hardening.php C:\xampp\htdocs\furk-cycle-novo
 *
 * Requer vendor/ na raiz indicada. Saida: OK/FALHA por teste; exit 0 se todos passarem.
 * Esta pasta tools/ NAO vai no deploy.zip: copie-a manualmente para o servidor se precisar.
 */

$root = $argv[1] ?? dirname(__DIR__);
$root = rtrim($root, "/\\");
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "vendor/autoload.php nao encontrado em {$root}\n");
    exit(2);
}
require $root . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use FurkiCycle\Auth\AuthenticationException;
use FurkiCycle\Auth\AuthorizationException;
use FurkiCycle\Auth\JwtGuard;
use FurkiCycle\Auth\LoginThrottle;
use FurkiCycle\Config\ConfigurationException;
use FurkiCycle\Config\Env;

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? 'OK    ' : 'FALHA ') . $name . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

$tmpDirs = [];

// ---------- Env ----------
$tmp = sys_get_temp_dir() . '/fc_test_env_' . bin2hex(random_bytes(4));
file_put_contents($tmp, "# comentario\nFC_TEST_A=valor1\nFC_TEST_B=\"com aspas\"\nlinha-sem-igual\nJWT_SECRET=segredo-de-teste-com-mais-de-32-caracteres-ok\nJWT_ISSUER=test-issuer\n");
$env = new Env($tmp);
check('Env le valor', $env->required('FC_TEST_A') === 'valor1');
check('Env remove aspas', $env->required('FC_TEST_B') === 'com aspas');
check('Env NAO propaga com putenv', getenv('FC_TEST_A') === false);
check('Env ignora linha sem "="', $env->optional('linha-sem-igual') === null);
try {
    $env->required('FC_NAO_EXISTE');
    check('Env required lanca ConfigurationException', false);
} catch (ConfigurationException) {
    check('Env required lanca ConfigurationException', true);
}
check('Env.int default', $env->int('FC_NAO_EXISTE', 7) === 7);
check('Env segunda instancia usa cache', (new Env($tmp))->required('FC_TEST_A') === 'valor1');
unlink($tmp);

// ---------- JwtGuard ----------
$secret = 'segredo-de-teste-com-mais-de-32-caracteres-ok';
$guard = new JwtGuard();
$try = function (?string $header) use ($guard, $env): string {
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    if ($header !== null) {
        $_SERVER['HTTP_AUTHORIZATION'] = $header;
    }
    try {
        $guard->requireAuthenticatedUser($env);
        return 'ok';
    } catch (AuthenticationException $e) {
        return 'auth:' . $e->getMessage();
    } catch (\Throwable $e) {
        return 'OUTRA:' . get_class($e);
    }
};
$now = time();
$claims = ['sub' => '1', 'role' => 'ADMIN', 'iss' => 'test-issuer'];
check('token valido', $try('Bearer ' . JWT::encode($claims + ['exp' => $now + 600], $secret, 'HS256')) === 'ok');
check('sem header -> 401', str_starts_with($try(null), 'auth:'));
check('token malformado abc.def.ghi -> 401', $try('Bearer abc.def.ghi') === 'auth:Token inválido.');
check('token expirado -> 401 (mensagem de sessao expirada)', str_starts_with($try('Bearer ' . JWT::encode($claims + ['exp' => $now - 600], $secret, 'HS256')), 'auth:Sessão expirada'));
check('assinatura errada -> 401', str_starts_with($try('Bearer ' . JWT::encode($claims + ['exp' => $now + 600], 'outro-segredo-qualquer-com-32-caracteres!!', 'HS256')), 'auth:'));
check('emissor errado -> 401', str_starts_with($try('Bearer ' . JWT::encode(['iss' => 'outro'] + $claims + ['exp' => $now + 600], $secret, 'HS256')), 'auth:'));
check('token sem exp -> 401', str_starts_with($try('Bearer ' . JWT::encode($claims, $secret, 'HS256')), 'auth:'));
$b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$none = $b64('{"alg":"none","typ":"JWT"}') . '.' . $b64('{"sub":"1","role":"ADMIN","iss":"test-issuer","exp":' . ($now + 600) . '}') . '.';
check('alg=none rejeitado', str_starts_with($try('Bearer ' . $none), 'auth:'));
try {
    $guard->requireRole(['role' => 'SUPERVISOR'], ['ADMIN']);
    check('perfil sem permissao -> AuthorizationException', false);
} catch (AuthorizationException) {
    check('perfil sem permissao -> AuthorizationException', true);
}

// ---------- LoginThrottle ----------
$dir = sys_get_temp_dir() . '/fc_throttle_' . bin2hex(random_bytes(4));
$tmpDirs[] = $dir;
$t = new LoginThrottle(3, 6, 900, $dir);
check('inicial liberado', $t->retryAfter('a@x.com', '1.1.1.1') === 0);
for ($i = 0; $i < 3; $i++) {
    $t->recordFailure('a@x.com', '1.1.1.1');
}
check('3 falhas bloqueiam', $t->retryAfter('a@x.com', '1.1.1.1') > 0);
check('e-mail case-insensitive', $t->retryAfter('A@X.com', '1.1.1.1') > 0);
check('outro e-mail nao bloqueado pelo par', $t->retryAfter('b@x.com', '1.1.1.1') === 0);
$t->clear('a@x.com', '1.1.1.1');
check('sucesso limpa o par', $t->retryAfter('a@x.com', '1.1.1.1') === 0);
$dirIp = $dir . '_ip';
$tmpDirs[] = $dirIp;
$t2 = new LoginThrottle(100, 4, 900, $dirIp);
foreach (['u1', 'u2', 'u3', 'u4'] as $u) {
    $t2->recordFailure($u . '@x.com', '2.2.2.2');
}
check('varredura de e-mails bloqueia por IP', $t2->retryAfter('novo@x.com', '2.2.2.2') > 0);
check('IP diferente nao bloqueado', $t2->retryAfter('novo@x.com', '3.3.3.3') === 0);
$tBad = new LoginThrottle(1, 1, 900, "bad\0dir");
$tBad->recordFailure('z@x.com', '9.9.9.9');
check('armazenamento indisponivel nao bloqueia (falha aberta)', $tBad->retryAfter('z@x.com', '9.9.9.9') === 0);

// limpeza dos temporarios criados por este script
foreach ($tmpDirs as $d) {
    foreach (glob($d . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($d);
}

echo PHP_EOL . ($fail === 0 ? 'TODOS OS TESTES PASSARAM' : "{$fail} TESTE(S) FALHARAM") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
