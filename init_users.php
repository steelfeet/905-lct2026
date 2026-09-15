<?php
/**
 * Инициализация сущности «Пользователи» для CRM «ИТ Школа РТК» (прототип, SQLite).
 *
 * Создание таблиц:
 *  - roles      — справочник ролей;
 *  - users      — пользователи (авторизация по логин/пароль);
 *  - user_roles — связь пользователей с ролями.
 *
 * Заполнение:
 *  - роли: Менеджер (manager), Представитель ВУЗа (university_rep),
 *          Руководитель (supervisor), Администратор (admin);
 *  - пользователи: Ivan / Pavel / Olga / Alex.
 *
 * Запуск: php init_users.php
 * Файл идемпотентен: при повторном запуске структура и данные не дублируются.
 */

require __DIR__ . '/db.php';

$pdo = db();

// --- Структура -------------------------------------------------------------

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS roles (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL
)
SQL);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    login      VARCHAR(100) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    full_name  VARCHAR(200) DEFAULT NULL,
    is_active  INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS user_roles (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
)
SQL);

// --- Справочник ролей ------------------------------------------------------

$roles = [
    'manager'       => 'Менеджер',
    'university_rep' => 'Представитель ВУЗа',
    'supervisor'    => 'Руководитель',
    'admin'         => 'Администратор',
];

$roleIds = [];

$insertRole = $pdo->prepare('INSERT INTO roles (code, name) VALUES (:code, :name)');
$selectRole = $pdo->prepare('SELECT id FROM roles WHERE code = :code');

foreach ($roles as $code => $name) {
    $selectRole->execute([':code' => $code]);
    $id = $selectRole->fetchColumn();

    if ($id === false) {
        $insertRole->execute([':code' => $code, ':name' => $name]);
        $id = $pdo->lastInsertId();
        echo "Создана роль: {$name} ({$code})\n";
    }

    $roleIds[$code] = (int) $id;
}

// --- Пользователи ----------------------------------------------------------

$users = [
    ['login' => 'Ivan',  'password' => '1111', 'full_name' => 'Иван',   'role' => 'manager'],
    ['login' => 'Pavel', 'password' => '2222', 'full_name' => 'Павел',  'role' => 'university_rep'],
    ['login' => 'Olga',  'password' => '333',  'full_name' => 'Ольга',  'role' => 'supervisor'],
    ['login' => 'Alex',  'password' => '4444', 'full_name' => 'Алексей', 'role' => 'admin'],
];

$insertUser = $pdo->prepare(
    'INSERT INTO users (login, password, full_name) VALUES (:login, :password, :full_name)'
);
$selectUser = $pdo->prepare('SELECT id FROM users WHERE login = :login');
$attachRole = $pdo->prepare('INSERT OR IGNORE INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');

foreach ($users as $user) {
    $selectUser->execute([':login' => $user['login']]);
    $userId = $selectUser->fetchColumn();

    if ($userId === false) {
        $insertUser->execute([
            ':login'     => $user['login'],
            ':password'  => password_hash($user['password'], PASSWORD_DEFAULT),
            ':full_name' => $user['full_name'],
        ]);
        $userId = $pdo->lastInsertId();
        echo "Создан пользователь: {$user['login']} ({$roles[$user['role']]})\n";
    }

    $attachRole->execute([':user_id' => (int) $userId, ':role_id' => $roleIds[$user['role']]]);
}

echo "Инициализация завершена. Таблицы: roles, users, user_roles.\n";
