<?php
declare(strict_types=1);

use FurkiCycle\Auth\LoginThrottle;
use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Http\JsonResponse;
use Firebase\JWT\JWT;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Hash descartável (bcrypt, custo 10) usado quando o e-mail não existe, para que o
// tempo de resposta não revele se a conta existe.
const LOGIN_DUMMY_HASH = '$2y$10$RYgOykY6EdSvuBvjHk92VuvYpR9.GQFRs9UORTdBsM0Yd6a5ma3Ji';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    JsonResponse::send(['success' => false, 'message' => 'Método não permitido.'], 405);
}

try {
    $env = new Env();
    $body = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new RuntimeException('O corpo da requisição deve ser um objeto JSON.');
    }

    $email = $body['email'] ?? null;
    $senha = $body['senha'] ?? null;
    if (!is_string($email) || !is_string($senha)) {
        throw new RuntimeException('Informe e-mail e senha.');
    }
    $email = trim($email);
    if ($email === '' || $senha === '' || strlen($email) > 254 || strlen($senha) > 1024) {
        JsonResponse::send(['success' => false, 'message' => 'E-mail ou senha inválidos.'], 401);
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $throttle = new LoginThrottle(
        $env->int('LOGIN_MAX_ATTEMPTS', 5),
        $env->int('LOGIN_MAX_ATTEMPTS_PER_IP', 30),
        $env->int('LOGIN_LOCK_MINUTES', 15) * 60,
    );

    $retryAfter = $throttle->retryAfter($email, $ip);
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        JsonResponse::send([
            'success' => false,
            'message' => 'Muitas tentativas de login. Tente novamente em ' . (int) ceil($retryAfter / 60) . ' minuto(s).',
        ], 429);
    }

    $connection = MySqlConnection::create($env);
    $stmt = $connection->prepare('SELECT id, name, email, password_hash, role, is_active FROM users WHERE email = :email AND is_active = 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    $hash = is_array($user) && is_string($user['password_hash'] ?? null) ? $user['password_hash'] : LOGIN_DUMMY_HASH;
    $validPassword = password_verify($senha, $hash);

    if (!is_array($user) || !$validPassword) {
        $throttle->recordFailure($email, $ip);
        // Sem o e-mail em claro no log (LGPD): apenas um identificador curto.
        error_log(sprintf('login-failed id=%s ip=%s', substr(hash('sha256', strtolower($email)), 0, 12), $ip));
        JsonResponse::send(['success' => false, 'message' => 'E-mail ou senha inválidos.'], 401);
    }

    $throttle->clear($email, $ip);

    $claims = [
        'sub' => (string) $user['id'],
        'role' => $user['role'],
        'iss' => $env->required('JWT_ISSUER'),
        'exp' => time() + $env->int('JWT_EXPIRATION_SECONDS', 3600),
    ];

    $token = JWT::encode($claims, $env->required('JWT_SECRET'), 'HS256');

    JsonResponse::send([
        'success' => true,
        'token' => $token,
        'usuario' => [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ],
    ]);
} catch (\Throwable $error) {
    JsonResponse::fromThrowable($error, 'Não foi possível concluir o login.');
}
