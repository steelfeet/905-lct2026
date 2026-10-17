<?php
/**
 * Заглушка Keycloak для shared-хостинга.
 *
 * Полностью повторяет OIDC-контракт Keycloak по путям и формату JWT.
 * Используется в демо, потому что реальный Keycloak — Java-сервис,
 * который нельзя развернуть на shared-хостинге с PHP.
 *
 * На собственной инфраструктуре заменяется реальным Keycloak —
 * клиентский код CRM и LMS не меняется.
 */

require_once __DIR__ . '/keys.php';

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = preg_replace('#^.*?/keycloak-stub#', '', $uri);
$path = rtrim($path, '/');

$realm = 'rtk';
$realmPrefix = '/realms/' . $realm;

// ============================================================================
// GET /realms/{realm}/protocol/openid-connect/auth
// Эмуляция формы логина. Для демо не показываем форму — сразу редиректим
// обратно на redirect_uri с подписанным code, содержащим выбранную роль.
// ============================================================================
if ($path === $realmPrefix . '/protocol/openid-connect/auth') {
    $loginHint = $_GET['login_hint'] ?? 'manager';
    $allowed   = ['manager', 'supervisor', 'university_rep', 'student', 'parent'];
    if (!in_array($loginHint, $allowed, true)) {
        $loginHint = 'manager';
    }

    $redirectUri = $_GET['redirect_uri'] ?? '';
    if ($redirectUri === '') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'redirect_uri is required']);
        exit;
    }

    $state = $_GET['state'] ?? '';

    // Формируем подписанный «code» с ролью и логином
    $code = stub_code_sign([
        'role'  => $loginHint,
        'login' => $loginHint,
        'iat'   => time(),
    ]);

    $sep = (strpos($redirectUri, '?') === false) ? '?' : '&';
    header('Location: ' . $redirectUri . $sep
        . 'code='  . urlencode($code)
        . '&state=' . urlencode($state));
    exit;
}

// ============================================================================
// POST /realms/{realm}/protocol/openid-connect/token
// Обмен authorization code на JWT.
// ============================================================================
if ($path === $realmPrefix . '/protocol/openid-connect/token') {
    header('Content-Type: application/json');

    $grantType = $_POST['grant_type'] ?? '';
    $code      = $_POST['code'] ?? '';

    if ($grantType !== 'authorization_code') {
        http_response_code(400);
        echo json_encode(['error' => 'unsupported_grant_type']);
        exit;
    }

    $payload = stub_code_verify($code);
    if (!$payload || !isset($payload['login'])) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant']);
        exit;
    }

    $now = time();
    $login = (string) $payload['login'];
    $role  = (string) ($payload['role'] ?? 'manager');

    $accessJwt = stub_jwt([
        'iss'                => ($_SERVER['HTTPS'] ?? '') === 'on'
                                    ? 'https://' . ($_SERVER['HTTP_HOST'] ?? '')
                                      . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\')
                                      . '/realms/' . $realm
                                    : 'http://' . ($_SERVER['HTTP_HOST'] ?? '')
                                      . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\')
                                      . '/realms/' . $realm,
        'sub'                => 'user-' . $login,
        'preferred_username' => $login,
        'name'               => ucfirst($login),
        'realm_access'       => ['roles' => [$role]],
        'iat'                => $now,
        'exp'                => $now + STUB_TOKEN_TTL,
        'typ'                => 'Bearer',
    ]);

    echo json_encode([
        'access_token'  => $accessJwt,
        'token_type'    => 'Bearer',
        'expires_in'    => STUB_TOKEN_TTL,
        'refresh_token' => 'stub-refresh-' . bin2hex(random_bytes(8)),
    ]);
    exit;
}

// ============================================================================
// GET /realms/{realm}/protocol/openid-connect/userinfo
// Данные пользователя по Bearer-токену.
// ============================================================================
if ($path === $realmPrefix . '/protocol/openid-connect/userinfo') {
    header('Content-Type: application/json');

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_token']);
        exit;
    }
    $p = stub_jwt_decode($m[1]);
    if (!$p) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_token']);
        exit;
    }

    echo json_encode([
        'sub'                => $p['sub'] ?? null,
        'preferred_username' => $p['preferred_username'] ?? null,
        'name'               => $p['name'] ?? null,
        'roles'              => $p['realm_access']['roles'] ?? [],
    ]);
    exit;
}

// ============================================================================
// GET /realms/{realm}/protocol/openid-connect/certs
// Публичные ключи Keycloak. Для стаба — заглушка в стандартном формате JWKS.
// ============================================================================
if ($path === $realmPrefix . '/protocol/openid-connect/certs') {
    header('Content-Type: application/json');
    echo json_encode([
        'keys' => [
            [
                'kid' => 'stub-key',
                'kty' => 'oct',
                'alg' => 'HS256',
                'use' => 'sig',
            ],
        ],
    ]);
    exit;
}

// ============================================================================
// 404
// ============================================================================
http_response_code(404);
header('Content-Type: application/json');
echo json_encode([
    'error'             => 'not_found',
    'error_description' => 'Keycloak-stub: endpoint not found',
    'path'              => $path,
]);