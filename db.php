<?php
declare(strict_types=1);

/**
 * Подключение к SQLite. Путь — всегда абсолютный:
 * при CLI и под веб-сервером это один и тот же файл.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = __DIR__ . '/data/crm.sqlite';

    if (!is_file($path)) {
        throw new RuntimeException(
            "БД не найдена: {$path}. Запустите `php db/init.php`."
        );
    }

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');   // важно под параллельные запросы

    return $pdo;
}