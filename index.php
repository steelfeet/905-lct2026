<?php
/**
 * Точка входа CRM «ИТ Школа РТК» (прототип).
 *
 * Роли:
 *   - manager: рабочий процесс + сетка проектов с drag-and-drop листочков;
 *   - university: рабочие процессы вуза + баннер подтверждения перехода;
 *   - supervisor: интерактивный дашборд — KPI, живые графики (Chart.js),
 *     стилизованная SVG-карта России без внешних API-ключей, cross-filtering.
 *
 * Фильтры периода и типа графика влияют на ВСЕ графики и карту.
 */

session_start();

require __DIR__ . '/db.php';

$pdo = db();

// =============================================================================
// AJAX / POST actions
// =============================================================================

$action = $_GET['action'] ?? '';

function phase_requires_confirmation(int $num): bool
{
    return $num > 0 && $num % 2 === 0;
}

if ($action === 'move_phase' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $projectId     = (int) ($input['project_id'] ?? 0);
    $targetPhaseId = (int) ($input['target_phase_id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM interactions WHERE id = :id');
    $stmt->execute([':id' => $projectId]);
    $interaction = $stmt->fetch();

    if (!$interaction) {
        echo json_encode(['ok' => false, 'error' => 'Проект не найден']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM interaction_phases WHERE id = :id');
    $stmt->execute([':id' => $targetPhaseId]);
    $targetPhase = $stmt->fetch();

    if (!$targetPhase) {
        echo json_encode(['ok' => false, 'error' => 'Фаза не найдена']);
        exit;
    }

    $currentPhaseId = $interaction['phase_id'] !== null ? (int) $interaction['phase_id'] : 0;
    $currentPhase = null;
    if ($currentPhaseId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM interaction_phases WHERE id = :id');
        $stmt->execute([':id' => $currentPhaseId]);
        $currentPhase = $stmt->fetch();
    }
    $curNum = $currentPhase ? (int) $currentPhase['num'] : 0;
    $tgtNum = (int) $targetPhase['num'];

    if (abs($tgtNum - $curNum) !== 1) {
        echo json_encode(['ok' => false, 'error' => 'Перемещать можно только на соседнюю фазу']);
        exit;
    }

    if (phase_requires_confirmation($tgtNum)) {
        $_SESSION['pending_phase_change'] = [
            'project_id'        => $projectId,
            'from_phase_id'     => $currentPhaseId,
            'target_phase_id'   => $targetPhaseId,
            'target_phase_num'  => $tgtNum,
            'target_phase_name' => $targetPhase['name'],
            'created_at'        => time(),
        ];
        $_SESSION['university_notification'] = [
            'type'       => 'pending',
            'message'    => 'ИТ Школа РТК предлагает перевести проект в фазу «'
                          . $targetPhase['name'] . '». Требуется ваше подтверждение.',
            'created_at' => time(),
        ];
        echo json_encode(['ok' => true, 'pending' => true]);
    } else {
        $stmt = $pdo->prepare('UPDATE interactions SET phase_id = :pid WHERE id = :id');
        $stmt->execute([':pid' => $targetPhaseId, ':id' => $projectId]);

        unset($_SESSION['pending_phase_change']);
        $_SESSION['university_notification'] = [
            'type'       => 'phase_changed',
            'message'    => 'Проект переведён в фазу «' . $targetPhase['name'] . '».',
            'created_at' => time(),
        ];
        echo json_encode(['ok' => true, 'pending' => false]);
    }
    exit;
}

if (($action === 'confirm_phase' || $action === 'reject_phase') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_SESSION['pending_phase_change'])) {
        $pending = $_SESSION['pending_phase_change'];

        if ($action === 'confirm_phase') {
            $stmt = $pdo->prepare('UPDATE interactions SET phase_id = :pid WHERE id = :id');
            $stmt->execute([':pid' => $pending['target_phase_id'], ':id' => $pending['project_id']]);

            $_SESSION['manager_notification'] = [
                'type'       => 'confirmed',
                'message'    => 'Вуз подтвердил перевод в фазу «' . $pending['target_phase_name'] . '».',
                'created_at' => time(),
            ];
        } else {
            $_SESSION['manager_notification'] = [
                'type'       => 'rejected',
                'message'    => 'Вуз отклонил перевод в фазу «' . $pending['target_phase_name'] . '».',
                'created_at' => time(),
            ];
        }

        unset($_SESSION['pending_phase_change']);
        unset($_SESSION['university_notification']);
    }
    header('Location: index.php?role=university');
    exit;
}

// =============================================================================
// Обычный поток
// =============================================================================

$error = '';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === '') {
    $login    = trim($_POST['login'] ?? '');
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

$allRoles     = $pdo->query('SELECT code, name FROM roles ORDER BY id')->fetchAll();
$universities = $pdo->query('SELECT id, name FROM universities ORDER BY id')->fetchAll();
$products     = $pdo->query('SELECT id, name FROM it_products ORDER BY id')->fetchAll();
$phases       = $pdo->query('SELECT id, code, num, name, color FROM interaction_phases ORDER BY num')->fetchAll();
$interactions = $pdo->query('SELECT id, university_id, product_id, phase_id FROM interactions')->fetchAll();

$phaseMap = [];
$interactionIdMap = [];
foreach ($interactions as $row) {
    $phaseMap[(int) $row['university_id']][(int) $row['product_id']] =
        $row['phase_id'] !== null ? (int) $row['phase_id'] : null;
    $interactionIdMap[(int) $row['university_id']][(int) $row['product_id']] = (int) $row['id'];
}

$phaseById = [];
$phaseByNum = [];
foreach ($phases as &$p) {
    $p['requires_confirmation'] = phase_requires_confirmation((int) $p['num']);
    $phaseById[(int) $p['id']] = $p;
    $phaseByNum[(int) $p['num']] = $p;
}
unset($p);

$phaseMeta = [];
foreach ($phases as $p) {
    $phaseMeta[] = [
        'id'                    => (int) $p['id'],
        'num'                   => (int) $p['num'],
        'name'                  => $p['name'],
        'color'                 => $p['color'],
        'requires_confirmation' => (bool) $p['requires_confirmation'],
    ];
}

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

$universityMarks = [
    1  => ['code' => 'К(П)ФУ', 'crest' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/8/85/Seal_of_KFU.svg/60px-Seal_of_KFU.svg.png'],
    2  => ['code' => 'МФТИ',   'crest' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/2/2c/Mipt_logo.svg/60px-Mipt_logo.svg.png'],
    3  => ['code' => 'МИФИ',   'crest' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/e/e5/MEI_logo.svg/60px-MEI_logo.svg.png'],
    4  => ['code' => 'СПбГУ',  'crest' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/3/3a/Seal_of_Saint_Petersburg_State_University.svg/60px-Seal_of_Saint_Petersburg_State_University.svg.png'],
    5  => ['code' => 'НГУ',    'crest' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/1/1c/Novosibirsk_State_University_logo.svg/60px-Novosibirsk_State_University_logo.svg.png'],
    6  => ['code' => 'УрФУ',   'crest' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/9/96/Ural_Federal_University_Logo.svg/60px-Ural_Federal_University_Logo.svg.png'],
    7  => ['code' => 'ЮФУ',    'crest' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/9/98/Southern_Federal_University_logo.svg/60px-Southern_Federal_University_logo.svg.png'],
    8  => ['code' => 'ДВФУ',   'crest' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/1/11/Far_Eastern_Federal_University_Logo.svg/60px-Far_Eastern_Federal_University_Logo.svg.png'],
    9  => ['code' => 'ТПУ',    'crest' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/1/1e/TPU_logo.svg/60px-TPU_logo.svg.png'],
    10 => ['code' => 'КНИТУ-КАИ', 'crest' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/8/83/Kazan_National_Research_Technical_University_logo.svg/60px-Kazan_National_Research_Technical_University_logo.svg.png'],
];

$productShortNames = [
    'Курсы по использованию ИИ в обучении'               => 'ИИ в обучении',
    'Основы Data Science для преподавателей'             => 'Data Science',
    'Кибергигиена и информационная безопасность'         => 'Инфобез',
    'Облачные технологии: практика применения'           => 'Облака',
    'Разработка на Python: базовый курс'                 => 'Python',
    'Машинное обучение в задачах телекома'               => 'ML в телекоме',
    'Цифровая трансформация образования'                 => 'Цифр. трансформация',
    'Программирование для школьников'                    => 'Прогиб для школ',
    'Аналитика данных: визуализация и отчётность'        => 'Аналитика данных',
    'IoT: умные устройства и сети'                       => 'IoT',
    'ИТ-программа «Большие данные и ИИ»'                 => 'БД и ИИ',
    'ИТ-программа «Информационная безопасность»'         => 'Инфобез (прогр.)',
    'ИТ-программа «Облачные вычисления и DevOps»'        => 'Cloud & DevOps',
    'ИТ-программа «Сети связи и телеком-системы»'        => 'Сети и телеком',
    'ИТ-программа «Цифровые платформы и лоу-код разработка»' => 'Цифр. платформы',
];

function uni_initial(string $name): string
{
    $name = ltrim($name);
    if ($name === '') {
        return '?';
    }

    $byte = ord($name[0]);
    if ($byte < 0x80) {
        $len = 1;
    } elseif ($byte < 0xF0) {
        $len = $byte < 0xE0 ? 2 : 3;
    } else {
        $len = 4;
    }

    $first = substr($name, 0, $len);

    if ($len > 1) {
        $last = $len - 1;
        $tail = ord($first[$last]);
        if ($tail >= 0xB0 && $tail <= 0xDF) {
            $first[$last] = chr($tail - 0x20);
        }
        return $first;
    }

    return strtoupper($first);
}

foreach ($universities as &$university) {
    $mark = $universityMarks[(int) $university['id']] ?? null;
    $university['short'] = $mark !== null ? $mark['code'] : $university['name'];
    $university['crest'] = $mark !== null ? $mark['crest'] : null;
    $university['initial'] = uni_initial($university['name']);
}
unset($university);

foreach ($products as &$product) {
    $product['short'] = $productShortNames[$product['name']] ?? $product['name'];
}
unset($product);

// ===== Рабочий процесс =====================================================

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

$projectOptions = $pdo->query(
    'SELECT i.id, i.phase_id, i.university_id, i.product_id,
            u.name AS university_name, p.name AS product_name
     FROM interactions i
     JOIN universities u ON u.id = i.university_id
     JOIN it_products  p ON p.id = i.product_id
     ORDER BY i.id'
)->fetchAll();

$shortByUniversityId = [];
foreach ($universities as $university) {
    $shortByUniversityId[(int) $university['id']] = $university['short'];
}
$shortByProductId = [];
foreach ($products as $product) {
    $shortByProductId[(int) $product['id']] = $product['short'];
}

foreach ($projectOptions as &$opt) {
    $opt['title'] = $opt['university_name'] . ' · ' . $opt['product_name'];
    $opt['short'] =
        ($shortByUniversityId[(int) $opt['university_id']] ?? $opt['university_name'])
        . ' · ' . ($shortByProductId[(int) $opt['product_id']] ?? $opt['product_name']);
    $opt['phase_num'] = ($opt['phase_id'] !== null && isset($phaseById[(int) $opt['phase_id']]))
        ? (int) $phaseById[(int) $opt['phase_id']]['num']
        : 0;
    $opt['phase'] = ($opt['phase_id'] !== null)
        ? ($phaseById[(int) $opt['phase_id']] ?? null)
        : null;
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

$currentProject = ['id' => $currentProjectId, 'title' => 'нет активных проектов', 'short' => '—', 'phase' => null];
$currentPhaseNum = 0;

foreach ($projectOptions as $opt) {
    if ((int) $opt['id'] === $currentProjectId) {
        $currentProject['title'] = $opt['university_name'] . ' · ' . $opt['product_name'];
        $currentProject['short'] = $opt['short'];
        $currentProject['phase'] = $opt['phase'];
        $currentPhaseNum = (int) $opt['phase_num'];
        break;
    }
}

$pendingChange = $_SESSION['pending_phase_change'] ?? null;
$pendingByProject = [];
if ($pendingChange !== null) {
    $pendingByProject[(int) $pendingChange['project_id']] = $pendingChange;
}

$stepStates = [
    'completed'        => ['label' => 'Завершён',        'color' => '#16a34a', 'fill' => 100],
    'in_progress'      => ['label' => 'В работе',        'color' => '#2563eb', 'fill' => 60],
    'waiting_internal' => ['label' => 'Ждёт ИТ Школу',   'color' => '#8b6914', 'fill' => 40],
    'waiting_external' => ['label' => 'Ждёт вуз',        'color' => '#d97706', 'fill' => 40],
    'needs_revision'   => ['label' => 'Требует доработки', 'color' => '#dc2626', 'fill' => 25],
    'pending'          => ['label' => 'Ожидает',         'color' => '#d1d5db', 'fill' => 0],
];

$currentStates = ['in_progress', 'waiting_external', 'needs_revision'];

$sideLabels = [
    'internal' => 'Внутренний контур — ИТ Школа РТК',
    'external' => 'Внешний контур — вуз',
    'both'     => 'Совместный шаг — ИТ Школа РТК + вуз',
];

function build_project_workflow(
    array $workflowSteps,
    array $sideLabels,
    array $stepStates,
    array $currentStates,
    int $phaseNum,
    int $projectId
): array {
    $currentState = $currentStates[$projectId % count($currentStates)];
    $workflow = [];

    foreach ($workflowSteps as $step) {
        if ($step['num'] < $phaseNum) {
            $state = 'completed';
        } elseif ($step['num'] === $phaseNum) {
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
            'isCurrent'  => $step['num'] === $phaseNum,
        ];
    }

    return $workflow;
}

$workflow = build_project_workflow(
    $workflowSteps,
    $sideLabels,
    $stepStates,
    $currentStates,
    $currentPhaseNum,
    $currentProjectId
);

// =============================================================================
// Данные для дашборда руководителя
// =============================================================================

$dashPhaseCounts = [];
foreach ($phases as $p) {
    $dashPhaseCounts[(int) $p['id']] = 0;
}
$dashUniCounts = [];
foreach ($universities as $u) {
    $dashUniCounts[(int) $u['id']] = 0;
}
$dashUniMaxPhaseNum = [];
$dashUniPhaseCount = [];
$totalInteractions = 0;
$activeInteractions = 0;
$sumPhase = 0;

foreach ($interactions as $row) {
    $totalInteractions++;
    $pid = $row['phase_id'] !== null ? (int) $row['phase_id'] : 0;
    $uid = (int) $row['university_id'];

    if ($pid > 0 && isset($dashPhaseCounts[$pid])) {
        $dashPhaseCounts[$pid]++;
    }
    if (isset($dashUniCounts[$uid])) {
        $dashUniCounts[$uid]++;
    }

    $num = ($pid > 0 && isset($phaseById[$pid])) ? (int) $phaseById[$pid]['num'] : 0;
    $sumPhase += $num;
    if ($num > 0 && $num < 14) {
        $activeInteractions++;
    }

    if (!isset($dashUniMaxPhaseNum[$uid]) || $num > $dashUniMaxPhaseNum[$uid]) {
        $dashUniMaxPhaseNum[$uid] = $num;
    }

    if ($pid > 0) {
        if (!isset($dashUniPhaseCount[$uid])) {
            $dashUniPhaseCount[$uid] = [];
        }
        $dashUniPhaseCount[$uid][$pid] = ($dashUniPhaseCount[$uid][$pid] ?? 0) + 1;
    }
}

$avgPhase = $totalInteractions > 0 ? round($sumPhase / $totalInteractions, 1) : 0;

$chartPhases = array_values(array_filter($phases, function ($p) { return (int) $p['num'] > 0; }));

$topUniversities = [];
foreach ($universities as $u) {
    $uid = (int) $u['id'];
    $topUniversities[] = [
        'id'    => $uid,
        'short' => $u['short'],
        'full'  => $u['name'],
        'count' => $dashUniCounts[$uid] ?? 0,
    ];
}
usort($topUniversities, function ($a, $b) { return $b['count'] - $a['count']; });
$topUniversities = array_slice($topUniversities, 0, 8);

// ===== Помесячные mock-данные с разбивкой «вуз × фаза» =====================
$monthsRu = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
$nowMonth = (int) date('n');

$chartNewProjectsByMonth = [];
$monthly = [];

for ($i = 11; $i >= 0; $i--) {
    $m = $nowMonth - $i;
    while ($m <= 0) { $m += 12; }
    $seed = ($i + 3) * 41;

    $byUniPhase = [];
    foreach ($universities as $u) {
        $uid = (int) $u['id'];
        $byUniPhase[(string) $uid] = [];
        foreach ($chartPhases as $p) {
            $pid = (int) $p['id'];
            $base = ($seed * 7 + $uid * 11 + $pid * 13) % 4;
            $recency = (12 - $i);
            $val = $base + (int) ($recency / 4);
            if ($val > 0) {
                $byUniPhase[(string) $uid][(string) $pid] = $val;
            }
        }
    }

    $chartNewProjectsByMonth[] = 1 + ($seed % 4);

    $monthly[] = [
        'label'      => $monthsRu[$m - 1],
        'byUniPhase' => $byUniPhase,
    ];
}

// ===== Карта: проекция lat/lng → SVG =======================================
$mapViewWidth  = 1000;
$mapViewHeight = 500;
$lngMin = 19.0;  $lngMax = 180.0;
$latMin = 41.0;  $latMax = 82.0;

function project_point(float $lat, float $lng, int $w, int $h, float $lngMin, float $lngMax, float $latMin, float $latMax): array {
    $x = ($lng - $lngMin) / ($lngMax - $lngMin) * $w;
    $y = ($latMax - $lat) / ($latMax - $latMin) * $h;
    return [round($x, 1), round($y, 1)];
}

$universityCoords = [
    1  => [55.7906, 49.1221],
    2  => [55.9297, 37.5213],
    3  => [55.6497, 37.6642],
    4  => [59.8822, 29.8258],
    5  => [54.8473, 83.0930],
    6  => [56.8439, 60.6526],
    7  => [47.2225, 39.7188],
    8  => [43.1155, 131.8855],
    9  => [56.4653, 84.9508],
    10 => [55.8337, 49.1254],
];

$mapPoints = [];
foreach ($universities as $u) {
    $uid = (int) $u['id'];
    if (!isset($universityCoords[$uid])) continue;

    $maxNum   = $dashUniMaxPhaseNum[$uid] ?? 0;
    $maxPhase = $maxNum > 0 ? ($phaseByNum[$maxNum] ?? null) : null;
    $color    = $maxPhase !== null ? $maxPhase['color'] : '#9ca3af';

    [$sx, $sy] = project_point(
        $universityCoords[$uid][0],
        $universityCoords[$uid][1],
        $mapViewWidth, $mapViewHeight,
        $lngMin, $lngMax, $latMin, $latMax
    );

    $mapPoints[] = [
        'id'       => $uid,
        'name'     => $u['name'],
        'short'    => $u['short'],
        'x'        => $sx,
        'y'        => $sy,
        'color'    => $color,
        'phase'    => $maxPhase !== null ? $maxPhase['name'] : 'Не начато',
        'projects' => $dashUniCounts[$uid] ?? 0,
    ];
}

// ===== Payload для JS-дашборда =============================================
$dashPayload = [
    'phases' => array_map(function ($p) {
        return [
            'id'    => (int) $p['id'],
            'num'   => (int) $p['num'],
            'name'  => $p['name'],
            'color' => $p['color'],
        ];
    }, $chartPhases),
    'universities' => array_map(function ($u) {
        return [
            'id'    => $u['id'],
            'short' => $u['short'],
            'name'  => $u['full'],
        ];
    }, $topUniversities),
    'allUniversities' => array_map(function ($u) {
        return [
            'id'    => (int) $u['id'],
            'short' => $u['short'],
            'name'  => $u['name'],
        ];
    }, $universities),
    'mapPoints'   => $mapPoints,
    'monthly'     => $monthly,
    'newProjects' => $chartNewProjectsByMonth,
    'kpi' => [
        'total'    => $totalInteractions,
        'active'   => $activeInteractions,
        'unis'     => count($universities),
        'products' => count($products),
        'avgPhase' => $avgPhase,
        'maxPhase' => count($phases) - 1,
    ],
];

// ===== Переключатель ролей ==================================================

$viewRole = $_GET['role'] ?? 'manager';
if (!in_array($viewRole, ['manager', 'university', 'supervisor'], true)) {
    $viewRole = 'manager';
}

$viewUniId = (int) ($_GET['uni_id'] ?? 0);
if ($viewRole === 'university' && $viewUniId === 0 && $universities !== []) {
    $viewUniId = (int) $universities[0]['id'];
}

$selectedUni = null;
foreach ($universities as $u) {
    if ((int) $u['id'] === $viewUniId) {
        $selectedUni = $u;
        break;
    }
}

$uniProjects = [];
if ($viewRole === 'university') {
    foreach ($projectOptions as $opt) {
        if ((int) $opt['university_id'] === $viewUniId) {
            $opt['workflow'] = build_project_workflow(
                $workflowSteps,
                $sideLabels,
                $stepStates,
                $currentStates,
                (int) $opt['phase_num'],
                (int) $opt['id']
            );
            $uniProjects[] = $opt;
        }
    }
}

$universityNotification = $_SESSION['university_notification'] ?? null;

$managerNotification = $_SESSION['manager_notification'] ?? null;
if ($managerNotification !== null) {
    unset($_SESSION['manager_notification']);
}

$managerLabel = $currentUser !== null
    ? ($currentUser['full_name'] ?: $currentUser['login'])
    : 'Павел';
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

        .auth-card__brand { font-size: 2rem; font-weight: 600; text-align: center; margin-bottom: var(--space-xs); }
        .auth-card__subtitle { font-size: 0.9rem; color: var(--color-accent); text-align: center; margin-bottom: var(--space-lg); font-weight: 500; }

        .auth-form label { display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: var(--space-xs); color: var(--color-secondary); }

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

        .auth-form input:focus, .auth-form select:focus { outline: none; border-color: var(--color-accent); }

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

        .auth-form button:hover { background-color: var(--color-accent-light); }

        .auth-error {
            background-color: #fdf3f2;
            color: #9b2c1f;
            border-left: 2px solid #9b2c1f;
            padding: 0.75rem var(--space-sm);
            font-size: 0.85rem;
            margin-bottom: var(--space-md);
        }

        .dashboard { display: flex; flex-direction: column; min-height: 100vh; }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: var(--space-sm);
            padding: var(--space-md) var(--space-lg);
            background-color: var(--color-white);
            border-bottom: 1px solid rgba(26, 26, 26, 0.1);
        }

        .topbar__title { font-family: var(--font-serif); font-size: 1.5rem; font-weight: 600; }
        .topbar__title span {
            color: var(--color-accent);
            font-size: 0.85rem;
            font-family: var(--font-sans);
            font-weight: 400;
            display: block;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .topbar__user { display: flex; align-items: center; gap: var(--space-sm); font-size: 0.9rem; }

        .role-switch { display: inline-flex; border: 1px solid rgba(26, 26, 26, 0.18); background-color: var(--color-white); }

        .role-switch__btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.45rem 0.85rem;
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--color-secondary);
            background-color: var(--color-white);
            border-right: 1px solid rgba(26, 26, 26, 0.18);
            transition: var(--transition-base);
            white-space: nowrap;
        }

        .role-switch__btn:last-child { border-right: none; }
        .role-switch__btn:hover { background-color: rgba(139, 105, 20, 0.08); color: var(--color-primary); }
        .role-switch__btn.active { background-color: var(--color-accent); color: var(--color-white); }
        .role-switch__btn.active:hover { color: var(--color-white); }

        .role-switch__dot { width: 8px; height: 8px; border-radius: 50%; background-color: var(--color-accent); flex-shrink: 0; }
        .role-switch__btn.active .role-switch__dot { background-color: var(--color-white); }

        .topbar__logout {
            padding: 0.5rem var(--space-md);
            border: 1px solid rgba(26, 26, 26, 0.2);
            font-size: 0.85rem;
            transition: var(--transition-base);
        }
        .topbar__logout:hover { border-color: var(--color-accent); background-color: rgba(139, 105, 20, 0.05); }

        .mainnav {
            display: flex;
            align-items: center;
            gap: var(--space-xs);
            padding: 0 var(--space-lg);
            background-color: var(--color-light);
            border-bottom: 1px solid rgba(26, 26, 26, 0.1);
        }

        .mainnav__caption {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--color-tertiary);
            margin-right: var(--space-sm);
        }

        .mainnav a {
            display: block;
            padding: 0.65rem var(--space-sm);
            font-size: 0.9rem;
            border-bottom: 2px solid transparent;
        }

        .mainnav a:hover, .mainnav a.active {
            background-color: rgba(139, 105, 20, 0.1);
            border-bottom-color: var(--color-accent);
            color: var(--color-primary);
        }

        .content { flex: 1; min-height: 0; padding: var(--space-lg); }

        .notification {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--space-md);
            padding: 12px 16px;
            margin-bottom: var(--space-md);
            border-left: 4px solid;
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .notification--pending { background-color: #fffbeb; border-color: #d97706; color: #78350f; }
        .notification--info    { background-color: #eff6ff; border-color: #2563eb; color: #1e3a8a; }
        .notification--success { background-color: #f0fdf4; border-color: #16a34a; color: #14532d; }
        .notification--danger  { background-color: #fef2f2; border-color: #dc2626; color: #7f1d1d; }

        .notification__icon { font-size: 1.2rem; flex-shrink: 0; }
        .notification__body { flex: 1; }
        .notification__actions { display: flex; gap: 8px; flex-shrink: 0; }

        .notification__btn {
            padding: 6px 14px;
            font-size: 0.8rem;
            font-weight: 500;
            border: 1px solid currentColor;
            background: transparent;
            cursor: pointer;
            font-family: var(--font-sans);
            transition: var(--transition-base);
        }

        .notification__btn--primary { background-color: #d97706; color: #fff; border-color: #d97706; }
        .notification__btn--primary:hover { background-color: #b45309; border-color: #b45309; }
        .notification__btn--danger { color: #dc2626; }
        .notification__btn--danger:hover { background-color: rgba(220, 38, 38, 0.08); }

        .workspace { display: flex; flex-direction: column; gap: var(--space-md); min-height: calc(100vh - 46px); }
        .workspace--uni { min-height: calc(100vh - 46px - 62px); }
        .workspace--uni .panel--workflow { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
        .workspace--dash { min-height: calc(100vh - 46px - 62px); }

        .panel { background-color: var(--color-white); border: 1px solid rgba(26, 26, 26, 0.1); padding: var(--space-md); }
        .panel--workflow { flex: 0 0 auto; min-height: 230px; display: flex; flex-direction: column; }
        .panel--matrix { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
        .panel--dashboard { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }

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

        .panel__hint {
            display: inline-block;
            margin-top: 6px;
            font-size: 0.78rem;
            color: var(--color-accent-dark);
            background-color: rgba(139, 105, 20, 0.08);
            border: 1px dashed var(--color-accent);
            padding: 3px 8px;
        }

        .project-select { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; }

        .project-select select {
            padding: 0.4rem 0.6rem;
            border: 1px solid rgba(26, 26, 26, 0.15);
            background-color: var(--color-white);
            font-family: var(--font-sans);
            font-size: 0.85rem;
            max-width: 380px;
        }

        .workflow-scroll { flex: 1; min-height: 0; display: flex; flex-direction: column; }
        .workflow-scroll--uni { overflow-y: auto; padding-right: 4px; }

        .workflow-track {
            display: flex;
            align-items: stretch;
            gap: 4px;
            padding: 4px 2px 8px;
        }

        .wstep {
            position: relative;
            flex: 1 1 0;
            min-width: 0;
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

        .wstep__num { position: absolute; top: 4px; left: 6px; font-size: 0.75rem; font-weight: 600; color: var(--color-tertiary); }
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
            font-size: 0.66rem;
            line-height: 1.2;
            color: var(--color-secondary);
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
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

        .wstep__flag { position: absolute; top: -7px; right: 22px; font-size: 0.6rem; font-weight: 700; color: var(--color-accent-dark); }

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

        .workflow-track--compact .wstep { min-height: 82px; padding: 22px 4px 4px; }
        .workflow-track--compact .wstep__name { font-size: 0.6rem; -webkit-line-clamp: 2; }
        .workflow-track--compact .wstep__status { font-size: 0.55rem; padding: 1px 4px; }
        .workflow-track--compact .wstep__side { font-size: 0.55rem; padding: 1px 4px; }
        .workflow-track--compact .wstep__num { font-size: 0.65rem; }

        @media (max-width: 1100px) {
            .workflow-track { overflow-x: auto; }
            .wstep { flex: 0 0 96px; }
        }

        .wf-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem var(--space-md);
            margin-top: auto;
            padding-top: var(--space-xs);
            border-top: 1px solid rgba(26, 26, 26, 0.08);
        }

        .wf-legend__item { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.72rem; color: var(--color-secondary); }
        .wf-legend__swatch { width: 12px; height: 12px; border: 1px solid rgba(26, 26, 26, 0.2); flex-shrink: 0; }

        .uni-workflow { padding: 10px 0 12px; border-bottom: 1px solid rgba(26, 26, 26, 0.08); }
        .uni-workflow:first-child { padding-top: 0; }
        .uni-workflow:last-child { border-bottom: none; padding-bottom: 0; }

        .uni-workflow__head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: var(--space-sm);
            flex-wrap: wrap;
            margin-bottom: 4px;
        }

        .uni-workflow__title { font-size: 0.85rem; font-weight: 600; color: var(--color-primary); }
        .uni-workflow__meta { font-size: 0.72rem; color: var(--color-tertiary); }

        .uni-empty {
            padding: var(--space-md);
            font-size: 0.9rem;
            color: var(--color-tertiary);
            text-align: center;
            background-color: var(--color-light);
            border: 1px dashed rgba(26, 26, 26, 0.15);
        }

        .matrix-scroll { flex: 1; min-height: 0; overflow: auto; border: 1px solid rgba(26, 26, 26, 0.1); }

        body.is-dragging .matrix-scroll { perspective: 1200px; }

        table.matrix {
            width: 100%;
            min-width: 880px;
            table-layout: fixed;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.8rem;
            background-color: var(--color-white);
            transition: transform 0.25s ease-out;
            transform-origin: 50% 50%;
        }

        body.is-dragging table.matrix { transform: rotateX(7deg); }

        table.matrix th, table.matrix td {
            border-right: 1px solid rgba(26, 26, 26, 0.1);
            border-bottom: 1px solid rgba(26, 26, 26, 0.1);
            padding: 3px;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        table.matrix thead th {
            background-color: var(--color-primary);
            color: var(--color-white);
            font-weight: 500;
            position: sticky;
            top: 0;
            z-index: 2;
            padding: 0.4rem 0.3rem;
        }

        table.matrix tbody th {
            background-color: var(--color-light);
            text-align: left;
            font-weight: 500;
            position: sticky;
            left: 0;
            z-index: 1;
            padding: 0.4rem 0.3rem;
        }

        table.matrix tbody tr.is-selected th { background-color: rgba(139, 105, 20, 0.12); box-shadow: inset 3px 0 0 var(--color-accent); }

        table.matrix .uni { display: flex; align-items: center; gap: 0.4rem; min-width: 0; }

        table.matrix .uni__crest {
            width: 20px; height: 20px;
            object-fit: contain;
            flex-shrink: 0;
            background-color: var(--color-white);
            border: 1px solid rgba(26, 26, 26, 0.12);
            border-radius: 50%;
            padding: 1px;
        }

        table.matrix .uni__initial {
            width: 20px; height: 20px;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.65rem;
            font-weight: 600;
            color: var(--color-white);
            background-color: var(--color-accent);
            border-radius: 50%;
        }

        table.matrix .uni__code { overflow: hidden; text-overflow: ellipsis; }
        table.matrix thead th:first-child { z-index: 3; }

        table.matrix td {
            color: var(--color-primary);
            font-weight: 500;
            cursor: default;
            height: 52px;
            position: relative;
        }

        table.matrix td.empty {
            background-color: var(--color-white);
            color: rgba(26, 26, 26, 0.25);
            padding: 0.4rem 0.3rem;
        }

        table.matrix td.phase { padding: 3px; }

        table.matrix td.phase .sticker-cell {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            min-height: 42px;
            background-color: var(--phase-color, #fde68a);
            background-image: linear-gradient(
                180deg,
                rgba(255, 255, 255, 0.35) 0%,
                rgba(255, 255, 255, 0.08) 45%,
                rgba(0, 0, 0, 0.10) 100%
            );
            border-radius: 2px;
            box-shadow:
                0 1px 2px rgba(0, 0, 0, 0.15),
                0 2px 6px rgba(0, 0, 0, 0.08);
            font-weight: 700;
            color: #ffffff;
            font-size: 1rem;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.45);
            transition: transform 0.2s ease-out, box-shadow 0.2s ease-out, opacity 0.2s;
            overflow: hidden;
        }

        table.matrix td.phase .sticker-cell::before {
            content: '';
            position: absolute;
            top: -2px;
            left: 50%;
            width: 22px;
            height: 8px;
            margin-left: -11px;
            background: rgba(255, 255, 255, 0.55);
            border: 1px solid rgba(0, 0, 0, 0.04);
            transform: rotate(-2deg);
            border-radius: 1px;
        }

        table.matrix td.clickable { cursor: grab; }
        table.matrix td.clickable:active { cursor: grabbing; }

        table.matrix td.clickable:hover .sticker-cell {
            transform: translateY(-2px) rotate(-1.5deg);
            box-shadow:
                0 3px 6px rgba(0, 0, 0, 0.18),
                0 6px 14px rgba(0, 0, 0, 0.12);
        }

        table.matrix td.clickable:focus-visible { outline: 2px solid var(--color-accent-dark); outline-offset: -2px; }

        table.matrix td.is-current-project .sticker-cell {
            box-shadow:
                0 3px 6px rgba(0, 0, 0, 0.2),
                0 8px 18px rgba(0, 0, 0, 0.16),
                inset 0 0 0 3px var(--color-accent-dark);
            transform: rotate(-2deg) scale(1.05);
        }

        table.matrix td.is-current-project .sticker-cell::after {
            content: '◆';
            position: absolute;
            top: 1px;
            right: 3px;
            font-size: 0.55rem;
            line-height: 1;
            color: #ffffff;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.55);
        }

        table.matrix td.is-pending .sticker-cell {
            animation: pulse-pending 1.8s ease-in-out infinite;
            outline: 2px dashed #b45309;
            outline-offset: -2px;
        }

        @keyframes pulse-pending {
            0%, 100% { box-shadow: 0 1px 2px rgba(0,0,0,0.15), 0 0 0 0 rgba(180, 83, 9, 0.5); }
            50%      { box-shadow: 0 1px 2px rgba(0,0,0,0.15), 0 0 0 10px rgba(180, 83, 9, 0); }
        }

        body.is-dragging table.matrix td.phase .sticker-cell {
            opacity: 0.35;
            filter: grayscale(0.4);
            transition: opacity 0.2s, filter 0.2s;
        }

        body.is-dragging table.matrix td.is-drag-source {
            z-index: 100;
            overflow: visible;
        }

        body.is-dragging table.matrix td.is-drag-source .sticker-cell {
            opacity: 1;
            filter: none;
            transform: rotate(-7deg) scale(1.25) translateZ(60px);
            box-shadow:
                0 10px 20px rgba(0, 0, 0, 0.28),
                0 24px 48px rgba(0, 0, 0, 0.22);
        }

        .phase-pocket {
            position: fixed;
            z-index: 10000;
            width: 240px;
            padding: 10px 12px 12px;
            background: var(--color-white);
            border: 2px dashed var(--color-accent);
            border-radius: 6px;
            box-shadow:
                0 6px 16px rgba(0, 0, 0, 0.18),
                0 16px 40px rgba(0, 0, 0, 0.15);
            font-family: var(--font-sans);
            cursor: copy;
            opacity: 0;
            transform: translateY(8px) scale(0.94);
            transition: transform 0.2s ease-out, box-shadow 0.2s, background-color 0.2s, border-color 0.2s;
            pointer-events: auto;
            user-select: none;
        }

        .phase-pocket.is-visible { opacity: 1; transform: translateY(0) scale(1); }

        .phase-pocket.is-hover {
            background: rgba(139, 105, 20, 0.1);
            border-style: solid;
            transform: translateY(0) scale(1.05);
            box-shadow:
                0 10px 24px rgba(0, 0, 0, 0.22),
                0 20px 48px rgba(0, 0, 0, 0.18);
        }

        .phase-pocket__label {
            font-size: 0.62rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--color-tertiary);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .phase-pocket__label-arrow { font-size: 0.9rem; font-weight: 700; color: var(--color-accent-dark); line-height: 1; }

        .phase-pocket__body {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--color-primary);
            line-height: 1.25;
        }

        .phase-pocket__num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            color: #fff;
            font-size: 0.75rem;
            font-weight: 700;
            flex-shrink: 0;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        }

        .phase-pocket__name { flex: 1; min-width: 0; }
        .phase-pocket__lock { margin-left: auto; font-size: 0.9rem; flex-shrink: 0; }

        .phase-pocket__pin {
            position: absolute;
            left: 50%;
            width: 10px;
            height: 10px;
            margin-left: -5px;
            background: var(--color-white);
            border: 2px dashed var(--color-accent);
            border-radius: 50%;
        }

        .phase-pocket--prev .phase-pocket__pin {
            bottom: -7px;
            border-top-color: transparent;
            border-right-color: transparent;
            transform: rotate(45deg);
        }

        .phase-pocket--next .phase-pocket__pin {
            top: -7px;
            border-bottom-color: transparent;
            border-left-color: transparent;
            transform: rotate(45deg);
        }

        body.role-university table.matrix td.clickable { cursor: default; }

        .legend {
            margin-top: var(--space-md);
            padding: var(--space-sm) var(--space-md);
            background-color: var(--color-light);
            border: 1px solid rgba(26, 26, 26, 0.1);
            flex-shrink: 0;
        }

        .legend__title { font-family: var(--font-serif); font-size: 1.1rem; margin-bottom: var(--space-sm); }
        .legend__items { display: flex; flex-wrap: wrap; gap: 0.5rem var(--space-md); }
        .legend__item { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; color: var(--color-secondary); }
        .legend__swatch { width: 16px; height: 16px; border: 1px solid rgba(26, 26, 26, 0.2); flex-shrink: 0; border-radius: 2px; }

        /* ===== Дашборд руководителя ===== */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: var(--space-sm);
            margin-bottom: var(--space-md);
        }

        .kpi-card {
            position: relative;
            padding: 16px 18px;
            background: linear-gradient(135deg, #fafaf7 0%, #f0ece0 100%);
            border: 1px solid rgba(139, 105, 20, 0.15);
            border-left: 3px solid var(--color-accent);
            overflow: hidden;
            transition: transform 0.2s ease-out, box-shadow 0.2s ease-out;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(139, 105, 20, 0.12);
        }

        .kpi-card::after {
            content: '';
            position: absolute;
            right: -30px;
            bottom: -30px;
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(139, 105, 20, 0.08) 0%, transparent 70%);
        }

        .kpi-card__label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--color-tertiary);
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .kpi-card__value {
            font-family: var(--font-serif);
            font-size: 2.2rem;
            font-weight: 600;
            color: var(--color-primary);
            line-height: 1;
            margin-bottom: 6px;
            position: relative;
            z-index: 1;
            transition: color 0.2s;
        }

        .kpi-card__hint {
            font-size: 0.72rem;
            color: var(--color-accent-dark);
            position: relative;
            z-index: 1;
        }

        .dash-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            padding: 10px 0;
            margin-bottom: var(--space-md);
            border-bottom: 1px solid rgba(26, 26, 26, 0.08);
        }

        .dash-toolbar__group {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .dash-toolbar__label {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--color-tertiary);
            margin-right: 4px;
        }

        .dash-toolbar__btn {
            padding: 5px 11px;
            font-size: 0.75rem;
            font-weight: 500;
            border: 1px solid rgba(26, 26, 26, 0.15);
            background: #fff;
            color: var(--color-secondary);
            cursor: pointer;
            font-family: var(--font-sans);
            transition: 0.15s ease-out;
        }

        .dash-toolbar__btn:hover { background: rgba(139, 105, 20, 0.08); color: var(--color-primary); }

        .dash-toolbar__btn.is-active {
            background: var(--color-accent);
            color: #fff;
            border-color: var(--color-accent);
        }

        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: rgba(139, 105, 20, 0.14);
            color: var(--color-accent-dark);
            border-radius: 14px;
            font-size: 0.72rem;
            font-weight: 500;
            cursor: pointer;
            margin-left: auto;
            transition: 0.15s;
            animation: chip-in 0.25s ease-out;
        }

        .filter-chip:hover { background: rgba(139, 105, 20, 0.24); }
        .filter-chip__x { font-size: 0.95rem; line-height: 1; opacity: 0.7; }
        .filter-chip:hover .filter-chip__x { opacity: 1; }

        @keyframes chip-in {
            from { opacity: 0; transform: translateX(8px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        .dash-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            grid-auto-rows: minmax(260px, auto);
            gap: var(--space-md);
            flex: 1;
        }

        .dash-card {
            padding: var(--space-md);
            border: 1px solid rgba(26, 26, 26, 0.1);
            background: var(--color-white);
            display: flex;
            flex-direction: column;
            min-height: 260px;
            min-width: 0;
            transition: box-shadow 0.2s ease-out, border-color 0.2s ease-out;
        }

        .dash-card:hover { border-color: rgba(139, 105, 20, 0.25); }

        .dash-card--wide { grid-column: span 2; }

        .dash-card__title {
            font-family: var(--font-serif);
            font-size: 1.15rem;
            margin-bottom: var(--space-sm);
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 8px;
        }

        .dash-card__hint { font-family: var(--font-sans); font-size: 0.72rem; color: var(--color-tertiary); font-weight: 400; }

        .dash-card__body {
            flex: 1;
            min-height: 200px;
            position: relative;
        }

        .dash-card canvas { width: 100% !important; height: 100% !important; cursor: pointer; display: block; }

        .dash-card--map { grid-column: span 3; min-height: 500px; }

        /* ===== SVG-карта ===== */
        .russia-map-wrap {
            position: relative;
            flex: 1;
            min-height: 420px;
            background: linear-gradient(180deg, #fbfaf6 0%, #eee9dc 100%);
            border: 1px solid rgba(26, 26, 26, 0.08);
            overflow: hidden;
        }

        .russia-map {
            width: 100%;
            height: 100%;
            display: block;
        }

        .russia-map .map-grid {
            stroke: rgba(26, 26, 26, 0.06);
            stroke-width: 0.6;
            stroke-dasharray: 2 3;
        }

        .russia-map .map-grid-label {
            font-family: var(--font-sans);
            font-size: 8px;
            fill: rgba(26, 26, 26, 0.25);
            letter-spacing: 0.05em;
        }

        .russia-outline {
            fill: url(#map-fill);
            stroke: rgba(139, 105, 20, 0.45);
            stroke-width: 1.8;
            stroke-linejoin: round;
            stroke-linecap: round;
            filter: url(#map-glow);
            transition: fill 0.3s ease-out;
        }

        .uni-point { cursor: pointer; transition: opacity 0.25s ease-out; }
        .uni-point.is-dimmed { opacity: 0.15; }

        .uni-point__dot {
            transition: r 0.25s ease-out, filter 0.25s ease-out, fill 0.3s ease-out;
            transform-origin: center;
        }

        .uni-point__halo {
            transition: opacity 0.3s ease-out, r 0.3s ease-out, fill 0.3s ease-out;
        }

        .uni-point__pulse {
            fill: none;
            stroke-width: 2;
            opacity: 0;
            transition: opacity 0.2s, stroke 0.3s ease-out;
            pointer-events: none;
        }

        .uni-point__label {
            font-family: var(--font-sans);
            font-size: 11px;
            font-weight: 600;
            fill: var(--color-secondary);
            paint-order: stroke;
            stroke: #fff;
            stroke-width: 3px;
            stroke-linejoin: round;
            pointer-events: none;
            transition: opacity 0.25s ease-out;
        }

        .uni-point.is-dimmed .uni-point__label { opacity: 0.2; }

        .uni-point:hover .uni-point__dot { r: 15; filter: brightness(1.1); }
        .uni-point:hover .uni-point__halo { opacity: 0.28; r: 26; }
        .uni-point.is-selected .uni-point__dot { r: 16; stroke-width: 3; }
        .uni-point.is-selected .uni-point__halo { opacity: 0.35; r: 30; }
        .uni-point.is-selected .uni-point__pulse {
            opacity: 0.55;
            animation: pulse-marker 2s ease-out infinite;
        }

        @keyframes pulse-marker {
            0%   { r: 16; opacity: 0.55; }
            100% { r: 34; opacity: 0; }
        }

        .map-info {
            position: absolute;
            top: 16px;
            left: 16px;
            min-width: 200px;
            padding: 10px 14px;
            background: rgba(255, 255, 255, 0.97);
            border-left: 3px solid var(--color-accent);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.1);
            font-family: var(--font-sans);
            font-size: 0.8rem;
            color: var(--color-primary);
            opacity: 0;
            transform: translateY(-6px);
            transition: opacity 0.2s ease-out, transform 0.2s ease-out;
            pointer-events: none;
            z-index: 10;
        }

        .map-info.is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        .map-info__phase {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--color-accent-dark);
            margin-bottom: 3px;
        }

        .map-info__name {
            font-family: var(--font-serif);
            font-size: 1.05rem;
            font-weight: 500;
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .map-info__projects {
            font-size: 0.72rem;
            color: var(--color-tertiary);
        }

        .map-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem var(--space-md);
            margin-top: var(--space-sm);
            padding-top: var(--space-sm);
            border-top: 1px solid rgba(26, 26, 26, 0.08);
        }

        .map-legend__item {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.75rem;
            color: var(--color-secondary);
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 10px;
            transition: 0.15s;
        }

        .map-legend__item:hover { background: rgba(139, 105, 20, 0.1); }
        .map-legend__item.is-active { background: rgba(139, 105, 20, 0.2); font-weight: 600; }

        .map-legend__dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; box-shadow: 0 0 0 1px rgba(0,0,0,0.15); }

        @media (max-width: 1100px) {
            .dash-grid { grid-template-columns: 1fr 1fr; }
            .dash-card--wide { grid-column: span 2; }
            .dash-card--map { grid-column: span 2; }
        }

        @media (max-width: 720px) {
            .dash-grid { grid-template-columns: 1fr; }
            .dash-card--wide, .dash-card--map { grid-column: span 1; }
        }

        @media (max-width: 900px) { .mainnav { overflow-x: auto; } }
    </style>
</head>
<body class="<?= $currentUser === null ? 'auth-body' : ('role-' . $viewRole) ?>">
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
                <div class="role-switch" role="tablist" aria-label="Переключение роли">
                    <a class="role-switch__btn <?= $viewRole === 'manager' ? 'active' : '' ?>"
                       role="tab"
                       aria-selected="<?= $viewRole === 'manager' ? 'true' : 'false' ?>"
                       href="?role=manager<?= $viewUniId ? '&uni_id=' . (int) $viewUniId : '' ?>">
                        <span class="role-switch__dot"></span>
                        <?= e($managerLabel) ?> · Менеджер
                    </a>
                    <a class="role-switch__btn <?= $viewRole === 'university' ? 'active' : '' ?>"
                       role="tab"
                       aria-selected="<?= $viewRole === 'university' ? 'true' : 'false' ?>"
                       href="?role=university<?= $viewUniId ? '&uni_id=' . (int) $viewUniId : '' ?>">
                        <span class="role-switch__dot"></span>
                        Представитель Вуза
                    </a>
                    <a class="role-switch__btn <?= $viewRole === 'supervisor' ? 'active' : '' ?>"
                       role="tab"
                       aria-selected="<?= $viewRole === 'supervisor' ? 'true' : 'false' ?>"
                       href="?role=supervisor">
                        <span class="role-switch__dot"></span>
                        Руководитель · Дашборд
                    </a>
                </div>
                <a class="topbar__logout" href="index.php?logout=1">Выйти</a>
            </div>
        </header>

        <nav class="mainnav">
            <span class="mainnav__caption">Разделы</span>
            <a class="active" href="index.php">Главная</a>
            <a href="#">Взаимодействия</a>
            <a href="#">Рабочий процесс</a>
            <a href="#">Отчёты</a>
            <a href="#">Аудит</a>
        </nav>

        <main class="content">
            <?php if ($viewRole === 'manager'): ?>
                <div class="workspace">

                    <?php if ($managerNotification !== null): ?>
                        <div class="notification notification--<?= $managerNotification['type'] === 'confirmed' ? 'success' : 'danger' ?>">
                            <span class="notification__icon"><?= $managerNotification['type'] === 'confirmed' ? '✓' : '✕' ?></span>
                            <div class="notification__body"><?= e($managerNotification['message']) ?></div>
                        </div>
                    <?php endif; ?>

                    <section class="panel panel--workflow">
                        <div class="panel__head">
                            <div>
                                <h2 class="panel__title">Рабочий процесс текущего проекта</h2>
                                <p class="panel__subtitle">
                                    Текущее взаимодействие:
                                    <strong title="<?= e($currentProject['title']) ?>"><?= e($currentProject['short']) ?></strong>
                                    <?php if ($currentProject['phase'] !== null): ?>
                                        · фаза <?= (int) $currentProject['phase']['num'] ?>
                                        «<?= e($currentProject['phase']['name']) ?>»
                                    <?php endif; ?>
                                    <?php if (isset($pendingByProject[(int) $currentProject['id']])): ?>
                                        · <span style="color:#b45309;font-weight:600;">⏳ ожидает подтверждения вуза</span>
                                    <?php endif; ?>
                                </p>
                            </div>
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
                        </div>
                    </section>

                    <section class="panel panel--matrix">
                        <div class="panel__head">
                            <div>
                                <h2 class="panel__title">Сетка проектов</h2>
                                <p class="panel__subtitle">
                                    Строки — ВУЗы, столбцы — ИТ-продукты. Клетки с взаимодействием — цветные
                                    листочки с номером фазы.
                                </p>
                                <span class="panel__hint">
                                    ◆ Перетащите листок — рядом появятся карманы «Предыдущая» и «Следующая» фаза.
                                    Фазы с 🔒 требуют подтверждения вуза.
                                </span>
                            </div>
                        </div>

                        <div class="matrix-scroll">
                            <table class="matrix">
                                <thead>
                                    <tr>
                                        <th>Вуз \ Продукт</th>
                                        <?php foreach ($products as $product): ?>
                                            <th title="<?= e($product['name']) ?>"><?= e($product['short']) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($universities as $university): ?>
                                        <tr>
                                            <th title="<?= e($university['name']) ?>">
                                                <span class="uni">
                                                    <?php if ($university['crest'] !== null): ?>
                                                        <img class="uni__crest" src="<?= e($university['crest']) ?>"
                                                             alt="Герб: <?= e($university['short']) ?>"
                                                             onerror="this.outerHTML='&lt;span class=&quot;uni__initial&quot;&gt;<?= e($university['initial']) ?>&lt;/span&gt;'">
                                                    <?php else: ?>
                                                        <span class="uni__initial"><?= e($university['initial']) ?></span>
                                                    <?php endif; ?>
                                                    <span class="uni__code"><?= e($university['short']) ?></span>
                                                </span>
                                            </th>
                                            <?php foreach ($products as $product): ?>
                                                <?php
                                                $uniId = (int) $university['id'];
                                                $prodId = (int) $product['id'];
                                                $phaseId = $phaseMap[$uniId][$prodId] ?? null;
                                                $phase = $phaseId !== null ? ($phaseById[$phaseId] ?? null) : null;
                                                $interactionId = $interactionIdMap[$uniId][$prodId] ?? null;
                                                $isCurrent = $interactionId !== null && $interactionId === $currentProject['id'];
                                                $isPending = $interactionId !== null && isset($pendingByProject[(int) $interactionId]);

                                                $cellClasses = [];
                                                if ($phase !== null && (int) $phase['num'] > 0) {
                                                    $cellClasses[] = 'phase';
                                                } else {
                                                    $cellClasses[] = 'empty';
                                                }
                                                if ($interactionId !== null) {
                                                    $cellClasses[] = 'clickable';
                                                }
                                                if ($isCurrent) {
                                                    $cellClasses[] = 'is-current-project';
                                                }
                                                if ($isPending) {
                                                    $cellClasses[] = 'is-pending';
                                                }
                                                $cellClass = implode(' ', $cellClasses);

                                                $cellTitle = $university['name'] . ' · ' . $product['name'];
                                                if ($phase !== null && (int) $phase['num'] > 0) {
                                                    $cellTitle .= ' → ' . $phase['name'];
                                                }
                                                if ($interactionId !== null) {
                                                    if ($isPending) {
                                                        $cellTitle .= ' · ожидает подтверждения вуза';
                                                    } else {
                                                        $cellTitle .= ' · открыть рабочий процесс';
                                                    }
                                                } else {
                                                    $cellTitle .= ' · взаимодействие не начато';
                                                }
                                                ?>
                                                <?php if ($interactionId !== null && $phase !== null && (int) $phase['num'] > 0): ?>
                                                    <td class="<?= e($cellClass) ?>"
                                                        title="<?= e($cellTitle) ?>"
                                                        data-project="<?= (int) $interactionId ?>"
                                                        data-phase-id="<?= (int) $phase['id'] ?>"
                                                        data-phase-num="<?= (int) $phase['num'] ?>"
                                                        draggable="true"
                                                        tabindex="0"
                                                        role="link"
                                                        aria-label="<?= e($cellTitle) ?>">
                                                        <span class="sticker-cell"
                                                              style="--phase-color: <?= e($phase['color']) ?>;">
                                                            <?= (int) $phase['num'] ?>
                                                        </span>
                                                    </td>
                                                <?php elseif ($interactionId !== null): ?>
                                                    <td class="<?= e($cellClass) ?>"
                                                        title="<?= e($cellTitle) ?>"
                                                        data-project="<?= (int) $interactionId ?>"
                                                        tabindex="0"
                                                        role="link"
                                                        aria-label="<?= e($cellTitle) ?>">
                                                        —
                                                    </td>
                                                <?php else: ?>
                                                    <td class="<?= e($cellClass) ?>" title="<?= e($cellTitle) ?>">—</td>
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
                                        <?php if ($phase['requires_confirmation']): ?> 🔒<?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    </section>
                </div>
            <?php elseif ($viewRole === 'university'): ?>
                <div class="workspace workspace--uni">

                    <?php if ($universityNotification !== null): ?>
                        <div class="notification notification--<?= $universityNotification['type'] === 'pending' ? 'pending' : 'info' ?>">
                            <span class="notification__icon">
                                <?= $universityNotification['type'] === 'pending' ? '⏳' : 'ℹ' ?>
                            </span>
                            <div class="notification__body"><?= e($universityNotification['message']) ?></div>

                            <?php if ($universityNotification['type'] === 'pending' && $pendingChange !== null): ?>
                                <div class="notification__actions">
                                    <form method="post" action="index.php?action=confirm_phase" style="display:inline;">
                                        <button type="submit" class="notification__btn notification__btn--primary">
                                            Подтвердить
                                        </button>
                                    </form>
                                    <form method="post" action="index.php?action=reject_phase" style="display:inline;">
                                        <button type="submit" class="notification__btn notification__btn--danger">
                                            Отклонить
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <section class="panel panel--workflow">
                        <div class="panel__head">
                            <div>
                                <h2 class="panel__title">Рабочий процесс вуза</h2>
                                <p class="panel__subtitle">
                                    Все взаимодействия:
                                    <strong><?= e($selectedUni['name'] ?? '—') ?></strong>
                                    · проектов: <?= count($uniProjects) ?>
                                </p>
                            </div>
                            <label class="project-select">
                                Вуз:
                                <select onchange="location.href = 'index.php?role=university&uni_id=' + this.value;">
                                    <?php foreach ($universities as $u): ?>
                                        <option value="<?= (int) $u['id'] ?>"
                                            <?= (int) $u['id'] === $viewUniId ? 'selected' : '' ?>>
                                            <?= e($u['short']) ?> — <?= e($u['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>

                        <div class="workflow-scroll workflow-scroll--uni">
                            <?php if ($uniProjects === []): ?>
                                <div class="uni-empty">
                                    У выбранного вуза пока нет активных взаимодействий.
                                </div>
                            <?php else: ?>
                                <?php foreach ($uniProjects as $proj): ?>
                                    <div class="uni-workflow">
                                        <div class="uni-workflow__head">
                                            <span class="uni-workflow__title" title="<?= e($proj['title']) ?>">
                                                <?= e($proj['short']) ?>
                                            </span>
                                            <span class="uni-workflow__meta">
                                                <?php if ($proj['phase'] !== null): ?>
                                                    фаза <?= (int) $proj['phase']['num'] ?>
                                                    «<?= e($proj['phase']['name']) ?>»
                                                    <?php if (!empty($proj['phase']['requires_confirmation'])): ?> 🔒<?php endif; ?>
                                                <?php else: ?>
                                                    фаза не задана
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="workflow-track workflow-track--compact">
                                            <?php foreach ($proj['workflow'] as $item): ?>
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
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="wf-legend">
                            <?php foreach ($phases as $phase): ?>
                                <span class="wf-legend__item">
                                    <span class="wf-legend__swatch" style="background-color: <?= e($phase['color']) ?>"></span>
                                    <?php if ((int) $phase['num'] > 0): ?><?= (int) $phase['num'] ?>.<?php endif; ?>
                                    <?= e($phase['name']) ?>
                                    <?php if ($phase['requires_confirmation']): ?> 🔒<?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            <?php else: ?>
                <!-- ============ ВИД РУКОВОДИТЕЛЯ: ИНТЕРАКТИВНЫЙ ДАШБОРД ============ -->
                <div class="workspace workspace--dash">
                    <section class="panel panel--dashboard">
                        <div class="panel__head">
                            <div>
                                <h2 class="panel__title">Дашборд руководителя</h2>
                                <p class="panel__subtitle">
                                    Фильтры периода и типа влияют на все графики и карту.
                                    Клик по фазе, вузу или маркеру — cross-filtering.
                                </p>
                            </div>
                        </div>

                        <div class="kpi-row">
                            <div class="kpi-card">
                                <div class="kpi-card__label">Всего проектов</div>
                                <div class="kpi-card__value"><?= (int) $totalInteractions ?></div>
                                <div class="kpi-card__hint">взаимодействий «вуз × продукт»</div>
                            </div>
                            <div class="kpi-card">
                                <div class="kpi-card__label">Активных</div>
                                <div class="kpi-card__value"><?= (int) $activeInteractions ?></div>
                                <div class="kpi-card__hint">в работе, не завершено</div>
                            </div>
                            <div class="kpi-card">
                                <div class="kpi-card__label">Вузов</div>
                                <div class="kpi-card__value"><?= count($universities) ?></div>
                                <div class="kpi-card__hint">партнёров в системе</div>
                            </div>
                            <div class="kpi-card">
                                <div class="kpi-card__label">ИТ-продуктов</div>
                                <div class="kpi-card__value"><?= count($products) ?></div>
                                <div class="kpi-card__hint">на витрине ИТ Школы</div>
                            </div>
                            <div class="kpi-card">
                                <div class="kpi-card__label">Средняя фаза</div>
                                <div class="kpi-card__value"><?= e((string) $avgPhase) ?></div>
                                <div class="kpi-card__hint">из <?= count($phases) - 1 ?> возможных</div>
                            </div>
                        </div>

                        <div class="dash-toolbar">
                            <div class="dash-toolbar__group">
                                <span class="dash-toolbar__label">Период:</span>
                                <button class="dash-toolbar__btn" data-months="3">3 мес</button>
                                <button class="dash-toolbar__btn" data-months="6">6 мес</button>
                                <button class="dash-toolbar__btn is-active" data-months="12">12 мес</button>
                            </div>
                            <div class="dash-toolbar__group">
                                <span class="dash-toolbar__label">Тип:</span>
                                <button class="dash-toolbar__btn is-active" data-act-type="line">Линия</button>
                                <button class="dash-toolbar__btn" data-act-type="bar">Столбцы</button>
                                <button class="dash-toolbar__btn" data-act-type="area">Область</button>
                            </div>
                            <button class="filter-chip" id="filter-chip" style="display:none;">
                                <span id="filter-chip-label"></span>
                                <span class="filter-chip__x">×</span>
                            </button>
                        </div>

                        <div class="dash-grid">
                            <div class="dash-card dash-card--wide">
                                <div class="dash-card__title">
                                    Динамика активности
                                    <span class="dash-card__hint" id="hint-activity">—</span>
                                </div>
                                <div class="dash-card__body">
                                    <canvas id="chart-activity"></canvas>
                                </div>
                            </div>

                            <div class="dash-card">
                                <div class="dash-card__title">
                                    Распределение по фазам
                                    <span class="dash-card__hint" id="hint-phases">—</span>
                                </div>
                                <div class="dash-card__body">
                                    <canvas id="chart-phases"></canvas>
                                </div>
                            </div>

                            <div class="dash-card">
                                <div class="dash-card__title">
                                    Топ вузов
                                    <span class="dash-card__hint" id="hint-unis">—</span>
                                </div>
                                <div class="dash-card__body">
                                    <canvas id="chart-universities"></canvas>
                                </div>
                            </div>

                            <div class="dash-card dash-card--wide">
                                <div class="dash-card__title">
                                    Фазы по вузам
                                    <span class="dash-card__hint" id="hint-stacked">—</span>
                                </div>
                                <div class="dash-card__body">
                                    <canvas id="chart-directions"></canvas>
                                </div>
                            </div>

                            <div class="dash-card dash-card--map">
                                <div class="dash-card__title">
                                    Карта вузов
                                    <span class="dash-card__hint" id="hint-map">цвет — преобладающая фаза за период</span>
                                </div>

                                <div class="russia-map-wrap">
                                    <svg class="russia-map"
                                         viewBox="0 0 <?= (int) $mapViewWidth ?> <?= (int) $mapViewHeight ?>"
                                         preserveAspectRatio="xMidYMid meet"
                                         xmlns="http://www.w3.org/2000/svg">
                                        <defs>
                                            <linearGradient id="map-fill" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="0%" stop-color="#f8f4e8"/>
                                                <stop offset="60%" stop-color="#ede7d3"/>
                                                <stop offset="100%" stop-color="#e0d6ba"/>
                                            </linearGradient>
                                            <linearGradient id="map-sea" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="0%" stop-color="#eef3f6"/>
                                                <stop offset="100%" stop-color="#dfe9ee"/>
                                            </linearGradient>
                                            <radialGradient id="map-vignette" cx="50%" cy="50%" r="70%">
                                                <stop offset="60%" stop-color="rgba(0,0,0,0)"/>
                                                <stop offset="100%" stop-color="rgba(139,105,20,0.08)"/>
                                            </radialGradient>
                                            <filter id="map-glow" x="-20%" y="-20%" width="140%" height="140%">
                                                <feGaussianBlur stdDeviation="3" result="blur"/>
                                                <feMerge>
                                                    <feMergeNode in="blur"/>
                                                    <feMergeNode in="SourceGraphic"/>
                                                </feMerge>
                                            </filter>
                                            <filter id="map-dot-shadow" x="-50%" y="-50%" width="200%" height="200%">
                                                <feDropShadow dx="0" dy="2" stdDeviation="2.2" flood-color="#000" flood-opacity="0.32"/>
                                            </filter>
                                        </defs>

                                        <rect width="<?= (int) $mapViewWidth ?>" height="<?= (int) $mapViewHeight ?>" fill="url(#map-sea)"/>

                                        <g class="map-grid-layer">
                                            <?php
                                            for ($lng = 20; $lng <= 180; $lng += 20) {
                                                [$gx] = project_point(0, $lng, $mapViewWidth, $mapViewHeight, $lngMin, $lngMax, $latMin, $latMax);
                                                echo '<line class="map-grid" x1="' . $gx . '" y1="0" x2="' . $gx . '" y2="' . $mapViewHeight . '"/>';
                                                echo '<text class="map-grid-label" x="' . ($gx + 2) . '" y="10">' . $lng . '°</text>';
                                            }
                                            for ($lat = 50; $lat <= 80; $lat += 10) {
                                                [, $gy] = project_point($lat, 0, $mapViewWidth, $mapViewHeight, $lngMin, $lngMax, $latMin, $latMax);
                                                echo '<line class="map-grid" x1="0" y1="' . $gy . '" x2="' . $mapViewWidth . '" y2="' . $gy . '"/>';
                                                echo '<text class="map-grid-label" x="4" y="' . ($gy - 2) . '">' . $lat . '°</text>';
                                            }
                                            ?>
                                        </g>

                                        <path class="russia-outline"
                                              d="M 56 250
                                                 Q 60 220 90 200
                                                 Q 100 180 85 160
                                                 Q 90 145 110 155
                                                 Q 130 165 155 190
                                                 Q 175 200 200 180
                                                 Q 230 160 280 130
                                                 Q 320 115 340 160
                                                 Q 360 170 380 120
                                                 Q 420 90 470 70
                                                 Q 520 50 555 105
                                                 Q 580 115 615 105
                                                 Q 660 115 700 130
                                                 Q 760 140 820 155
                                                 Q 880 165 940 180
                                                 Q 985 195 1000 195
                                                 Q 1000 210 975 220
                                                 Q 960 235 945 260
                                                 Q 930 285 900 280
                                                 Q 875 275 855 320
                                                 Q 850 350 855 375
                                                 Q 840 380 830 340
                                                 Q 810 320 780 300
                                                 Q 765 300 760 340
                                                 Q 745 375 720 420
                                                 Q 705 445 690 440
                                                 Q 685 410 665 395
                                                 Q 645 390 600 390
                                                 Q 540 390 470 390
                                                 Q 430 400 405 400
                                                 Q 375 395 350 370
                                                 Q 330 355 305 365
                                                 Q 275 375 245 400
                                                 Q 215 425 185 445
                                                 Q 155 465 130 470
                                                 Q 105 465 90 440
                                                 Q 82 415 80 390
                                                 Q 75 355 65 320
                                                 Q 56 295 56 250 Z"/>

                                        <rect width="<?= (int) $mapViewWidth ?>" height="<?= (int) $mapViewHeight ?>"
                                              fill="url(#map-vignette)" pointer-events="none"/>

                                        <text x="<?= (int) ($mapViewWidth / 2) ?>" y="<?= (int) ($mapViewHeight - 18) ?>"
                                              text-anchor="middle"
                                              style="font-family: 'Cormorant Garamond', serif; font-size: 22px; font-weight: 500; fill: rgba(139,105,20,0.28); letter-spacing: 0.2em;"
                                              pointer-events="none">РОССИЙСКАЯ ФЕДЕРАЦИЯ</text>

                                        <g class="map-points-layer">
                                            <?php foreach ($mapPoints as $p): ?>
                                                <g class="uni-point"
                                                   data-uni-id="<?= (int) $p['id'] ?>"
                                                   data-name="<?= e($p['short']) ?>"
                                                   data-full="<?= e($p['name']) ?>"
                                                   data-x="<?= (float) $p['x'] ?>"
                                                   data-y="<?= (float) $p['y'] ?>">
                                                    <circle class="uni-point__pulse" r="16"
                                                            stroke="<?= e($p['color']) ?>"
                                                            cx="<?= (float) $p['x'] ?>"
                                                            cy="<?= (float) $p['y'] ?>"/>
                                                    <circle class="uni-point__halo" r="20"
                                                            fill="<?= e($p['color']) ?>"
                                                            cx="<?= (float) $p['x'] ?>"
                                                            cy="<?= (float) $p['y'] ?>"
                                                            opacity="0"/>
                                                    <circle class="uni-point__dot" r="11"
                                                            fill="<?= e($p['color']) ?>"
                                                            stroke="#ffffff" stroke-width="2"
                                                            cx="<?= (float) $p['x'] ?>"
                                                            cy="<?= (float) $p['y'] ?>"
                                                            filter="url(#map-dot-shadow)"/>
                                                    <text class="uni-point__label"
                                                          x="<?= (float) $p['x'] ?>"
                                                          y="<?= (float) ($p['y'] + 28) ?>"
                                                          text-anchor="middle"><?= e($p['short']) ?></text>
                                                </g>
                                            <?php endforeach; ?>
                                        </g>
                                    </svg>

                                    <div class="map-info" id="map-info" aria-hidden="true">
                                        <div class="map-info__phase" id="map-info-phase">—</div>
                                        <div class="map-info__name" id="map-info-name">—</div>
                                        <div class="map-info__projects" id="map-info-projects">—</div>
                                    </div>
                                </div>

                                <div class="map-legend" id="map-legend">
                                    <?php foreach ($chartPhases as $phase): ?>
                                        <span class="map-legend__item" data-phase-id="<?= (int) $phase['id'] ?>">
                                            <span class="map-legend__dot" style="background-color: <?= e($phase['color']) ?>"></span>
                                            <?= (int) $phase['num'] ?>. <?= e($phase['name']) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <?php if ($viewRole === 'manager' && $currentUser !== null): ?>
    <script>
    (function () {
        'use strict';

        var PHASES = <?= json_encode($phaseMeta, JSON_UNESCAPED_UNICODE) ?>;
        var phaseByNum = {};
        PHASES.forEach(function (p) { phaseByNum[p.num] = p; });

        var cells = Array.prototype.slice.call(
            document.querySelectorAll('table.matrix td.phase[draggable="true"]')
        );
        if (cells.length === 0) return;

        var source = null;
        var pockets = [];

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function makePocket(phase, direction, rect) {
            var pocket = document.createElement('div');
            pocket.className = 'phase-pocket phase-pocket--' + direction;
            pocket.dataset.phaseId = phase.id;
            pocket.dataset.phaseNum = phase.num;

            var arrow = direction === 'prev' ? '↑' : '↓';
            var label = direction === 'prev' ? 'Предыдущая фаза' : 'Следующая фаза';

            pocket.innerHTML = ''
                + '<div class="phase-pocket__pin"></div>'
                + '<div class="phase-pocket__label">'
                +   '<span class="phase-pocket__label-arrow">' + arrow + '</span>'
                +   label
                + '</div>'
                + '<div class="phase-pocket__body">'
                +   '<span class="phase-pocket__num" style="background-color: ' + phase.color + '">' + phase.num + '</span>'
                +   '<span class="phase-pocket__name">' + escapeHtml(phase.name) + '</span>'
                +   (phase.requires_confirmation ? '<span class="phase-pocket__lock" title="Требует подтверждения вуза">🔒</span>' : '')
                + '</div>';

            var pw = 240;
            var left = rect.left + rect.width / 2 - pw / 2;
            left = Math.max(8, Math.min(left, window.innerWidth - pw - 8));

            var gap = 14;
            var top;
            if (direction === 'prev') {
                top = rect.top - 90 - gap;
                if (top < 8) { top = rect.bottom + gap; left = Math.max(8, left - 60); }
            } else {
                top = rect.bottom + gap;
                if (top + 90 > window.innerHeight - 8) { top = rect.top - 90 - gap; left = Math.max(8, left + 60); }
            }
            top = Math.max(8, Math.min(top, window.innerHeight - 96));

            pocket.style.left = left + 'px';
            pocket.style.top = top + 'px';
            pocket.style.width = pw + 'px';

            pocket.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                pocket.classList.add('is-hover');
            });
            pocket.addEventListener('dragleave', function () { pocket.classList.remove('is-hover'); });
            pocket.addEventListener('drop', function (e) {
                e.preventDefault();
                if (!source) return;
                doMove(source.dataset.project, pocket.dataset.phaseId);
            });

            document.body.appendChild(pocket);
            requestAnimationFrame(function () { pocket.classList.add('is-visible'); });
            pockets.push(pocket);
        }

        function cleanup() {
            document.body.classList.remove('is-dragging');
            cells.forEach(function (c) { c.classList.remove('is-drag-source'); });
            pockets.forEach(function (p) { p.remove(); });
            pockets = [];
            source = null;
        }

        function doMove(projectId, targetPhaseId) {
            var pid = parseInt(projectId, 10);
            cleanup();

            fetch('index.php?action=move_phase', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    project_id: pid,
                    target_phase_id: parseInt(targetPhaseId, 10)
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) { alert((data && data.error) || 'Не удалось переместить листок'); return; }
                window.location.href = 'index.php?role=manager&project=' + pid;
            })
            .catch(function () { alert('Ошибка сети. Попробуйте ещё раз.'); });
        }

        cells.forEach(function (td) {
            td.addEventListener('dragstart', function (e) {
                source = td;
                var fromNum = parseInt(td.dataset.phaseNum, 10);
                var rect = td.getBoundingClientRect();

                document.body.classList.add('is-dragging');
                td.classList.add('is-drag-source');

                var prev = phaseByNum[fromNum - 1];
                var next = phaseByNum[fromNum + 1];
                if (prev) makePocket(prev, 'prev', rect);
                if (next) makePocket(next, 'next', rect);

                e.dataTransfer.setData('text/plain', JSON.stringify({
                    projectId: td.dataset.project,
                    fromPhaseId: td.dataset.phaseId
                }));
                e.dataTransfer.effectAllowed = 'move';
            });

            td.addEventListener('dragend', function () { cleanup(); });

            td.addEventListener('click', function () {
                if (source !== null) return;
                window.location.href = 'index.php?role=manager&project=' + td.dataset.project;
            });

            td.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    window.location.href = 'index.php?role=manager&project=' + td.dataset.project;
                }
            });
        });
    })();
    </script>
    <?php endif; ?>

    <?php if ($viewRole === 'supervisor' && $currentUser !== null): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
    (function () {
        'use strict';

        var DASH = <?= json_encode($dashPayload, JSON_UNESCAPED_UNICODE) ?>;

        var accent = '#8b6914';
        var accentDark = '#6b5010';
        var gridColor = 'rgba(26, 26, 26, 0.08)';
        var textColor = '#4a4a4a';

        Chart.defaults.font.family = "'Inter', -apple-system, sans-serif";
        Chart.defaults.font.size = 11;
        Chart.defaults.color = textColor;

        var state = {
            months: 12,
            chartType: 'line',   // 'line' | 'bar' | 'area'
            phaseId: null,
            uniId: null,
            aggregate: null
        };

        var charts = {
            activity: null,
            phases: null,
            unis: null,
            stacked: null
        };

        function phaseById(id) {
            for (var i = 0; i < DASH.phases.length; i++) {
                if (DASH.phases[i].id === id) return DASH.phases[i];
            }
            return null;
        }

        function uniById(id) {
            for (var i = 0; i < DASH.universities.length; i++) {
                if (DASH.universities[i].id === id) return DASH.universities[i];
            }
            return null;
        }

        function anyUniById(id) {
            for (var i = 0; i < DASH.allUniversities.length; i++) {
                if (DASH.allUniversities[i].id === id) return DASH.allUniversities[i];
            }
            return null;
        }

        function hexWithAlpha(hex, alpha) {
            if (!hex || hex.charAt(0) !== '#') return hex;
            var h = hex.substring(1);
            if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
            var r = parseInt(h.substring(0, 2), 16);
            var g = parseInt(h.substring(2, 4), 16);
            var b = parseInt(h.substring(4, 6), 16);
            return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
        }

        // ===== Агрегация по выбранному периоду =====
        function aggregateMonths(n) {
            var slice = DASH.monthly.slice(-n);
            var byUniPhase = {};
            var labels = [];
            var activity = [];
            var newProjects = [];

            slice.forEach(function (m, idx) {
                labels.push(m.label);
                var monthTotal = 0;
                Object.keys(m.byUniPhase).forEach(function (uid) {
                    if (!byUniPhase[uid]) byUniPhase[uid] = {};
                    var u = m.byUniPhase[uid];
                    Object.keys(u).forEach(function (pid) {
                        byUniPhase[uid][pid] = (byUniPhase[uid][pid] || 0) + u[pid];
                        monthTotal += u[pid];
                    });
                });
                activity.push(monthTotal);
                newProjects.push(DASH.newProjects[DASH.newProjects.length - n + idx] || 1);
            });

            return {
                labels: labels,
                activity: activity,
                newProjects: newProjects,
                byUniPhase: byUniPhase
            };
        }

        function computeByPhase() {
            var res = {};
            DASH.phases.forEach(function (p) { res[p.id] = 0; });

            Object.keys(state.aggregate.byUniPhase).forEach(function (uid) {
                if (state.uniId && parseInt(uid, 10) !== state.uniId) return;
                var u = state.aggregate.byUniPhase[uid];
                Object.keys(u).forEach(function (pid) {
                    var key = parseInt(pid, 10);
                    if (res[key] !== undefined) res[key] += u[pid];
                });
            });
            return res;
        }

        function computeByUni() {
            var res = {};
            DASH.universities.forEach(function (u) { res[u.id] = 0; });

            Object.keys(state.aggregate.byUniPhase).forEach(function (uidStr) {
                var uid = parseInt(uidStr, 10);
                var u = state.aggregate.byUniPhase[uidStr];
                Object.keys(u).forEach(function (pidStr) {
                    var pid = parseInt(pidStr, 10);
                    if (state.phaseId && pid !== state.phaseId) return;
                    if (res[uid] !== undefined) res[uid] += u[pidStr];
                });
            });
            return res;
        }

        function topPhaseForUni(uid) {
            var u = state.aggregate.byUniPhase[String(uid)];
            if (!u) return null;
            var maxCount = 0;
            var maxPid = null;
            Object.keys(u).forEach(function (pidStr) {
                if (u[pidStr] > maxCount) {
                    maxCount = u[pidStr];
                    maxPid = parseInt(pidStr, 10);
                }
            });
            return maxPid;
        }

        function phaseColorById(pid) {
            var p = phaseById(pid);
            return p ? p.color : '#9ca3af';
        }

        function rebuildAggregate() {
            state.aggregate = aggregateMonths(state.months);
        }

        // ===== График активности =====
        function renderActivity() {
            var canvas = document.getElementById('chart-activity');
            if (!canvas) return;

            if (charts.activity) {
                charts.activity.destroy();
                charts.activity = null;
            }

            var labels = state.aggregate.labels;
            var actData = state.aggregate.activity;
            var newData = state.aggregate.newProjects;

            var gradient = canvas.getContext('2d').createLinearGradient(0, 0, 0, 260);
            gradient.addColorStop(0, 'rgba(139, 105, 20, 0.4)');
            gradient.addColorStop(1, 'rgba(139, 105, 20, 0.02)');

            var type = state.chartType === 'bar' ? 'bar' : 'line';
            var fill = state.chartType === 'area';

            var datasets = [
                {
                    label: 'Активность',
                    data: actData,
                    borderColor: accent,
                    backgroundColor: type === 'bar' ? accent : (fill ? gradient : 'rgba(139, 105, 20, 0.08)'),
                    fill: fill || type === 'bar',
                    tension: 0.35,
                    pointBackgroundColor: accent,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: type === 'bar' ? 0 : 4,
                    pointHoverRadius: type === 'bar' ? 0 : 7,
                    borderWidth: 2,
                    borderRadius: type === 'bar' ? 3 : 0,
                    barPercentage: 0.6
                },
                {
                    label: 'Новые проекты',
                    data: newData,
                    borderColor: accentDark,
                    backgroundColor: 'transparent',
                    borderDash: type === 'bar' ? [] : [4, 4],
                    tension: 0.35,
                    pointBackgroundColor: accentDark,
                    pointRadius: type === 'bar' ? 0 : 3,
                    pointHoverRadius: 5,
                    borderWidth: 1.5,
                    type: 'line',
                    hidden: type === 'bar'
                }
            ];

            charts.activity = new Chart(canvas, {
                type: type,
                data: { labels: labels, datasets: datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 500, easing: 'easeOutQuart' },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14, font: { size: 11 } } },
                        tooltip: {
                            backgroundColor: 'rgba(26, 26, 26, 0.92)',
                            padding: 10,
                            cornerRadius: 2,
                            titleFont: { size: 12, weight: '600' },
                            bodyFont: { size: 12 }
                        }
                    },
                    scales: {
                        x: { grid: { color: gridColor, drawBorder: false }, ticks: { color: textColor } },
                        y: { beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { color: textColor, precision: 0 } }
                    }
                }
            });
        }

        // ===== Распределение по фазам =====
        function renderPhases() {
            var canvas = document.getElementById('chart-phases');
            if (!canvas) return;

            if (charts.phases) {
                charts.phases.destroy();
                charts.phases = null;
            }

            var byPhase = computeByPhase();
            var labels = DASH.phases.map(function (p) { return p.num + '. ' + p.name; });
            var colors = DASH.phases.map(function (p) { return p.color; });
            var data = DASH.phases.map(function (p) { return byPhase[p.id] || 0; });
            var alphas = DASH.phases.map(function (p) {
                if (state.phaseId && p.id !== state.phaseId) return 0.22;
                return 1;
            });

            charts.phases = new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: colors.map(function (c, i) { return hexWithAlpha(c, alphas[i]); }),
                        borderColor: '#fff',
                        borderWidth: 2,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    animation: { duration: 500, easing: 'easeOutQuart' },
                    onClick: function (evt, els) {
                        if (!els || !els.length) return;
                        var idx = els[0].index;
                        var pid = DASH.phases[idx].id;
                        state.phaseId = (state.phaseId === pid) ? null : pid;
                        refreshAll();
                    },
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: { boxWidth: 10, boxHeight: 10, padding: 8, font: { size: 10 } }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(26, 26, 26, 0.92)',
                            padding: 10,
                            cornerRadius: 2,
                            callbacks: {
                                label: function (ctx) { return ctx.label + ': ' + ctx.parsed + ' проектов'; }
                            }
                        }
                    }
                }
            });
        }

        // ===== Топ вузов (bar / line / area) =====
        function renderUnis() {
            var canvas = document.getElementById('chart-universities');
            if (!canvas) return;

            if (charts.unis) {
                charts.unis.destroy();
                charts.unis = null;
            }

            var byUni = computeByUni();
            var labels = DASH.universities.map(function (u) { return u.short; });
            var data = DASH.universities.map(function (u) { return byUni[u.id] || 0; });

            var bgs = DASH.universities.map(function (u) {
                if (state.uniId && u.id !== state.uniId) return hexWithAlpha(accent, 0.22);
                return accent;
            });
            var hoverBgs = DASH.universities.map(function (u) {
                if (state.uniId && u.id !== state.uniId) return hexWithAlpha(accentDark, 0.3);
                return accentDark;
            });

            var type = state.chartType === 'bar' ? 'bar' : 'line';
            var fill = state.chartType === 'area';

            var dataset;
            var options;

            if (type === 'bar') {
                dataset = {
                    label: 'Проектов',
                    data: data,
                    backgroundColor: bgs,
                    hoverBackgroundColor: hoverBgs,
                    borderRadius: 3,
                    barThickness: 16
                };

                options = {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 500, easing: 'easeOutQuart' },
                    onClick: function (evt, els) {
                        if (!els || !els.length) return;
                        var idx = els[0].index;
                        var uid = DASH.universities[idx].id;
                        state.uniId = (state.uniId === uid) ? null : uid;
                        refreshAll();
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(26, 26, 26, 0.92)',
                            padding: 10,
                            cornerRadius: 2,
                            callbacks: {
                                label: function (ctx) {
                                    var v = ctx.parsed && ctx.parsed.x !== undefined ? ctx.parsed.x : ctx.parsed;
                                    return 'Проектов: ' + v;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { color: textColor, precision: 0 } },
                        y: { grid: { display: false }, ticks: { color: textColor, font: { size: 11 } } }
                    }
                };
            } else {
                dataset = {
                    label: 'Проектов',
                    data: data,
                    borderColor: accent,
                    backgroundColor: fill ? hexWithAlpha(accent, 0.35) : hexWithAlpha(accent, 0.1),
                    fill: fill,
                    tension: 0.35,
                    pointBackgroundColor: accent,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    borderWidth: 2
                };

                options = {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 500, easing: 'easeOutQuart' },
                    onClick: function (evt, els) {
                        if (!els || !els.length) return;
                        var idx = els[0].index;
                        var uid = DASH.universities[idx].id;
                        state.uniId = (state.uniId === uid) ? null : uid;
                        refreshAll();
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(26, 26, 26, 0.92)',
                            padding: 10,
                            cornerRadius: 2,
                            callbacks: {
                                label: function (ctx) {
                                    var v = ctx.parsed && ctx.parsed.y !== undefined ? ctx.parsed.y : ctx.parsed;
                                    return 'Проектов: ' + v;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: textColor, font: { size: 11 }, autoSkip: false, maxRotation: 45, minRotation: 0 } },
                        y: { beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { color: textColor, precision: 0 } }
                    }
                };
            }

            charts.unis = new Chart(canvas, {
                type: type,
                data: { labels: labels, datasets: [dataset] },
                options: options
            });
        }

        // ===== Фазы по вузам =====
        function renderStacked() {
            var canvas = document.getElementById('chart-directions');
            if (!canvas) return;

            if (charts.stacked) {
                charts.stacked.destroy();
                charts.stacked = null;
            }

            var byUniPhase = state.aggregate.byUniPhase;
            var uniLabels = DASH.universities.map(function (u) { return u.short; });
            var phaseIds = DASH.phases.map(function (p) { return p.id; });
            var phaseLabels = DASH.phases.map(function (p) { return p.num + '. ' + p.name; });
            var phaseColors = DASH.phases.map(function (p) { return p.color; });

            var type = state.chartType === 'bar' ? 'bar' : 'line';
            var fill = state.chartType === 'area';

            var datasets = [];
            for (var i = 0; i < phaseIds.length; i++) {
                var pid = phaseIds[i];
                var isDimmed = state.phaseId && state.phaseId !== pid;
                var data = DASH.universities.map(function (u) {
                    if (state.uniId && u.id !== state.uniId) return 0;
                    var uData = byUniPhase[String(u.id)];
                    return uData ? (uData[String(pid)] || 0) : 0;
                });

                var alpha = isDimmed ? 0.22 : 1;
                var ds = {
                    label: phaseLabels[i],
                    data: data,
                    borderColor: phaseColors[i],
                    borderWidth: 2,
                    tension: 0.35,
                    pointBackgroundColor: phaseColors[i],
                    pointBorderColor: '#fff',
                    pointBorderWidth: 1.5,
                    pointRadius: type === 'bar' ? 0 : 4,
                    pointHoverRadius: 6
                };

                if (type === 'bar') {
                    ds.backgroundColor = hexWithAlpha(phaseColors[i], alpha * 0.9);
                    ds.borderRadius = 2;
                    ds.stack = 'phases';
                } else {
                    ds.backgroundColor = fill ? hexWithAlpha(phaseColors[i], alpha * 0.35) : 'transparent';
                    ds.fill = fill;
                }

                datasets.push(ds);
            }

            charts.stacked = new Chart(canvas, {
                type: type,
                data: { labels: uniLabels, datasets: datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 500, easing: 'easeOutQuart' },
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, padding: 10, font: { size: 10 } } },
                        tooltip: {
                            backgroundColor: 'rgba(26, 26, 26, 0.92)',
                            padding: 10,
                            cornerRadius: 2
                        }
                    },
                    scales: {
                        x: { stacked: type === 'bar', grid: { display: false }, ticks: { color: textColor } },
                        y: { stacked: type === 'bar', beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { color: textColor, precision: 0 } }
                    }
                }
            });
        }

        // ===== Карта =====
        function renderMap() {
            var mapWrap = document.querySelector('.russia-map-wrap');
            if (!mapWrap) return;

            var points = mapWrap.querySelectorAll('.uni-point');

            points.forEach(function (g) {
                var uid = parseInt(g.dataset.uniId, 10);
                g.classList.remove('is-dimmed', 'is-selected');

                var topPid = topPhaseForUni(uid);
                var color = topPid ? phaseColorById(topPid) : '#9ca3af';

                var dot = g.querySelector('.uni-point__dot');
                var halo = g.querySelector('.uni-point__halo');
                var pulse = g.querySelector('.uni-point__pulse');
                if (dot) dot.setAttribute('fill', color);
                if (halo) halo.setAttribute('fill', color);
                if (pulse) pulse.setAttribute('stroke', color);

                var hasData = state.aggregate.byUniPhase[String(uid)] !== undefined;

                if (state.uniId && uid === state.uniId) {
                    g.classList.add('is-selected');
                    return;
                }
                if (state.phaseId) {
                    var uData = state.aggregate.byUniPhase[String(uid)];
                    var inPhase = uData && uData[String(state.phaseId)];
                    if (!inPhase) g.classList.add('is-dimmed');
                } else if (state.uniId && uid !== state.uniId) {
                    g.classList.add('is-dimmed');
                } else if (!hasData) {
                    g.classList.add('is-dimmed');
                }
            });

            var legendItems = document.querySelectorAll('.map-legend__item');
            legendItems.forEach(function (li) {
                var pid = parseInt(li.dataset.phaseId, 10);
                li.classList.toggle('is-active', state.phaseId === pid);
            });
        }

        // ===== Подсказки =====
        function updateChip() {
            var chip = document.getElementById('filter-chip');
            var label = document.getElementById('filter-chip-label');
            if (!chip || !label) return;

            var parts = [];
            if (state.uniId) {
                var u = uniById(state.uniId) || anyUniById(state.uniId);
                if (u) parts.push('Вуз: ' + u.short);
            }
            if (state.phaseId) {
                var p = phaseById(state.phaseId);
                if (p) parts.push('Фаза: ' + p.num + '. ' + p.name);
            }

            if (parts.length === 0) {
                chip.style.display = 'none';
            } else {
                chip.style.display = 'inline-flex';
                label.textContent = parts.join('  ·  ');
            }
        }

        function updateHints() {
            var typeLabel = state.chartType === 'line' ? 'линия'
                : (state.chartType === 'bar' ? 'столбцы' : 'область');

            var hAct = document.getElementById('hint-activity');
            if (hAct) hAct.textContent = 'последние ' + state.months + ' мес · ' + typeLabel;

            var hPh = document.getElementById('hint-phases');
            if (hPh) hPh.textContent = 'за ' + state.months + ' мес · клик — фильтр';

            var hUn = document.getElementById('hint-unis');
            if (hUn) hUn.textContent = 'за ' + state.months + ' мес · ' + typeLabel + ' · клик — фильтр';

            var hSt = document.getElementById('hint-stacked');
            if (hSt) hSt.textContent = 'за ' + state.months + ' мес · ' + typeLabel;

            var hMap = document.getElementById('hint-map');
            if (hMap) hMap.textContent = 'за ' + state.months + ' мес · цвет — преобладающая фаза';
        }

        function refreshAll() {
            rebuildAggregate();
            renderPhases();
            renderUnis();
            renderStacked();
            renderMap();
            updateChip();
            updateHints();
        }

        // ===== Первичная отрисовка =====
        rebuildAggregate();
        renderActivity();
        renderPhases();
        renderUnis();
        renderStacked();
        renderMap();
        updateChip();
        updateHints();

        // ===== Тулбар: период =====
        var monthBtns = document.querySelectorAll('[data-months]');
        monthBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                monthBtns.forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');
                state.months = parseInt(btn.dataset.months, 10);
                refreshAll();
                renderActivity();
            });
        });

        // ===== Тулбар: тип графика =====
        var typeBtns = document.querySelectorAll('[data-act-type]');
        typeBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                typeBtns.forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');
                state.chartType = btn.dataset.actType;
                renderActivity();
                renderUnis();
                renderStacked();
                updateHints();
            });
        });

        // ===== Сброс фильтров =====
        var chip = document.getElementById('filter-chip');
        if (chip) {
            chip.addEventListener('click', function () {
                state.phaseId = null;
                state.uniId = null;
                refreshAll();
            });
        }

        // ===== Легенда карты =====
        var legendItems = document.querySelectorAll('.map-legend__item');
        legendItems.forEach(function (li) {
            li.addEventListener('click', function () {
                var pid = parseInt(li.dataset.phaseId, 10);
                state.phaseId = (state.phaseId === pid) ? null : pid;
                refreshAll();
            });
        });

        // ===== Точки на карте =====
        var mapWrap = document.querySelector('.russia-map-wrap');
        var mapInfo = document.getElementById('map-info');

        if (mapWrap && mapInfo) {
            var infoPhase    = document.getElementById('map-info-phase');
            var infoName     = document.getElementById('map-info-name');
            var infoProjects = document.getElementById('map-info-projects');

            var points = mapWrap.querySelectorAll('.uni-point');

            points.forEach(function (g) {
                var uid = parseInt(g.dataset.uniId, 10);
                var fullName = g.dataset.full || '';
                var short = g.dataset.name || '';

                g.addEventListener('mouseenter', function () {
                    var topPid = topPhaseForUni(uid);
                    var topPhase = topPid ? phaseById(topPid) : null;
                    var phaseName = topPhase ? (topPhase.num + '. ' + topPhase.name) : 'Не начато';
                    var color = topPhase ? topPhase.color : '#8b6914';

                    var uData = state.aggregate.byUniPhase[String(uid)] || {};
                    var total = 0;
                    Object.keys(uData).forEach(function (k) { total += uData[k]; });

                    infoPhase.textContent = phaseName;
                    infoPhase.style.color = color;
                    infoName.textContent = short;
                    infoName.title = fullName;
                    infoProjects.textContent = 'Проектов за период: ' + total + ' · клик — фильтр';
                    mapInfo.classList.add('is-visible');
                });

                g.addEventListener('mouseleave', function () {
                    mapInfo.classList.remove('is-visible');
                });

                g.addEventListener('click', function () {
                    state.uniId = (state.uniId === uid) ? null : uid;
                    refreshAll();
                });
            });
        }
    })();
    </script>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>