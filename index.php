<?php
/**
 * Точка входа CRM «ИТ Школа РТК» (прототип).
 *
 * Авторизация по логин/пароль. Если пользователь не залогинен — показывается
 * форма входа. После успешного входа — основное поле: сетка «вуз × продукт»,
 * окрашенная по фазам взаимодействия, с легендой фаз.
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

// Справочник ролей — для выпадающего списка на форме входа (демонстрация)
$allRoles = $pdo->query('SELECT code, name FROM roles ORDER BY id')->fetchAll();

// Данные для сетки «вуз × продукт» на главном окне
$universities = $pdo->query('SELECT id, name FROM universities ORDER BY id')->fetchAll();
$products     = $pdo->query('SELECT id, name FROM it_products ORDER BY id')->fetchAll();
$phases       = $pdo->query('SELECT id, code, num, name, color FROM interaction_phases ORDER BY num')->fetchAll();
$interactions = $pdo->query('SELECT university_id, product_id, phase_id FROM interactions')->fetchAll();

// Карта: university_id => [product_id => phase_id]
$phaseMap = [];
foreach ($interactions as $row) {
    $phaseMap[(int) $row['university_id']][(int) $row['product_id']] =
        $row['phase_id'] !== null ? (int) $row['phase_id'] : null;
}

// Быстрый доступ к фазе по id
$phaseById = [];
foreach ($phases as $p) {
    $phaseById[(int) $p['id']] = $p;
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

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// ===== Workflow текущего проекта ===========================================
// 14 шагов базового workflow по ТЗ (раздел 5.1): номер, название, сторона.

$workflowSteps = [
    ['num' => 1,  'name' => 'Поиск контактов ответственного в вузе',   'side' => 'internal'],
    ['num' => 2,  'name' => 'Коммуникация и уточнение актуальности программ', 'side' => 'both'],
    ['num' => 3,  'name' => 'Организация встречи',                     'side' => 'both'],
    ['num' => 4,  'name' => 'Обмен документами',                       'side' => 'both'],
    ['num' => 5,  'name' => 'Корректировка документов',                'side' => 'both'],
    ['num' => 6,  'name' => 'Подписание документов',                   'side' => 'both'],
    ['num' => 7,  'name' => 'Передача материалов, лицензий, документации', 'side' => 'external'],
    ['num' => 8,  'name' => 'Сопровождение внедрения',                 'side' => 'both'],
    ['num' => 9,  'name' => 'Обучение преподавателей',                 'side' => 'both'],
    ['num' => 10, 'name' => 'Актуализация учебной программы',          'side' => 'both'],
    ['num' => 11, 'name' => 'Ведение занятий',                         'side' => 'external'],
    ['num' => 12, 'name' => 'Актуализация документации',               'side' => 'both'],
    ['num' => 13, 'name' => 'Повышение квалификации преподавателей',   'side' => 'both'],
    ['num' => 14, 'name' => 'Контроль исполнения',                     'side' => 'internal'],
];

// Текущий проект (взаимодействие «вуз + продукт») — выбирается в шапке панели.
$projectOptions = $pdo->query(
    'SELECT i.id, i.phase_id, u.name AS university_name, p.name AS product_name
     FROM interactions i
     JOIN universities u ON u.id = i.university_id
     JOIN it_products  p ON p.id = i.product_id
     ORDER BY i.id'
)->fetchAll();

// Готовое название для выпадающего списка выбора текущего проекта.
foreach ($projectOptions as &$opt) {
    $opt['title'] = $opt['university_name'] . ' · ' . $opt['product_name'];
}
unset($opt);

$currentProjectId = 0;
foreach ($projectOptions as $opt) {
    if ((int) $opt['id'] === (int) ($_GET['project'] ?? 0)) {
        $currentProjectId = (int) $opt['id'];
        break;
    }
}
if ($currentProjectId === 0 && $projectOptions !== []) {
    $currentProjectId = (int) $projectOptions[0]['id'];
}

$currentProject = ['id' => $currentProjectId, 'title' => 'нет активных проектов', 'phase' => null];
$currentPhaseNum = 0;

foreach ($projectOptions as $opt) {
    if ((int) $opt['id'] === $currentProjectId) {
        $currentProject['title'] = $opt['university_name'] . ' · ' . $opt['product_name'];
        $currentProject['phase'] = $opt['phase_id'] !== null ? ($phaseById[(int) $opt['phase_id']] ?? null) : null;
        if ($currentProject['phase'] !== null) {
            $currentPhaseNum = (int) $currentProject['phase']['num'];
        }
        break;
    }
}

// Рабочие состояния шагов для текущего этапа (демонстрация двунаправленности).
$stepStates = [
    'completed'        => ['label' => 'Завершён',        'color' => '#16a34a', 'fill' => 100],
    'in_progress'      => ['label' => 'В работе',        'color' => '#2563eb', 'fill' => 60],
    'waiting_internal' => ['label' => 'Ждёт ИТ Школу',   'color' => '#8b6914', 'fill' => 40],
    'waiting_external' => ['label' => 'Ждёт вуз',        'color' => '#d97706', 'fill' => 40],
    'needs_revision'   => ['label' => 'Требует доработки', 'color' => '#dc2626', 'fill' => 25],
    'pending'          => ['label' => 'Ожидает',         'color' => '#d1d5db', 'fill' => 0],
];

// Детерминированное состояние текущего шага по id проекта.
$currentStates = ['in_progress', 'waiting_external', 'needs_revision'];
$currentState = $currentStates[$currentProjectId % count($currentStates)];

$sideLabels = [
    'internal' => 'Внутренний контур — ИТ Школа РТК',
    'external' => 'Внешний контур — вуз',
    'both'     => 'Совместный шаг — ИТ Школа РТК + вуз',
];

$workflow = [];
foreach ($workflowSteps as $step) {
    if ($step['num'] < $currentPhaseNum) {
        $state = 'completed';
    } elseif ($step['num'] === $currentPhaseNum) {
        $state = $currentState;
    } else {
        $state = 'pending';
    }

    $workflow[] = [
        'num'        => $step['num'],
        'name'       => $step['name'],
        'side'       => $step['side'],
        'sideLabel'  => $sideLabels[$step['side']],
        'state'      => $state,
        'stateLabel' => $stepStates[$state]['label'],
        'color'      => $stepStates[$state]['color'],
        'fill'       => $stepStates[$state]['fill'],
        'isCurrent'  => $step['num'] === $currentPhaseNum,
    ];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CRM «ИТ Школа РТК»</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        /* Quiet Luxury — единый стиль с корневым сайтом «Кладезь» */
        :root {
            --color-primary: #1a1a1a;
            --color-secondary: #2d2d2d;
            --color-tertiary: #4a4a4a;
            --color-light: #f8f8f8;
            --color-white: #ffffff;
            --color-accent: #8b6914;
            --color-accent-light: #a67c00;
            --color-accent-dark: #6b5010;
            --font-serif: 'Cormorant Garamond', serif;
            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            --space-xs: 0.5rem;
            --space-sm: 1rem;
            --space-md: 1.5rem;
            --space-lg: 2rem;
            --space-xl: 3rem;
            --transition-base: 0.2s ease-in-out;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-sans);
            font-weight: 400;
            line-height: 1.6;
            color: var(--color-primary);
            background-color: var(--color-white);
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3 { font-family: var(--font-serif); font-weight: 500; line-height: 1.2; }

        a { color: var(--color-primary); text-decoration: none; transition: var(--transition-base); }
        a:hover { color: var(--color-accent); }

        /* ===== Форма входа ===== */
        .auth-body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--color-light);
            padding: var(--space-lg);
        }

        .auth-card {
            background-color: var(--color-white);
            border: 1px solid rgba(26, 26, 26, 0.1);
            padding: var(--space-xl);
            width: 100%;
            max-width: 420px;
        }

        .auth-card__brand {
            font-size: 2rem;
            font-weight: 600;
            text-align: center;
            margin-bottom: var(--space-xs);
        }

        .auth-card__subtitle {
            font-size: 0.9rem;
            color: var(--color-accent);
            text-align: center;
            margin-bottom: var(--space-lg);
            font-weight: 500;
        }

        .auth-form label {
            display: block;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: var(--space-xs);
            color: var(--color-secondary);
        }

        .auth-form input[type="text"],
        .auth-form input[type="password"],
        .auth-form select {
            width: 100%;
            padding: 0.75rem var(--space-sm);
            border: 1px solid rgba(26, 26, 26, 0.15);
            background-color: var(--color-white);
            color: var(--color-primary);
            font-family: var(--font-sans);
            font-size: 0.95rem;
            margin-bottom: var(--space-md);
            transition: var(--transition-base);
        }

        .auth-form input:focus,
        .auth-form select:focus {
            outline: none;
            border-color: var(--color-accent);
        }

        .auth-form button {
            width: 100%;
            padding: var(--space-sm);
            background-color: var(--color-accent);
            color: var(--color-white);
            border: none;
            font-family: var(--font-sans);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition-base);
        }

        .auth-form button:hover {
            background-color: var(--color-accent-light);
        }

        .auth-error {
            background-color: #fdf3f2;
            color: #9b2c1f;
            border-left: 2px solid #9b2c1f;
            padding: 0.75rem var(--space-sm);
            font-size: 0.85rem;
            margin-bottom: var(--space-md);
        }

        /* ===== Панель управления ===== */
        .dashboard {
            display: grid;
            grid-template-columns: 260px 1fr;
            grid-template-rows: auto 1fr;
            min-height: 100vh;
        }

        .topbar {
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: var(--space-sm);
            padding: var(--space-md) var(--space-lg);
            background-color: var(--color-white);
            border-bottom: 1px solid rgba(26, 26, 26, 0.1);
        }

        .topbar__title {
            font-family: var(--font-serif);
            font-size: 1.5rem;
            font-weight: 600;
        }

        .topbar__title span {
            color: var(--color-accent);
            font-size: 0.85rem;
            font-family: var(--font-sans);
            font-weight: 400;
            display: block;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .topbar__user {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            font-size: 0.9rem;
        }

        .badge {
            display: inline-block;
            background-color: rgba(139, 105, 20, 0.1);
            border: 1px solid var(--color-accent);
            color: var(--color-accent-dark);
            padding: 2px 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .topbar__logout {
            padding: 0.5rem var(--space-md);
            border: 1px solid rgba(26, 26, 26, 0.2);
            font-size: 0.85rem;
            transition: var(--transition-base);
        }

        .topbar__logout:hover {
            border-color: var(--color-accent);
            background-color: rgba(139, 105, 20, 0.05);
        }

        .sidebar {
            background-color: var(--color-light);
            border-right: 1px solid rgba(26, 26, 26, 0.1);
            padding: var(--space-lg) var(--space-md);
        }

        .sidebar__caption {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--color-tertiary);
            margin-bottom: var(--space-sm);
        }

        .sidebar ul { list-style: none; }

        .sidebar a {
            display: block;
            padding: 0.65rem var(--space-sm);
            font-size: 0.9rem;
            border-left: 2px solid transparent;
            margin-bottom: 2px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background-color: rgba(139, 105, 20, 0.1);
            border-left-color: var(--color-accent);
            color: var(--color-primary);
        }

        .content {
            padding: var(--space-lg);
            overflow-x: auto;
        }

        .content__header { margin-bottom: var(--space-lg); }

        .content__title { font-size: 1.75rem; margin-bottom: var(--space-xs); }

        .content__subtitle { color: var(--color-tertiary); font-size: 0.95rem; }

        /* ===== Главное окно: workflow (верхняя четверть) + сетка (нижние 3/4) ===== */
        .workspace {
            display: flex;
            flex-direction: column;
            gap: var(--space-md);
            min-height: calc(100vh - 46px); /* минус высота шапки */
        }

        .panel {
            background-color: var(--color-white);
            border: 1px solid rgba(26, 26, 26, 0.1);
            padding: var(--space-md);
        }

        .panel--workflow {
            flex: 0 0 25%;
            min-height: 230px;
            display: flex;
            flex-direction: column;
        }

        .panel--matrix {
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .panel__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: var(--space-sm);
            margin-bottom: var(--space-sm);
        }

        .panel__title { font-size: 1.4rem; }

        .panel__subtitle { color: var(--color-tertiary); font-size: 0.85rem; }

        /* ===== Workflow текущего проекта ===== */
        .project-select { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; }

        .project-select select {
            padding: 0.4rem 0.6rem;
            border: 1px solid rgba(26, 26, 26, 0.15);
            background-color: var(--color-white);
            font-family: var(--font-sans);
            font-size: 0.85rem;
            max-width: 380px;
        }

        .workflow-scroll {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .workflow-track {
            display: flex;
            align-items: stretch;
            gap: 4px;
            overflow-x: auto;
            padding: 4px 2px 8px;
        }

        .wstep {
            position: relative;
            flex: 1 0 96px;
            max-width: 130px;
            min-height: 108px;
            border: 1px solid rgba(26, 26, 26, 0.12);
            border-top: 4px solid rgba(26, 26, 26, 0.2);
            background-color: var(--color-light);
            padding: 26px 6px 6px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            transition: var(--transition-base);
        }

        .wstep:hover { border-color: var(--color-accent); }

        .wstep.current {
            border-color: var(--color-accent);
            background-color: rgba(139, 105, 20, 0.06);
            box-shadow: 0 0 0 1px var(--color-accent);
        }

        .wstep__num {
            position: absolute;
            top: 4px;
            left: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--color-tertiary);
        }

        .wstep.current .wstep__num { color: var(--color-accent-dark); }

        .wstep__side {
            position: absolute;
            top: 4px;
            right: 6px;
            font-size: 0.6rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            padding: 1px 5px;
            border: 1px solid rgba(26, 26, 26, 0.25);
            color: var(--color-secondary);
            background-color: var(--color-white);
        }

        .wstep__side--both { border-color: var(--color-accent); color: var(--color-accent-dark); }

        .wstep__name {
            font-size: 0.7rem;
            line-height: 1.25;
            color: var(--color-secondary);
            overflow: hidden;
        }

        .wstep__status {
            margin-top: auto;
            font-size: 0.62rem;
            font-weight: 500;
            padding: 1px 5px;
            border: 1px solid rgba(26, 26, 26, 0.2);
            background-color: var(--color-white);
            align-self: flex-start;
            white-space: nowrap;
        }

        .wstep__bar { height: 6px; background-color: rgba(26, 26, 26, 0.08); }

        .wstep__bar-fill { height: 100%; }

        .wstep__flag {
            position: absolute;
            top: -7px;
            right: 22px;
            font-size: 0.6rem;
            font-weight: 700;
            color: var(--color-accent-dark);
        }

        /* Рабочие состояния шагов (демонстрация визуализации этапов) */
        .wstep--completed { border-top-color: #16a34a; }
        .wstep--completed .wstep__status { border-color: #16a34a; color: #15803d; }

        .wstep--in_progress { border-top-color: #2563eb; }
        .wstep--in_progress .wstep__status { border-color: #2563eb; color: #1d4ed8; }

        .wstep--waiting_internal { border-top-color: var(--color-accent); }
        .wstep--waiting_internal .wstep__status { border-color: var(--color-accent); color: var(--color-accent-dark); }

        .wstep--waiting_external { border-top-color: #d97706; }
        .wstep--waiting_external .wstep__status { border-color: #d97706; color: #b45309; }

        .wstep--needs_revision { border-top-color: #dc2626; }
        .wstep--needs_revision .wstep__status { border-color: #dc2626; color: #b91c1c; }

        .wstep--pending { border-top-color: rgba(26, 26, 26, 0.2); opacity: 0.85; }

        /* Легенда состояний workflow */
        .wf-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem var(--space-md);
            margin-top: auto;
            padding-top: var(--space-xs);
            border-top: 1px solid rgba(26, 26, 26, 0.08);
        }

        .wf-legend__item {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.72rem;
            color: var(--color-secondary);
        }

        .wf-legend__dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* Легенда фаз в верхней панели workflow (по фазам, а не по состояниям) */
        .wf-legend__swatch {
            width: 12px;
            height: 12px;
            border: 1px solid rgba(26, 26, 26, 0.2);
            flex-shrink: 0;
        }

        /* ===== Матрица «вуз × продукт» ===== */
        .matrix-scroll {
            flex: 1;
            min-height: 0;
            overflow: auto;
            border: 1px solid rgba(26, 26, 26, 0.1);
        }

        table.matrix {
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.8rem;
            background-color: var(--color-white);
        }

        table.matrix th,
        table.matrix td {
            border-right: 1px solid rgba(26, 26, 26, 0.1);
            border-bottom: 1px solid rgba(26, 26, 26, 0.1);
            padding: 0.5rem 0.65rem;
            text-align: center;
            white-space: nowrap;
        }

        table.matrix thead th {
            background-color: var(--color-primary);
            color: var(--color-white);
            font-weight: 500;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        table.matrix tbody th {
            background-color: var(--color-light);
            text-align: left;
            font-weight: 500;
            position: sticky;
            left: 0;
            z-index: 1;
            max-width: 280px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        table.matrix thead th:first-child { z-index: 3; }

        table.matrix td {
            color: var(--color-primary);
            font-weight: 500;
            cursor: default;
            height: 44px;
        }

        table.matrix td.empty {
            background-color: var(--color-white);
            color: rgba(26, 26, 26, 0.25);
        }

        table.matrix td.phase {
            border-bottom: 3px solid rgba(26, 26, 26, 0.35);
        }

        /* ===== Легенда фаз ===== */
        .legend {
            margin-top: var(--space-md);
            padding: var(--space-sm) var(--space-md);
            background-color: var(--color-light);
            border: 1px solid rgba(26, 26, 26, 0.1);
            flex-shrink: 0;
        }

        .legend__title {
            font-family: var(--font-serif);
            font-size: 1.1rem;
            margin-bottom: var(--space-sm);
        }

        .legend__items {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem var(--space-md);
        }

        .legend__item {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: var(--color-secondary);
        }

        .legend__swatch {
            width: 16px;
            height: 16px;
            border: 1px solid rgba(26, 26, 26, 0.2);
            flex-shrink: 0;
        }

        @media (max-width: 900px) {
            .dashboard { grid-template-columns: 1fr; }
            .sidebar { border-right: none; border-bottom: 1px solid rgba(26, 26, 26, 0.1); }
        }
    </style>
</head>
<body class="<?= $currentUser === null ? 'auth-body' : '' ?>">
<?php if ($currentUser === null): ?>
    <div class="auth-card">
        <h1 class="auth-card__brand">Кладезь</h1>
        <p class="auth-card__subtitle">CRM «ИТ Школа РТК» — вход в систему</p>

        <?php if ($error !== ''): ?>
            <div class="auth-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form class="auth-form" method="post" action="index.php">
            <label for="login">Логин</label>
            <input type="text" id="login" name="login" autocomplete="username" autofocus
                   value="<?= e((string) ($_POST['login'] ?? '')) ?>">

            <label for="password">Пароль</label>
            <input type="password" id="password" name="password" autocomplete="current-password">

            <label for="role">Роль</label>
            <select id="role" name="role">
                <?php foreach ($allRoles as $role): ?>
                    <option value="<?= e($role['code']) ?>"><?= e($role['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Войти</button>
        </form>
    </div>
<?php else: ?>
    <div class="dashboard">
        <header class="topbar">
            <div class="topbar__title">
                ИТ Школа РТК
                <span>CRM · двунаправленный контроль обучения</span>
            </div>
            <div class="topbar__user">
                <strong><?= e($currentUser['full_name'] ?: $currentUser['login']) ?></strong>
                <span class="badge"><?= e($currentUser['role_names'] ?? 'роль не назначена') ?></span>
                <a class="topbar__logout" href="index.php?logout=1">Выйти</a>
            </div>
        </header>

        <nav class="sidebar">
            <p class="sidebar__caption">Разделы</p>
            <ul>
                <li><a class="active" href="index.php">Главная</a></li>
                <li><a href="#">Взаимодействия</a></li>
                <li><a href="#">Workflow</a></li>
                <li><a href="#">Отчёты</a></li>
                <li><a href="#">Аудит</a></li>
            </ul>
        </nav>

        <main class="content">
            <div class="workspace">
                <!-- ===== Верхняя четверть: workflow текущего проекта ===== -->
                <section class="panel panel--workflow">
                    <div class="panel__head">
                        <div>
                            <h2 class="panel__title">Workflow текущего проекта</h2>
                            <p class="panel__subtitle">
                                Текущее взаимодействие: <strong><?= e($currentProject['title']) ?></strong>
                                <?php if ($currentProject['phase'] !== null): ?>
                                    · фаза <?= (int) $currentProject['phase']['num'] ?>
                                    «<?= e($currentProject['phase']['name']) ?>»
                                <?php endif; ?>
                            </p>
                        </div>
                        <label class="project-select">
                            Проект:
                            <select onchange="if (this.value) location.href = 'index.php?project=' + this.value;">
                                <?php foreach ($projectOptions as $opt): ?>
                                    <option value="<?= (int) $opt['id'] ?>"
                                        <?= $opt['id'] === $currentProject['id'] ? 'selected' : '' ?>>
                                        <?= e($opt['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <div class="workflow-scroll">
                        <div class="workflow-track">
                            <?php foreach ($workflow as $item): ?>
                                <?php
                                $stateClass = 'wstep--' . $item['state'];
                                $sideClass = $item['side'] === 'both' ? ' wstep__side--both' : '';
                                ?>
                                <div class="wstep <?= $stateClass ?><?= $item['isCurrent'] ? ' current' : '' ?>"
                                     title="<?= e($item['name']) ?> · <?= e($item['sideLabel']) ?> · <?= e($item['stateLabel']) ?>">
                                    <span class="wstep__num"><?= (int) $item['num'] ?></span>
                                    <span class="wstep__side<?= $sideClass ?>"><?= e($item['side']) ?></span>
                                    <?php if ($item['isCurrent']): ?><span class="wstep__flag">◆</span><?php endif; ?>
                                    <span class="wstep__name"><?= e($item['name']) ?></span>
                                    <span class="wstep__status"><?= e($item['stateLabel']) ?></span>
                                    <span class="wstep__bar"
                                          style="background-color: <?= e($item['color']) ?>33">
                                        <span class="wstep__bar-fill" style="width: <?= (int) $item['fill'] ?>%; background-color: <?= e($item['color']) ?>"></span>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="wf-legend">
                            <?php foreach ($phases as $phase): ?>
                                <span class="wf-legend__item">
                                    <span class="wf-legend__swatch" style="background-color: <?= e($phase['color']) ?>"></span>
                                    <?php if ((int) $phase['num'] > 0): ?><?= (int) $phase['num'] ?>.<?php endif; ?>
                                    <?= e($phase['name']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <!-- ===== Нижние 3/4: сетка проектов «вуз × продукт» ===== -->
                <section class="panel panel--matrix">
                    <div class="panel__head">
                        <div>
                            <h2 class="panel__title">Сетка проектов</h2>
                            <p class="panel__subtitle">
                                Строки — ВУЗы, столбцы — ИТ-продукты. Цвет клетки показывает текущую
                                фазу взаимодействия; «—» означает, что взаимодействие не начато.
                            </p>
                        </div>
                    </div>

                    <div class="matrix-scroll">
                        <table class="matrix">
                            <thead>
                                <tr>
                                    <th>Вуз \ Продукт</th>
                                    <?php foreach ($products as $product): ?>
                                        <th title="<?= e($product['name']) ?>"><?= e($product['name']) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($universities as $university): ?>
                                    <tr>
                                        <th title="<?= e($university['name']) ?>"><?= e($university['name']) ?></th>
                                        <?php foreach ($products as $product): ?>
                                            <?php
                                            $phaseId = $phaseMap[(int) $university['id']][(int) $product['id']] ?? null;
                                            $phase = $phaseId !== null ? ($phaseById[$phaseId] ?? null) : null;
                                            ?>
                                            <?php if ($phase !== null && (int) $phase['num'] > 0): ?>
                                                <td class="phase"
                                                    style="background-color: <?= e($phase['color']) ?>"
                                                    title="<?= e($university['name']) ?> · <?= e($product['name']) ?> → <?= e($phase['name']) ?>">
                                                    <?= (int) $phase['num'] ?>
                                                </td>
                                            <?php else: ?>
                                                <td class="empty">—</td>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <section class="legend">
                        <h3 class="legend__title">Легенда фаз взаимодействия</h3>
                        <div class="legend__items">
                            <?php foreach ($phases as $phase): ?>
                                <span class="legend__item">
                                    <span class="legend__swatch" style="background-color: <?= e($phase['color']) ?>"></span>
                                    <?php if ((int) $phase['num'] > 0): ?><?= (int) $phase['num'] ?>.<?php endif; ?>
                                    <?= e($phase['name']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </section>
            </div>
        </main>
    </div>
<?php endif; ?>
</body>
</html>
