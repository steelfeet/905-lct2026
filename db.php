<?php
/**
 * Подключение к базе данных прототипа (SQLite).
 * Файл БД создаётся рядом с этим скриптом: crm.sqlite
 */

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $path = __DIR__ . '/crm.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    return $pdo;
}
