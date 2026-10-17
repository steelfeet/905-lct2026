<?php
declare(strict_types=1);

/**
 * Точка входа CRM «ИТ Школа РТК» (прототип).
 * Разбит на модули: lib/actions.php (POST), lib/data.php (данные),
 * lib/views/*.php (представления по ролям).
 */

session_start();

require __DIR__ . '/db.php';
require_once __DIR__ . '/auth/oidc.php';
require_once __DIR__ . '/lib/utils.php';
require_once __DIR__ . '/lib/workflow.php';
require_once __DIR__ . '/lib/journal.php';

$pdo = db();

$action = $_GET['action'] ?? '';
$error  = '';

// =============================================================================
// OIDC: инициация входа
// =============================================================================
if ($action === 'oidc_login') {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oidc_state'] = $state;
    $loginHint = $_GET['login_hint'] ?? '';
    header('Location: ' . oidc_login_url($state, $loginHint));
    exit;
}

// =============================================================================
// OIDC: callback
// =============================================================================
if ($action === 'oidc_callback') {
    $code  = $_GET['code']  ?? '';
    $state = $_GET['state'] ?? '';
    $expectedState = $_SESSION['oidc_state'] ?? '';
    unset($_SESSION['oidc_state']);

    if ($code === '' || $state === '' || !hash_equals($expectedState, $state)) {
        $error = 'Ошибка авторизации (state mismatch). Попробуйте снова.';
    } else {
        $tokens = oidc_exchange_code($code);
        if (!$tokens || empty($tokens['access_token'])) {
            $error = 'Не удалось получить токен от Keycloak.';
        } else {
            $oidcUser = oidc_user_from_jwt($tokens['access_token']);
            if (!$oidcUser || empty($oidcUser['login'])) {
                $error = 'Некорректный токен.';
            } else {
                $stmt = $pdo->prepare('SELECT * FROM users WHERE login = :login AND is_active = 1');
                $stmt->execute([':login' => $oidcUser['login']]);
                $dbUser = $stmt->fetch();
                if ($dbUser) {
                    session_regenerate_id(true);
                    $_SESSION['user_id']           = (int) $dbUser['id'];
                    $_SESSION['oidc_access_token'] = $tokens['access_token'];
                    $_SESSION['oidc_roles']        = $oidcUser['roles'];
                    header('Location: index.php');
                    exit;
                }
                $error = 'Пользователь «' . $oidcUser['login'] . '» не найден в системе.';
            }
        }
    }
}

// =============================================================================
// Выход
// =============================================================================
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

// =============================================================================
// Текущий пользователь
// =============================================================================
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
    if ($currentUser === false) $currentUser = null;
}

// POST-действия (move_phase, confirm_phase, reject_phase) — до рендера
require __DIR__ . '/lib/actions.php';

// Если не авторизован — экран входа и выход
if ($currentUser === null) {
    require __DIR__ . '/lib/views/auth.php';
    exit;
}

// Роль текущего представления (URL ?role=...)
$viewRole = $_GET['role'] ?? 'manager';
if (!in_array($viewRole, ['manager', 'university', 'supervisor', 'student', 'parent'], true)) {
    $viewRole = 'manager';
}

// Загрузка данных (БД + mock + агрегаты дашборда)
require __DIR__ . '/lib/data.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CRM «ИТ Школа РТК»</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600&family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="role-<?= e($viewRole) ?>">
    <div class="dashboard">
        <?php require __DIR__ . '/lib/views/topbar.php'; ?>
        <?php require __DIR__ . '/lib/views/nav.php'; ?>
        <main class="content">
            <?php
            switch ($viewRole) {
                case 'manager':    require __DIR__ . '/lib/views/manager.php';    break;
                case 'university': require __DIR__ . '/lib/views/university.php'; break;
                case 'student':    require __DIR__ . '/lib/views/student.php';    break;
                case 'parent':     require __DIR__ . '/lib/views/parent.php';     break;
                case 'supervisor': require __DIR__ . '/lib/views/supervisor.php'; break;
            }
            ?>
        </main>
    </div>

    <?php require __DIR__ . '/lib/views/footer.php'; ?>

    <script src="assets/common.js"></script>

    <?php if ($viewRole === 'supervisor'): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script src="assets/supervisor.js"></script>
    <?php endif; ?>
</body>
</html>