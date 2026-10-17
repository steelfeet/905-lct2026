<?php
/**
 * Конфигурация OIDC-авторизации.
 *
 * На хакатонном shared-хостинге issuer указывает на keycloak-stub,
 * который полностью повторяет контракт Keycloak по путям и формату JWT.
 *
 * При переезде на собственную инфраструктуру достаточно поменять
 * переменную окружения OIDC_ISSUER_URL на реальный Keycloak — клиентский
 * код (auth/oidc.php, index.php) не меняется.
 */

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');

return [
    // Полный issuer URL. Для стаба — <host>/<base>/keycloak-stub
    'issuer'        => getenv('OIDC_ISSUER_URL')
        ?: $scheme . '://' . $host . $base . '/keycloak-stub',

    'realm'         => 'rtk',
    'client_id'     => getenv('OIDC_CLIENT_ID')     ?: 'crm-web',
    'client_secret' => getenv('OIDC_CLIENT_SECRET') ?: 'rtk-crm-secret-change-me',

    // Куда Keycloak вернёт authorization code
    'redirect_uri'  => getenv('OIDC_REDIRECT_URI')
        ?: $scheme . '://' . $host . $base . '/index.php?action=oidc_callback',

    // Таймауты
    'http_timeout'  => 10,
];