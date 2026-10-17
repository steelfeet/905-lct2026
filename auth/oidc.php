<?php
/**
 * Единый OIDC-клиент для CRM и LMS.
 *
 * Контракт совпадает с Keycloak:
 *   GET  /realms/{realm}/protocol/openid-connect/auth     — форма логина
 *   POST /realms/{realm}/protocol/openid-connect/token    — обмен code на JWT
 *   GET  /realms/{realm}/protocol/openid-connect/userinfo — данные пользователя
 *   GET  /realms/{realm}/protocol/openid-connect/certs    — публичные ключи
 *
 * На хакатоне issuer — keycloak-stub, но URL и формат JWT те же.
 * На собственной инфраструктуре — реальный Keycloak без изменений в этом файле.
 */

require_once __DIR__ . '/config.php';

function oidc_cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

function oidc_b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function oidc_b64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Возвращает URL формы логина Keycloak.
 *
 * @param string $state     Случайная строка для защиты от CSRF.
 * @param string $loginHint Подсказка — какого пользователя показать в форме (демо).
 */
function oidc_login_url(string $state, string $loginHint = ''): string
{
    $c = oidc_cfg();
    $base = rtrim($c['issuer'], '/') . '/realms/' . $c['realm']
          . '/protocol/openid-connect/auth';

    $params = [
        'client_id'     => $c['client_id'],
        'redirect_uri'  => $c['redirect_uri'],
        'response_type' => 'code',
        'scope'         => 'openid profile email roles',
        'state'         => $state,
    ];
    if ($loginHint !== '') {
        $params['login_hint'] = $loginHint;
    }

    return $base . '?' . http_build_query($params);
}

/**
 * Обменивает authorization code на набор токенов.
 *
 * @return array|null  ['access_token', 'refresh_token', 'expires_in', ...] или null
 */
function oidc_exchange_code(string $code): ?array
{
    $c   = oidc_cfg();
    $url = rtrim($c['issuer'], '/') . '/realms/' . $c['realm']
         . '/protocol/openid-connect/token';

    $body = http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => $c['client_id'],
        'client_secret' => $c['client_secret'],
        'redirect_uri'  => $c['redirect_uri'],
    ]);

    // 1) Пробуем curl, если доступен
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) $c['http_timeout'],
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $resp = curl_exec($ch);
        $err  = curl_errno($ch);
        curl_close($ch);
        if ($err === 0 && is_string($resp)) {
            return json_decode($resp, true) ?: null;
        }
    }

    // 2) Fallback — stream context (если curl отключён на shared-хостинге)
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => (int) $c['http_timeout'],
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        return null;
    }
    return json_decode($resp, true) ?: null;
}

/**
 * Декодирует payload JWT без проверки подписи.
 * Используется только как быстрый доступ к полям; подпись проверяется отдельно
 * (в проде — по JWKS, в стабе — HMAC на стороне keycloak-stub).
 */
function oidc_decode_payload(string $jwt): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    $payload = json_decode(oidc_b64url_decode($parts[1]), true);
    if (!is_array($payload)) {
        return null;
    }
    if (($payload['exp'] ?? 0) < time()) {
        return null;
    }
    return $payload;
}

/**
 * Возвращает нормализованного пользователя из JWT.
 *
 * @return array|null  ['sub', 'login', 'name', 'roles']
 */
function oidc_user_from_jwt(string $jwt): ?array
{
    $p = oidc_decode_payload($jwt);
    if (!$p) {
        return null;
    }

    $roles = [];
    if (isset($p['realm_access']['roles']) && is_array($p['realm_access']['roles'])) {
        $roles = $p['realm_access']['roles'];
    }

    return [
        'sub'   => $p['sub'] ?? null,
        'login' => $p['preferred_username'] ?? null,
        'name'  => $p['name'] ?? ($p['preferred_username'] ?? ''),
        'roles' => $roles,
    ];
}

/**
 * Запрашивает userinfo по access-токену.
 * Опционально — если в интерфейсе нужны дополнительные поля пользователя.
 */
function oidc_userinfo(string $accessToken): ?array
{
    $c = oidc_cfg();
    $url = rtrim($c['issuer'], '/') . '/realms/' . $c['realm']
         . '/protocol/openid-connect/userinfo';

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "Authorization: Bearer " . $accessToken . "\r\n",
            'timeout' => (int) $c['http_timeout'],
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        return null;
    }
    return json_decode($resp, true) ?: null;
}