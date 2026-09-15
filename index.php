<?php
/**
 * Точка входа CRM «ИТ Школа РТК» (прототип).
 *
 * Авторизация по логин/пароль. Если пользователь не залогинен — показывается
 * форма входа. После успешного входа — основное поле с приветствием, ролью
 * пользователя и кнопкой выхода.
 */

session_start();

require __DIR__ . '/db.php';

$pdo = db();
$error = '';

// Выход
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Вход
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login === '' || $password === '') {
        $error = 'Укажите логин и пароль.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE login = :login AND is_active = 1');
        $stmt->execute([':login' => $login]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            header('Location: index.php');
            exit;
        }

        $error = 'Неверный логин или пароль.';
    }
}

// Текущий пользователь
$currentUser = null;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare(
        'SELECT u.id, u.login, u.full_name,
                GROUP_CONCAT(r.name, ", ") AS role_names
         FROM users u
         LEFT JOIN user_roles ur ON ur.user_id = u.id
         LEFT JOIN roles r ON r.id = ur.role_id
         WHERE u.id = :id AND u.is_active = 1
         GROUP BY u.id'
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $currentUser = $stmt->fetch();

    if ($currentUser === false) {
        $currentUser = null;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CRM «ИТ Школа РТК»</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: #f4f6f9;
            color: #1f2937;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
            padding: 32px;
            width: 100%;
            max-width: 400px;
        }
        h1 { font-size: 22px; margin-bottom: 8px; }
        .subtitle { color: #6b7280; font-size: 14px; margin-bottom: 24px; }
        label { display: block; font-size: 14px; margin-bottom: 6px; }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
            margin-bottom: 16px;
        }
        input:focus { outline: 2px solid #2563eb; border-color: #2563eb; }
        button {
            width: 100%;
            padding: 12px;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            cursor: pointer;
        }
        button:hover { background: #1d4ed8; }
        .error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            margin-bottom: 16px;
        }
        .main { max-width: 720px; }
        .user-meta { margin: 16px 0 24px; font-size: 15px; line-height: 1.6; }
        .badge {
            display: inline-block;
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 999px;
            padding: 4px 12px;
            font-size: 13px;
        }
        .logout { display: inline-block; width: auto; background: #6b7280; text-decoration: none; }
        .logout:hover { background: #4b5563; }
    </style>
</head>
<body>
<?php if ($currentUser === null): ?>
    <div class="card">
        <h1>CRM «ИТ Школа РТК»</h1>
        <p class="subtitle">Вход в систему</p>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" action="index.php">
            <label for="login">Логин</label>
            <input type="text" id="login" name="login" autocomplete="username"
                   value="<?= htmlspecialchars($_POST['login'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <label for="password">Пароль</label>
            <input type="password" id="password" name="password" autocomplete="current-password">

            <button type="submit">Войти</button>
        </form>
    </div>
<?php else: ?>
    <div class="card main">
        <h1>Добро пожаловать!</h1>
        <p class="subtitle">Основное поле CRM «ИТ Школа РТК»</p>

        <div class="user-meta">
            <strong><?= htmlspecialchars($currentUser['full_name'] ?: $currentUser['login'], ENT_QUOTES, 'UTF-8') ?></strong>
            (логин: <?= htmlspecialchars($currentUser['login'], ENT_QUOTES, 'UTF-8') ?>)<br>
            Роль:
            <span class="badge">
                <?= htmlspecialchars($currentUser['role_names'] ?? 'не назначена', ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>

        <a class="logout" href="index.php?logout=1">Выйти</a>
    </div>
<?php endif; ?>
</body>
</html>
