<?php
/**
 * Ключи и утилиты подписи JWT для keycloak-stub.
 *
 * Для стаба используется HMAC-SHA256 с общим секретом.
 * Реальный Keycloak подписывает токены RS256 и публикует публичный ключ
 * на /realms/{realm}/protocol/openid-connect/certs — структура JWT та же,
 * меняется только алгоритм и способ проверки подписи.
 *
 * Этот файл не должен быть доступен из браузера — закрыт в .htaccess.
 */

const STUB_JWT_SECRET = 'rtk-keycloak-stub-secret-change-in-production';
const STUB_TOKEN_TTL  = 3600; // секунд

function stub_b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function stub_b64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/'));
}

/** Формирует JWT (header.payload.signature) с HMAC-SHA256. */
function stub_jwt(array $payload): string
{
    $header = stub_b64url_encode(json_encode([
        'alg' => 'HS256',
        'typ' => 'JWT',
        'kid' => 'stub-key',
    ]));
    $body = stub_b64url_encode(json_encode($payload));
    $sig  = stub_b64url_encode(hash_hmac('sha256', $header . '.' . $body, STUB_JWT_SECRET, true));

    return $header . '.' . $body . '.' . $sig;
}

/** Проверяет JWT и возвращает payload или null. */
function stub_jwt_decode(string $jwt): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    [$h, $b, $s] = $parts;

    $expected = stub_b64url_encode(hash_hmac('sha256', $h . '.' . $b, STUB_JWT_SECRET, true));
    if (!hash_equals($expected, $s)) {
        return null;
    }

    $payload = json_decode(stub_b64url_decode($b), true);
    if (!is_array($payload)) {
        return null;
    }
    if (($payload['exp'] ?? 0) < time()) {
        return null;
    }
    return $payload;
}

/** Подписывает «authorization code» — короткоживущий токен с ролью. */
function stub_code_sign(array $payload): string
{
    $payload['exp'] = time() + 300; // 5 минут
    return stub_jwt($payload);
}

/** Проверяет «authorization code» и возвращает payload или null. */
function stub_code_verify(string $code): ?array
{
    return stub_jwt_decode($code);
}