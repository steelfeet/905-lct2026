<?php
/**
 * Точка входа CRM «ИТ Школа РТК» (прототип).
 *
 * Роли (5):
 *   - supervisor: сводный дашборд;
 *   - manager: рабочий процесс + сетка проектов + «Статистика студентов»
 *     при фазе «Ведение занятий» (11): вкладки «Проекты» и «Сводка по журналу»;
 *   - university: рабочие процессы вуза + вкладки «Проекты» и «Журнал»
 *     + заметки преподавателя + активность родителей;
 *   - student: карточки своих проектов (может быть несколько, командные),
 *     свои оценки и заметки преподавателя в «Журнале»;
 *   - parent: журнал и проекты только своего ребёнка,
 *     уведомления о плохих отметках, история визитов.
 *
 * Особенности структуры проектов:
 *   - у одного студента может быть несколько проектов в разных фазах;
 *   - несколько студентов могут работать над одним проектом одновременно;
 *   - проекты бывают разных типов (доклад, интерактив, код и т.п.).
 *
 * Cookie-плашка и пометка об отсутствии рекомендательных технологий
 * показываются авторизованным пользователям.
 */

session_start();

require __DIR__ . '/db.php';

$pdo = db();

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
    if (!$interaction) { echo json_encode(['ok' => false, 'error' => 'Проект не найден']); exit; }

    $stmt = $pdo->prepare('SELECT * FROM interaction_phases WHERE id = :id');
    $stmt->execute([':id' => $targetPhaseId]);
    $targetPhase = $stmt->fetch();
    if (!$targetPhase) { echo json_encode(['ok' => false, 'error' => 'Фаза не найдена']); exit; }

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
    if ($currentUser === false) $currentUser = null;
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
    if ($name === '') return '?';
    $byte = ord($name[0]);
    if ($byte < 0x80) $len = 1;
    elseif ($byte < 0xF0) $len = $byte < 0xE0 ? 2 : 3;
    else $len = 4;
    $first = substr($name, 0, $len);
    if ($len > 1) {
        $last = $len - 1;
        $tail = ord($first[$last]);
        if ($tail >= 0xB0 && $tail <= 0xDF) $first[$last] = chr($tail - 0x20);
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
foreach ($universities as $university) $shortByUniversityId[(int) $university['id']] = $university['short'];
$shortByProductId = [];
foreach ($products as $product) $shortByProductId[(int) $product['id']] = $product['short'];

foreach ($projectOptions as &$opt) {
    $opt['title'] = $opt['university_name'] . ' · ' . $opt['product_name'];
    $opt['short'] =
        ($shortByUniversityId[(int) $opt['university_id']] ?? $opt['university_name'])
        . ' · ' . ($shortByProductId[(int) $opt['product_id']] ?? $opt['product_name']);
    $opt['phase_num'] = ($opt['phase_id'] !== null && isset($phaseById[(int) $opt['phase_id']]))
        ? (int) $phaseById[(int) $opt['phase_id']]['num'] : 0;
    $opt['phase'] = ($opt['phase_id'] !== null) ? ($phaseById[(int) $opt['phase_id']] ?? null) : null;
}
unset($opt);

$currentProjectId = 0;
foreach ($projectOptions as $opt) {
    if ((int) $opt['id'] === (int) ($_GET['project'] ?? 0)) { $currentProjectId = (int) $opt['id']; break; }
}
if ($currentProjectId === 0 && $projectOptions !== []) $currentProjectId = (int) $projectOptions[0]['id'];

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
if ($pendingChange !== null) $pendingByProject[(int) $pendingChange['project_id']] = $pendingChange;

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
    array $workflowSteps, array $sideLabels, array $stepStates, array $currentStates,
    int $phaseNum, int $projectId
): array {
    $currentState = $currentStates[$projectId % count($currentStates)];
    $workflow = [];
    foreach ($workflowSteps as $step) {
        if ($step['num'] < $phaseNum) $state = 'completed';
        elseif ($step['num'] === $phaseNum) $state = $currentState;
        else $state = 'pending';
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

$workflow = build_project_workflow($workflowSteps, $sideLabels, $stepStates, $currentStates, $currentPhaseNum, $currentProjectId);

// =============================================================================
// Студенческие проектные фазы и типы проектов
// =============================================================================

$studentPhases = [
    ['code' => 'topic',   'num' => 1, 'name' => 'Выбор темы',             'color' => '#8b6914'],
    ['code' => 'work',    'num' => 2, 'name' => 'Работа над проектом',     'color' => '#2563eb'],
    ['code' => 'predef',  'num' => 3, 'name' => 'Предварительная защита',  'color' => '#d97706'],
    ['code' => 'defense', 'num' => 4, 'name' => 'Защита проекта',          'color' => '#16a34a'],
];
$studentPhaseByNum = [];
foreach ($studentPhases as $sp) $studentPhaseByNum[(int) $sp['num']] = $sp;

$projectTypes = [
    'code'        => ['label' => 'Код-проект',   'color' => '#16a34a', 'icon' => '💻'],
    'research'    => ['label' => 'Исследование', 'color' => '#7c3aed', 'icon' => '🔬'],
    'interactive' => ['label' => 'Интерактив',   'color' => '#2563eb', 'icon' => '🎮'],
    'report'      => ['label' => 'Доклад',       'color' => '#8b6914', 'icon' => '📄'],
    'design'      => ['label' => 'Дизайн',       'color' => '#d97706', 'icon' => '🎨'],
];

$mockStudents = [
    ['id' => 1, 'name' => 'Иванов Иван Иванович',       'group' => 'ИУ7-41Б'],
    ['id' => 2, 'name' => 'Петрова Анна Сергеевна',     'group' => 'ИУ7-41Б'],
    ['id' => 3, 'name' => 'Сидоров Пётр Алексеевич',    'group' => 'ИУ7-42Б'],
    ['id' => 4, 'name' => 'Кузнецова Мария Дмитриевна', 'group' => 'ИУ7-42Б'],
    ['id' => 5, 'name' => 'Смирнов Олег Викторович',    'group' => 'ИУ7-43Б'],
    ['id' => 6, 'name' => 'Волкова Ольга Игоревна',     'group' => 'ИУ7-43Б'],
    ['id' => 7, 'name' => 'Никитин Артём Павлович',     'group' => 'ИУ7-44Б'],
    ['id' => 8, 'name' => 'Морозова Дарья Ивановна',    'group' => 'ИУ7-44Б'],
    ['id' => 9, 'name' => 'Федотов Кирилл Андреевич',   'group' => 'ИУ7-45Б'],
    ['id' => 10,'name' => 'Романова Елизавета Олеговна','group' => 'ИУ7-45Б'],
];
$studentById = [];
foreach ($mockStudents as $s) $studentById[(int) $s['id']] = $s;

$mockProjects = [
    [
        'id'       => 1,
        'title'    => 'ML-модель для телекома',
        'type'     => 'code',
        'students' => [1, 2, 4],
        'history'  => [
            ['num' => 1, 'date' => '02.09', 'note' => 'Выбор темы и постановка задачи'],
            ['num' => 2, 'date' => '09.09', 'note' => 'Начало работы, сбор данных'],
            ['num' => 3, 'date' => '23.09', 'note' => 'Предзащита: замечания по методологии'],
            ['num' => 2, 'date' => '30.09', 'note' => 'Возврат: доработка методологии'],
            ['num' => 3, 'date' => '07.10', 'note' => 'Повторная предзащита — принято'],
        ],
        'git' => [
            ['hash' => 'a3f9c12', 'date' => '20.09 14:23', 'author' => 'Иванов И.И.',    'message' => 'Добавлен раздел «Введение»'],
            ['hash' => 'b7e2a45', 'date' => '19.09 18:07', 'author' => 'Петрова А.С.',   'message' => 'Постановка задачи и цели работы'],
            ['hash' => 'c1d4f88', 'date' => '19.09 11:52', 'author' => 'Преподаватель',  'message' => 'Ревью: рекомендации по структуре'],
            ['hash' => 'd92b8e1', 'date' => '18.09 20:31', 'author' => 'Кузнецова М.Д.', 'message' => 'Инициализация репозитория и README'],
        ],
    ],
    [
        'id'       => 2,
        'title'    => 'Аналитика оттока клиентов',
        'type'     => 'research',
        'students' => [3],
        'history'  => [
            ['num' => 1, 'date' => '02.09', 'note' => 'Выбор темы: аналитика оттока'],
            ['num' => 2, 'date' => '09.09', 'note' => 'Сбор и обработка данных'],
            ['num' => 3, 'date' => '23.09', 'note' => 'Предзащита — хорошо'],
        ],
        'git' => [
            ['hash' => 'f1a2b3c', 'date' => '15.09 10:14', 'author' => 'Сидоров П.А.', 'message' => 'Обзор литературы'],
            ['hash' => 'g4h5i6j', 'date' => '12.09 16:40', 'author' => 'Сидоров П.А.', 'message' => 'Первичный анализ датасета'],
        ],
    ],
    [
        'id'       => 3,
        'title'    => 'Чат-бот поддержки на LLM',
        'type'     => 'code',
        'students' => [5, 6],
        'history'  => [
            ['num' => 1, 'date' => '02.09', 'note' => 'Выбор темы: чат-бот на LLM'],
            ['num' => 2, 'date' => '09.09', 'note' => 'Прототип и интеграция с API'],
            ['num' => 3, 'date' => '23.09', 'note' => 'Предзащита'],
            ['num' => 4, 'date' => '30.09', 'note' => 'Защита проекта — отлично'],
        ],
        'git' => [
            ['hash' => 'k1l2m3n', 'date' => '22.09 11:08', 'author' => 'Смирнов О.В.',  'message' => 'MVP чат-бота'],
            ['hash' => 'o4p5q6r', 'date' => '21.09 17:32', 'author' => 'Волкова О.И.',  'message' => 'Интеграция с LLM API'],
        ],
    ],
    [
        'id'       => 4,
        'title'    => 'Дашборд качества связи',
        'type'     => 'interactive',
        'students' => [7],
        'history'  => [
            ['num' => 1, 'date' => '02.09', 'note' => 'Выбор темы: дашборд качества'],
            ['num' => 2, 'date' => '09.09', 'note' => 'Прототип и визуализация'],
            ['num' => 3, 'date' => '23.09', 'note' => 'Предзащита — принято'],
        ],
        'git' => [
            ['hash' => 's7t8u9v', 'date' => '20.09 09:15', 'author' => 'Никитин А.П.', 'message' => 'Прототип дашборда'],
        ],
    ],
    [
        'id'       => 5,
        'title'    => 'Классификация обращений',
        'type'     => 'code',
        'students' => [8, 9],
        'history'  => [
            ['num' => 1, 'date' => '02.09', 'note' => 'Выбор темы'],
            ['num' => 2, 'date' => '09.09', 'note' => 'Начало работы'],
            ['num' => 3, 'date' => '23.09', 'note' => 'Предзащита'],
            ['num' => 2, 'date' => '30.09', 'note' => 'Возврат: доработка признаков'],
        ],
        'git' => [
            ['hash' => 'w1x2y3z', 'date' => '19.09 14:52', 'author' => 'Морозова Д.И.', 'message' => 'Базовая модель классификации'],
        ],
    ],
    [
        'id'       => 6,
        'title'    => 'Рекомендации тарифов',
        'type'     => 'report',
        'students' => [10, 1],
        'history'  => [
            ['num' => 1, 'date' => '05.09', 'note' => 'Выбор темы: рекомендации тарифов'],
        ],
        'git' => [],
    ],
    [
        'id'       => 7,
        'title'    => 'Юзабилити-аудит личного кабинета',
        'type'     => 'design',
        'students' => [3, 5],
        'history'  => [
            ['num' => 1, 'date' => '06.09', 'note' => 'Согласование темы'],
            ['num' => 2, 'date' => '13.09', 'note' => 'Сбор метрик и интервью'],
        ],
        'git' => [],
    ],
];

$projectById = [];
$projectsByStudent = [];
foreach ($mockProjects as $proj) {
    $projectById[(int) $proj['id']] = $proj;
    foreach ($proj['students'] as $sid) {
        $projectsByStudent[(int) $sid][] = (int) $proj['id'];
    }
}

function project_current_phase(array $proj): int {
    if (empty($proj['history'])) return 0;
    $last = end($proj['history']);
    return (int) $last['num'];
}

// =============================================================================
// Журнал
// =============================================================================
$journalDates = ['03.09', '05.09', '10.09', '12.09', '17.09', '19.09', '24.09', '26.09', '01.10', '03.10'];

$journalLessons = [
    0 => ['topic' => 'Введение в машинное обучение',       'homework' => 'Прочитать главу 1, ответить на вопросы'],
    1 => ['topic' => 'Линейная регрессия',                 'homework' => 'Реализовать линейную регрессию с нуля'],
    2 => ['topic' => 'Логистическая регрессия',            'homework' => 'Разобрать примеры, выполнить задание 2'],
    3 => ['topic' => 'Деревья решений',                    'homework' => 'Построить дерево на датасете Iris'],
    4 => ['topic' => 'Ансамбли: Random Forest',            'homework' => 'Сравнить Random Forest и одиночное дерево'],
    5 => ['topic' => 'Градиентный бустинг',                'homework' => 'Применить XGBoost на учебном датасете'],
    6 => ['topic' => 'Кластеризация: K-means',             'homework' => 'Кластеризовать клиентов по RFM'],
    7 => ['topic' => 'PCA и снижение размерности',         'homework' => 'Применить PCA к датасету, визуализировать'],
    8 => ['topic' => 'Метрики качества',                   'homework' => 'Рассчитать precision/recall на своём проекте'],
    9 => ['topic' => 'Семинар: разбор проектов',           'homework' => 'Подготовить отчёт по проекту'],
];

$journalMarks = [
    1  => ['5', '·', '4', '·', '5', '·', '4', 'н', '5', '·'],
    2  => ['·', '5', '·', '5', '4', '·', '5', '5', '·', '4'],
    3  => ['4', '4', '·', '·', 'н', '4', '5', '5', '5', '5'],
    4  => ['5', '·', '5', '5', '·', '·', '4', '4', 'н', '5'],
    5  => ['·', '·', 'н', '4', '·', '4', '·', '·', '4', '4'],
    6  => ['4', '5', '5', '·', '5', '5', '·', '4', '5', '·'],
    7  => ['5', '·', '·', '4', '5', 'н', '5', '5', '·', '5'],
    8  => ['·', '4', '4', '·', '·', '5', '4', 'н', '·', '4'],
    9  => ['3', '·', 'н', '·', '4', '·', '·', '4', '4', '·'],
    10 => ['4', '5', '·', '5', '5', '4', '5', '5', '·', '5'],
];

$journalNotes = [
    1  => [1 => 'Просил разобрать подробнее линейную регрессию', 8 => 'Сильно вырос, хвалить'],
    3  => [4 => 'Не был по уважительной причине, отработает',     5 => 'Отвечал на семинаре'],
    5  => [2 => 'Похвалить за активность',                        3 => 'Пропуск — предупредил заранее'],
    7  => [5 => 'Нужна помощь с бустингом, назначить консультацию'],
    8  => [7 => 'Спросить, почему не сдал задание'],
];

// ===== Сводные метрики по журналу (для менеджера) =====
$journalSummary = [
    'dist'       => [5 => 0, 4 => 0, 3 => 0, 2 => 0],
    'absent'     => 0,
    'present'    => 0,
    'cells'      => 0,
    'gradeSum'   => 0,
    'gradeCount' => 0,
];
$perStudentSummary = [];
foreach ($mockStudents as $st) {
    $sid = (int) $st['id'];
    $marks = $journalMarks[$sid] ?? [];
    $sSum = 0; $sCount = 0; $sAbsent = 0; $sPresent = 0;
    $sDist = [5 => 0, 4 => 0, 3 => 0, 2 => 0];
    foreach ($marks as $m) {
        $journalSummary['cells']++;
        if ($m === 'н') {
            $sAbsent++; $journalSummary['absent']++;
        } elseif ($m === '5' || $m === '4' || $m === '3' || $m === '2') {
            $g = (int) $m;
            $sSum += $g; $sCount++;
            $sDist[$g] = ($sDist[$g] ?? 0) + 1;
            $journalSummary['dist'][$g] = ($journalSummary['dist'][$g] ?? 0) + 1;
            $sPresent++; $journalSummary['present']++;
        } elseif ($m === '·') {
            $sPresent++; $journalSummary['present']++;
        }
    }
    $perStudentSummary[$sid] = [
        'student'    => $st,
        'sum'        => $sSum,
        'count'      => $sCount,
        'avg'        => $sCount > 0 ? round($sSum / $sCount, 2) : null,
        'absent'     => $sAbsent,
        'present'    => $sPresent,
        'dist'       => $sDist,
        'attendance' => ($sAbsent + $sPresent) > 0 ? round($sPresent / ($sAbsent + $sPresent) * 100, 1) : null,
    ];
}
foreach ($journalSummary['dist'] as $g => $c) {
    $journalSummary['gradeSum'] += $g * $c;
    $journalSummary['gradeCount'] += $c;
}
$avgGrade = $journalSummary['gradeCount'] > 0
    ? round($journalSummary['gradeSum'] / $journalSummary['gradeCount'], 2)
    : null;
$totalAbsent = $journalSummary['absent'];
$totalPresent = $journalSummary['present'];
$attendance = ($totalAbsent + $totalPresent) > 0
    ? round($totalPresent / ($totalAbsent + $totalPresent) * 100, 1)
    : null;

// ===== Активность родителей =====
$parentVisitsMock = [
    1  => ['count' => 14, 'last' => '20.09', 'trend' => '+3', 'unread_bad' => 0],
    2  => ['count' => 8,  'last' => '18.09', 'trend' => '+1', 'unread_bad' => 0],
    3  => ['count' => 19, 'last' => '21.09', 'trend' => '+5', 'unread_bad' => 1],
    4  => ['count' => 6,  'last' => '15.09', 'trend' => '-2', 'unread_bad' => 1],
    5  => ['count' => 3,  'last' => '10.09', 'trend' => '0',  'unread_bad' => 2],
    6  => ['count' => 11, 'last' => '19.09', 'trend' => '+2', 'unread_bad' => 0],
    7  => ['count' => 16, 'last' => '20.09', 'trend' => '+4', 'unread_bad' => 0],
    8  => ['count' => 4,  'last' => '12.09', 'trend' => '-1', 'unread_bad' => 2],
    9  => ['count' => 2,  'last' => '06.09', 'trend' => '0',  'unread_bad' => 1],
    10 => ['count' => 13, 'last' => '21.09', 'trend' => '+3', 'unread_bad' => 0],
];

$viewParentChildId = (int) ($_GET['child_id'] ?? 0);
if ($viewParentChildId === 0 && $mockStudents !== []) $viewParentChildId = (int) $mockStudents[0]['id'];
$parentChild = $studentById[$viewParentChildId] ?? null;

if (!isset($_SESSION['parent_visits'])) $_SESSION['parent_visits'] = [];
$todayKey = date('Y-m-d');
if (($_GET['role'] ?? '') === 'parent' && $parentChild !== null) {
    $cid = (int) $parentChild['id'];
    if (!isset($_SESSION['parent_visits'][$cid])) $_SESSION['parent_visits'][$cid] = [];
    $_SESSION['parent_visits'][$cid][$todayKey] = ($_SESSION['parent_visits'][$cid][$todayKey] ?? 0) + 1;
}

$parentBadMarks = [];
if ($parentChild !== null) {
    $cid = (int) $parentChild['id'];
    $marks = $journalMarks[$cid] ?? [];
    foreach ($marks as $i => $m) {
        if ($m === '2' || $m === '3') {
            $parentBadMarks[] = [
                'date'  => $journalDates[$i] ?? '',
                'mark'  => $m,
                'topic' => $journalLessons[$i]['topic'] ?? '',
            ];
        }
    }
}

// ===== Дашборд =====
$dashPhaseCounts = [];
foreach ($phases as $p) $dashPhaseCounts[(int) $p['id']] = 0;
$dashUniCounts = [];
foreach ($universities as $u) $dashUniCounts[(int) $u['id']] = 0;
$dashUniMaxPhaseNum = [];
$dashUniPhaseCount = [];
$totalInteractions = 0;
$activeInteractions = 0;
$sumPhase = 0;

foreach ($interactions as $row) {
    $totalInteractions++;
    $pid = $row['phase_id'] !== null ? (int) $row['phase_id'] : 0;
    $uid = (int) $row['university_id'];
    if ($pid > 0 && isset($dashPhaseCounts[$pid])) $dashPhaseCounts[$pid]++;
    if (isset($dashUniCounts[$uid])) $dashUniCounts[$uid]++;
    $num = ($pid > 0 && isset($phaseById[$pid])) ? (int) $phaseById[$pid]['num'] : 0;
    $sumPhase += $num;
    if ($num > 0 && $num < 14) $activeInteractions++;
    if (!isset($dashUniMaxPhaseNum[$uid]) || $num > $dashUniMaxPhaseNum[$uid]) $dashUniMaxPhaseNum[$uid] = $num;
    if ($pid > 0) {
        if (!isset($dashUniPhaseCount[$uid])) $dashUniPhaseCount[$uid] = [];
        $dashUniPhaseCount[$uid][$pid] = ($dashUniPhaseCount[$uid][$pid] ?? 0) + 1;
    }
}

$avgPhase = $totalInteractions > 0 ? round($sumPhase / $totalInteractions, 1) : 0;
$chartPhases = array_values(array_filter($phases, function ($p) { return (int) $p['num'] > 0; }));

$topUniversities = [];
foreach ($universities as $u) {
    $uid = (int) $u['id'];
    $topUniversities[] = ['id' => $uid, 'short' => $u['short'], 'full' => $u['name'], 'count' => $dashUniCounts[$uid] ?? 0];
}
usort($topUniversities, function ($a, $b) { return $b['count'] - $a['count']; });
$topUniversities = array_slice($topUniversities, 0, 8);

$monthsRu = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
$nowMonth = (int) date('n');
$chartNewProjectsByMonth = [];
$monthly = [];
for ($i = 11; $i >= 0; $i--) {
    $m = $nowMonth - $i;
    while ($m <= 0) $m += 12;
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
            if ($val > 0) $byUniPhase[(string) $uid][(string) $pid] = $val;
        }
    }
    $chartNewProjectsByMonth[] = 1 + ($seed % 4);
    $monthly[] = ['label' => $monthsRu[$m - 1], 'byUniPhase' => $byUniPhase];
}

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
    1  => [55.7906, 49.1221], 2  => [55.9297, 37.5213], 3  => [55.6497, 37.6642],
    4  => [59.8822, 29.8258], 5  => [54.8473, 83.0930], 6  => [56.8439, 60.6526],
    7  => [47.2225, 39.7188], 8  => [43.1155, 131.8855], 9 => [56.4653, 84.9508], 10 => [55.8337, 49.1254],
];

$mapPoints = [];
foreach ($universities as $u) {
    $uid = (int) $u['id'];
    if (!isset($universityCoords[$uid])) continue;
    $maxNum   = $dashUniMaxPhaseNum[$uid] ?? 0;
    $maxPhase = $maxNum > 0 ? ($phaseByNum[$maxNum] ?? null) : null;
    $color    = $maxPhase !== null ? $maxPhase['color'] : '#9ca3af';
    [$sx, $sy] = project_point($universityCoords[$uid][0], $universityCoords[$uid][1], $mapViewWidth, $mapViewHeight, $lngMin, $lngMax, $latMin, $latMax);
    $mapPoints[] = [
        'id'       => $uid, 'name' => $u['name'], 'short' => $u['short'],
        'x'        => $sx, 'y' => $sy, 'color' => $color,
        'phase'    => $maxPhase !== null ? $maxPhase['name'] : 'Не начато',
        'projects' => $dashUniCounts[$uid] ?? 0,
    ];
}

$dashPayload = [
    'phases' => array_map(function ($p) {
        return ['id' => (int) $p['id'], 'num' => (int) $p['num'], 'name' => $p['name'], 'color' => $p['color']];
    }, $chartPhases),
    'universities' => array_map(function ($u) {
        return ['id' => $u['id'], 'short' => $u['short'], 'name' => $u['full']];
    }, $topUniversities),
    'allUniversities' => array_map(function ($u) {
        return ['id' => (int) $u['id'], 'short' => $u['short'], 'name' => $u['name']];
    }, $universities),
    'mapPoints'   => $mapPoints,
    'monthly'     => $monthly,
    'newProjects' => $chartNewProjectsByMonth,
];

$viewRole = $_GET['role'] ?? 'manager';
if (!in_array($viewRole, ['manager', 'university', 'supervisor', 'student', 'parent'], true)) {
    $viewRole = 'manager';
}

$viewUniId = (int) ($_GET['uni_id'] ?? 0);
if ($viewRole === 'university' && $viewUniId === 0 && $universities !== []) $viewUniId = (int) $universities[0]['id'];

$selectedUni = null;
foreach ($universities as $u) {
    if ((int) $u['id'] === $viewUniId) { $selectedUni = $u; break; }
}

$uniProjects = [];
if ($viewRole === 'university') {
    foreach ($projectOptions as $opt) {
        if ((int) $opt['university_id'] === $viewUniId) {
            $opt['workflow'] = build_project_workflow($workflowSteps, $sideLabels, $stepStates, $currentStates, (int) $opt['phase_num'], (int) $opt['id']);
            $uniProjects[] = $opt;
        }
    }
}

$universityNotification = $_SESSION['university_notification'] ?? null;
$managerNotification = $_SESSION['manager_notification'] ?? null;
if ($managerNotification !== null) unset($_SESSION['manager_notification']);

$managerLabel = $currentUser !== null ? ($currentUser['full_name'] ?: $currentUser['login']) : 'Павел';

$viewStudentId = (int) ($_GET['student_id'] ?? 0);
if ($viewRole === 'student' && $viewStudentId === 0 && $mockStudents !== []) $viewStudentId = (int) $mockStudents[0]['id'];
$selectedStudent = $studentById[$viewStudentId] ?? null;
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
            --font-mono: 'JetBrains Mono', monospace;
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

        .auth-body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background-color: var(--color-light); padding: var(--space-lg); }
        .auth-card { background-color: var(--color-white); border: 1px solid rgba(26, 26, 26, 0.1); padding: var(--space-xl); width: 100%; max-width: 420px; }
        .auth-card__brand { font-size: 2rem; font-weight: 600; text-align: center; margin-bottom: var(--space-xs); }
        .auth-card__subtitle { font-size: 0.9rem; color: var(--color-accent); text-align: center; margin-bottom: var(--space-lg); font-weight: 500; }
        .auth-form label { display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: var(--space-xs); color: var(--color-secondary); }

        .auth-form input[type="text"],
        .auth-form input[type="password"],
        .auth-form select {
            width: 100%; padding: 0.75rem var(--space-sm);
            border: 1px solid rgba(26, 26, 26, 0.15);
            background-color: var(--color-white); color: var(--color-primary);
            font-family: var(--font-sans); font-size: 0.95rem;
            margin-bottom: var(--space-md); transition: var(--transition-base);
        }
        .auth-form input:focus, .auth-form select:focus { outline: none; border-color: var(--color-accent); }
        .auth-form button {
            width: 100%; padding: var(--space-sm);
            background-color: var(--color-accent); color: var(--color-white);
            border: none; font-family: var(--font-sans); font-size: 1rem; font-weight: 500;
            cursor: pointer; transition: var(--transition-base);
        }
        .auth-form button:hover { background-color: var(--color-accent-light); }
        .auth-error { background-color: #fdf3f2; color: #9b2c1f; border-left: 2px solid #9b2c1f; padding: 0.75rem var(--space-sm); font-size: 0.85rem; margin-bottom: var(--space-md); }

        .dashboard { display: flex; flex-direction: column; min-height: 100vh; }

        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: var(--space-sm);
            padding: var(--space-md) var(--space-lg);
            background-color: var(--color-white); border-bottom: 1px solid rgba(26, 26, 26, 0.1);
        }
        .topbar__title { font-family: var(--font-serif); font-size: 1.5rem; font-weight: 600; }
        .topbar__title span { color: var(--color-accent); font-size: 0.85rem; font-family: var(--font-sans); font-weight: 400; display: block; letter-spacing: 0.08em; text-transform: uppercase; }
        .topbar__user { display: flex; align-items: center; gap: var(--space-sm); font-size: 0.9rem; }

        .role-switch { display: inline-flex; flex-direction: column; border: 1px solid rgba(26, 26, 26, 0.18); background-color: var(--color-white); border-radius: 2px; overflow: hidden; }
        .role-switch__row { display: flex; }
        .role-switch__row + .role-switch__row { border-top: 1px solid rgba(26, 26, 26, 0.18); }

        .role-switch__btn {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.5rem 0.9rem; font-size: 0.8rem; font-weight: 500;
            color: var(--color-secondary); background-color: var(--color-white);
            border-right: 1px solid rgba(26, 26, 26, 0.18);
            transition: var(--transition-base); white-space: nowrap;
            flex: 1; justify-content: flex-start;
        }
        .role-switch__btn:last-child { border-right: none; }
        .role-switch__btn:hover { background-color: rgba(139, 105, 20, 0.08); color: var(--color-primary); }
        .role-switch__btn.active { background-color: var(--color-accent); color: var(--color-white); }
        .role-switch__btn.active:hover { color: var(--color-white); }
        .role-switch__emoji { font-size: 1rem; line-height: 1; flex-shrink: 0; filter: saturate(0.9); }
        .role-switch__label { white-space: nowrap; }

        .topbar__logout { padding: 0.5rem var(--space-md); border: 1px solid rgba(26, 26, 26, 0.2); font-size: 0.85rem; transition: var(--transition-base); }
        .topbar__logout:hover { border-color: var(--color-accent); background-color: rgba(139, 105, 20, 0.05); }

        .mainnav { display: flex; align-items: center; gap: var(--space-xs); padding: 0 var(--space-lg); background-color: var(--color-light); border-bottom: 1px solid rgba(26, 26, 26, 0.1); }
        .mainnav__caption { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--color-tertiary); margin-right: var(--space-sm); }
        .mainnav a { display: block; padding: 0.65rem var(--space-sm); font-size: 0.9rem; border-bottom: 2px solid transparent; }
        .mainnav a:hover, .mainnav a.active { background-color: rgba(139, 105, 20, 0.1); border-bottom-color: var(--color-accent); color: var(--color-primary); }

        .content { flex: 1; min-height: 0; padding: var(--space-lg); }

        .notification {
            display: flex; align-items: center; justify-content: space-between;
            gap: var(--space-md); padding: 12px 16px; margin-bottom: var(--space-md);
            border-left: 4px solid; font-size: 0.9rem; line-height: 1.4;
        }
        .notification--pending { background-color: #fffbeb; border-color: #d97706; color: #78350f; }
        .notification--info    { background-color: #eff6ff; border-color: #2563eb; color: #1e3a8a; }
        .notification--success { background-color: #f0fdf4; border-color: #16a34a; color: #14532d; }
        .notification--danger  { background-color: #fef2f2; border-color: #dc2626; color: #7f1d1d; }
        .notification__icon { font-size: 1.2rem; flex-shrink: 0; }
        .notification__body { flex: 1; }
        .notification__actions { display: flex; gap: 8px; flex-shrink: 0; }
        .notification__btn { padding: 6px 14px; font-size: 0.8rem; font-weight: 500; border: 1px solid currentColor; background: transparent; cursor: pointer; font-family: var(--font-sans); transition: var(--transition-base); }
        .notification__btn--primary { background-color: #d97706; color: #fff; border-color: #d97706; }
        .notification__btn--primary:hover { background-color: #b45309; border-color: #b45309; }
        .notification__btn--danger { color: #dc2626; }
        .notification__btn--danger:hover { background-color: rgba(220, 38, 38, 0.08); }

        .workspace { display: flex; flex-direction: column; gap: var(--space-md); min-height: calc(100vh - 46px); }
        .workspace--uni { min-height: calc(100vh - 46px - 62px); }
        .workspace--uni .panel--workflow { flex: 0 0 auto; min-height: 180px; display: flex; flex-direction: column; }
        .workspace--dash { min-height: calc(100vh - 46px - 62px); }
        .workspace--student { min-height: calc(100vh - 46px - 62px); }
        .workspace--parent { min-height: calc(100vh - 46px - 62px); }

        .panel { background-color: var(--color-white); border: 1px solid rgba(26, 26, 26, 0.1); padding: var(--space-md); }
        .panel--workflow { flex: 0 0 auto; min-height: 230px; display: flex; flex-direction: column; }
        .panel--matrix { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
        .panel--dashboard { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
        .panel--students { flex: 0 0 auto; display: flex; flex-direction: column; }
        .panel--tabs { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }

        .panel__head { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: var(--space-sm); margin-bottom: var(--space-sm); }
        .panel__title { font-size: 1.4rem; }
        .panel__subtitle { color: var(--color-tertiary); font-size: 0.85rem; }

        .panel__hint { display: inline-block; margin-top: 6px; font-size: 0.78rem; color: var(--color-accent-dark); background-color: rgba(139, 105, 20, 0.08); border: 1px dashed var(--color-accent); padding: 3px 8px; }
        .panel__hint--phase { background-color: rgba(37, 99, 235, 0.08); border-color: #2563eb; color: #1d4ed8; }

        .project-select { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; }
        .project-select select { padding: 0.4rem 0.6rem; border: 1px solid rgba(26, 26, 26, 0.15); background-color: var(--color-white); font-family: var(--font-sans); font-size: 0.85rem; max-width: 380px; }

        .workflow-scroll { flex: 1; min-height: 0; display: flex; flex-direction: column; }
        .workflow-scroll--uni { overflow-y: auto; padding-right: 4px; }
        .workflow-track { display: flex; align-items: stretch; gap: 4px; padding: 4px 2px 8px; }

        .wstep {
            position: relative; flex: 1 1 0; min-width: 0; min-height: 108px;
            border: 1px solid rgba(26, 26, 26, 0.12); border-top: 4px solid rgba(26, 26, 26, 0.2);
            background-color: var(--color-light); padding: 26px 6px 6px;
            display: flex; flex-direction: column; gap: 4px; transition: var(--transition-base);
        }
        .wstep:hover { border-color: var(--color-accent); }
        .wstep.current { border-color: var(--color-accent); background-color: rgba(139, 105, 20, 0.06); box-shadow: 0 0 0 1px var(--color-accent); }
        .wstep__num { position: absolute; top: 4px; left: 6px; font-size: 0.75rem; font-weight: 600; color: var(--color-tertiary); }
        .wstep.current .wstep__num { color: var(--color-accent-dark); }
        .wstep__side { position: absolute; top: 4px; right: 6px; font-size: 0.6rem; font-weight: 600; letter-spacing: 0.04em; padding: 1px 5px; border: 1px solid rgba(26, 26, 26, 0.25); color: var(--color-secondary); background-color: var(--color-white); }
        .wstep__side--both { border-color: var(--color-accent); color: var(--color-accent-dark); }
        .wstep__name { font-size: 0.66rem; line-height: 1.2; color: var(--color-secondary); overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; }
        .wstep__status { margin-top: auto; font-size: 0.62rem; font-weight: 500; padding: 1px 5px; border: 1px solid rgba(26, 26, 26, 0.2); background-color: var(--color-white); align-self: flex-start; white-space: nowrap; }
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

        @media (max-width: 1100px) { .workflow-track { overflow-x: auto; } .wstep { flex: 0 0 96px; } }

        .wf-legend { display: flex; flex-wrap: wrap; gap: 0.4rem var(--space-md); margin-top: auto; padding-top: var(--space-xs); border-top: 1px solid rgba(26, 26, 26, 0.08); }
        .wf-legend__item { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.72rem; color: var(--color-secondary); }
        .wf-legend__swatch { width: 12px; height: 12px; border: 1px solid rgba(26, 26, 26, 0.2); flex-shrink: 0; }

        .uni-workflow { padding: 10px 0 12px; border-bottom: 1px solid rgba(26, 26, 26, 0.08); }
        .uni-workflow:first-child { padding-top: 0; }
        .uni-workflow:last-child { border-bottom: none; padding-bottom: 0; }
        .uni-workflow__head { display: flex; align-items: baseline; justify-content: space-between; gap: var(--space-sm); flex-wrap: wrap; margin-bottom: 4px; }
        .uni-workflow__title { font-size: 0.85rem; font-weight: 600; color: var(--color-primary); }
        .uni-workflow__meta { font-size: 0.72rem; color: var(--color-tertiary); }

        .uni-empty { padding: var(--space-md); font-size: 0.9rem; color: var(--color-tertiary); text-align: center; background-color: var(--color-light); border: 1px dashed rgba(26, 26, 26, 0.15); }

        .matrix-scroll { flex: 1; min-height: 0; overflow: auto; border: 1px solid rgba(26, 26, 26, 0.1); }
        body.is-dragging .matrix-scroll { perspective: 1200px; }

        table.matrix { width: 100%; min-width: 880px; table-layout: fixed; border-collapse: separate; border-spacing: 0; font-size: 0.8rem; background-color: var(--color-white); transition: transform 0.25s ease-out; transform-origin: 50% 50%; }
        body.is-dragging table.matrix { transform: rotateX(7deg); }

        table.matrix th, table.matrix td {
            border-right: 1px solid rgba(26, 26, 26, 0.1); border-bottom: 1px solid rgba(26, 26, 26, 0.1);
            padding: 3px; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        table.matrix thead th { background-color: var(--color-primary); color: var(--color-white); font-weight: 500; position: sticky; top: 0; z-index: 2; padding: 0.4rem 0.3rem; }
        table.matrix tbody th { background-color: var(--color-light); text-align: left; font-weight: 500; position: sticky; left: 0; z-index: 1; padding: 0.4rem 0.3rem; }

        table.matrix .uni { display: flex; align-items: center; gap: 0.4rem; min-width: 0; }
        table.matrix .uni__crest { width: 20px; height: 20px; object-fit: contain; flex-shrink: 0; background-color: var(--color-white); border: 1px solid rgba(26, 26, 26, 0.12); border-radius: 50%; padding: 1px; }
        table.matrix .uni__initial { width: 20px; height: 20px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 0.65rem; font-weight: 600; color: var(--color-white); background-color: var(--color-accent); border-radius: 50%; }
        table.matrix .uni__code { overflow: hidden; text-overflow: ellipsis; }
        table.matrix thead th:first-child { z-index: 3; }

        table.matrix td { color: var(--color-primary); font-weight: 500; cursor: default; height: 52px; position: relative; }
        table.matrix td.empty { background-color: var(--color-white); color: rgba(26, 26, 26, 0.25); padding: 0.4rem 0.3rem; }
        table.matrix td.phase { padding: 3px; }

        table.matrix td.phase .sticker-cell {
            position: relative; display: flex; align-items: center; justify-content: center;
            width: 100%; height: 100%; min-height: 42px;
            background-color: var(--phase-color, #fde68a);
            background-image: linear-gradient(180deg, rgba(255, 255, 255, 0.35) 0%, rgba(255, 255, 255, 0.08) 45%, rgba(0, 0, 0, 0.10) 100%);
            border-radius: 2px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.15), 0 2px 6px rgba(0, 0, 0, 0.08);
            font-weight: 700; color: #ffffff; font-size: 1rem;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.45);
            transition: transform 0.2s ease-out, box-shadow 0.2s ease-out, opacity 0.2s;
            overflow: hidden;
        }
        table.matrix td.phase .sticker-cell::before {
            content: ''; position: absolute; top: -2px; left: 50%; width: 22px; height: 8px;
            margin-left: -11px; background: rgba(255, 255, 255, 0.55);
            border: 1px solid rgba(0, 0, 0, 0.04); transform: rotate(-2deg); border-radius: 1px;
        }
        table.matrix td.clickable { cursor: grab; }
        table.matrix td.clickable:active { cursor: grabbing; }
        table.matrix td.clickable:hover .sticker-cell {
            transform: translateY(-2px) rotate(-1.5deg);
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.18), 0 6px 14px rgba(0, 0, 0, 0.12);
        }
        table.matrix td.is-current-project .sticker-cell {
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.2), 0 8px 18px rgba(0, 0, 0, 0.16), inset 0 0 0 3px var(--color-accent-dark);
            transform: rotate(-2deg) scale(1.05);
        }
        table.matrix td.is-current-project .sticker-cell::after {
            content: '◆'; position: absolute; top: 1px; right: 3px; font-size: 0.55rem; line-height: 1; color: #ffffff; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.55);
        }
        table.matrix td.is-pending .sticker-cell { animation: pulse-pending 1.8s ease-in-out infinite; outline: 2px dashed #b45309; outline-offset: -2px; }
        @keyframes pulse-pending {
            0%, 100% { box-shadow: 0 1px 2px rgba(0,0,0,0.15), 0 0 0 0 rgba(180, 83, 9, 0.5); }
            50%      { box-shadow: 0 1px 2px rgba(0,0,0,0.15), 0 0 0 10px rgba(180, 83, 9, 0); }
        }
        body.is-dragging table.matrix td.phase .sticker-cell { opacity: 0.35; filter: grayscale(0.4); }
        body.is-dragging table.matrix td.is-drag-source { z-index: 100; overflow: visible; }
        body.is-dragging table.matrix td.is-drag-source .sticker-cell {
            opacity: 1; filter: none;
            transform: rotate(-7deg) scale(1.25) translateZ(60px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.28), 0 24px 48px rgba(0, 0, 0, 0.22);
        }

        .phase-pocket {
            position: fixed; z-index: 10000; width: 240px; padding: 10px 12px 12px;
            background: var(--color-white); border: 2px dashed var(--color-accent); border-radius: 6px;
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.18), 0 16px 40px rgba(0, 0, 0, 0.15);
            font-family: var(--font-sans); cursor: copy; opacity: 0;
            transform: translateY(8px) scale(0.94);
            transition: transform 0.2s ease-out, box-shadow 0.2s, background-color 0.2s, border-color 0.2s;
            pointer-events: auto; user-select: none;
        }
        .phase-pocket.is-visible { opacity: 1; transform: translateY(0) scale(1); }
        .phase-pocket.is-hover { background: rgba(139, 105, 20, 0.1); border-style: solid; transform: translateY(0) scale(1.05); box-shadow: 0 10px 24px rgba(0, 0, 0, 0.22), 0 20px 48px rgba(0, 0, 0, 0.18); }
        .phase-pocket__label { font-size: 0.62rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--color-tertiary); margin-bottom: 6px; display: flex; align-items: center; gap: 5px; }
        .phase-pocket__label-arrow { font-size: 0.9rem; font-weight: 700; color: var(--color-accent-dark); line-height: 1; }
        .phase-pocket__body { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 500; color: var(--color-primary); line-height: 1.25; }
        .phase-pocket__num { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; color: #fff; font-size: 0.75rem; font-weight: 700; flex-shrink: 0; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3); }
        .phase-pocket__name { flex: 1; min-width: 0; }
        .phase-pocket__lock { margin-left: auto; font-size: 0.9rem; flex-shrink: 0; }
        .phase-pocket__pin { position: absolute; left: 50%; width: 10px; height: 10px; margin-left: -5px; background: var(--color-white); border: 2px dashed var(--color-accent); border-radius: 50%; }
        .phase-pocket--prev .phase-pocket__pin { bottom: -7px; border-top-color: transparent; border-right-color: transparent; transform: rotate(45deg); }
        .phase-pocket--next .phase-pocket__pin { top: -7px; border-bottom-color: transparent; border-left-color: transparent; transform: rotate(45deg); }

        .legend { margin-top: var(--space-md); padding: var(--space-sm) var(--space-md); background-color: var(--color-light); border: 1px solid rgba(26, 26, 26, 0.1); flex-shrink: 0; }
        .legend__title { font-family: var(--font-serif); font-size: 1.1rem; margin-bottom: var(--space-sm); }
        .legend__items { display: flex; flex-wrap: wrap; gap: 0.5rem var(--space-md); }
        .legend__item { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; color: var(--color-secondary); }
        .legend__swatch { width: 16px; height: 16px; border: 1px solid rgba(26, 26, 26, 0.2); flex-shrink: 0; border-radius: 2px; }

        .tabs { display: flex; gap: 2px; border-bottom: 1px solid rgba(26, 26, 26, 0.1); margin-bottom: var(--space-md); }
        .tab {
            padding: 10px 20px; font-family: var(--font-sans); font-size: 0.9rem; font-weight: 500;
            border: 1px solid transparent; border-bottom: none; background: transparent;
            color: var(--color-secondary); cursor: pointer; transition: var(--transition-base);
            border-radius: 2px 2px 0 0; position: relative; top: 1px;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .tab:hover { background: rgba(139, 105, 20, 0.06); color: var(--color-primary); }
        .tab.is-active { background: var(--color-white); color: var(--color-primary); border-color: rgba(26, 26, 26, 0.1); border-bottom: 1px solid var(--color-white); font-weight: 600; }
        .tab.is-active::after { content: ''; position: absolute; left: 10px; right: 10px; bottom: -1px; height: 3px; background: var(--color-accent); }
        .tab-content { display: none; flex: 1; min-height: 0; flex-direction: column; }
        .tab-content.is-active { display: flex; }

        .project-type-chip {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 12px;
            font-size: 0.72rem; font-weight: 600;
            color: #fff;
            background: var(--type-color, #8b6914);
            background-image: linear-gradient(180deg, rgba(255,255,255,0.25) 0%, rgba(0,0,0,0.08) 100%);
            white-space: nowrap;
        }

        .participant-list { display: flex; flex-direction: column; gap: 4px; }
        .participant-row { display: flex; align-items: center; gap: 6px; font-size: 0.78rem; }
        .participant-row__name { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .phase-timeline-wrap { flex: 1; min-height: 0; overflow: auto; border: 1px solid rgba(26, 26, 26, 0.1); background: var(--color-white); }
        table.students-timeline { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.82rem; }
        table.students-timeline th, table.students-timeline td { border-bottom: 1px solid rgba(26, 26, 26, 0.1); padding: 10px 12px; vertical-align: middle; }
        table.students-timeline thead th { background-color: var(--color-primary); color: #fff; font-weight: 500; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; text-align: left; position: sticky; top: 0; z-index: 2; }
        table.students-timeline tbody tr:hover { background-color: rgba(139, 105, 20, 0.04); }
        table.students-timeline td.project-cell-title { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        table.students-timeline td.project-cell-title .student-sub { display: block; font-weight: 400; font-size: 0.7rem; color: var(--color-tertiary); margin-top: 4px; }

        .phase-timeline { display: flex; align-items: center; gap: 4px; flex-wrap: nowrap; overflow-x: auto; padding: 4px 0; }
        .phase-chip {
            display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border-radius: 2px;
            font-size: 0.72rem; font-weight: 600; color: #fff; white-space: nowrap; flex-shrink: 0;
            background: var(--phase-color);
            background-image: linear-gradient(180deg, rgba(255, 255, 255, 0.3) 0%, rgba(0, 0, 0, 0.1) 100%);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.15); position: relative; cursor: default;
        }
        .phase-chip::before { content: ''; position: absolute; top: -2px; left: 50%; width: 14px; height: 5px; margin-left: -7px; background: rgba(255, 255, 255, 0.55); border-radius: 1px; }
        .phase-chip__num { display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; border-radius: 50%; background: rgba(0, 0, 0, 0.2); font-size: 0.65rem; font-weight: 700; line-height: 1; }
        .phase-chip__date { font-size: 0.65rem; font-weight: 400; opacity: 0.92; }
        .phase-arrow { color: var(--color-tertiary); font-size: 0.85rem; flex-shrink: 0; line-height: 1; padding: 0 1px; }
        .phase-arrow--return { color: #b45309; font-weight: 700; }
        .phase-timeline-empty { font-size: 0.78rem; color: var(--color-tertiary); font-style: italic; }

        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
            gap: var(--space-md);
        }

        .project-card {
            background: var(--color-white);
            border: 1px solid rgba(26, 26, 26, 0.1);
            border-left: 4px solid var(--type-color, var(--color-accent));
            padding: var(--space-md);
            display: flex;
            flex-direction: column;
            gap: var(--space-sm);
            transition: box-shadow 0.15s, transform 0.15s;
        }
        .project-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.06); }

        .project-card__head { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-sm); flex-wrap: wrap; }
        .project-card__title {
            font-family: var(--font-serif);
            font-size: 1.2rem;
            font-weight: 500;
            line-height: 1.2;
            color: var(--color-primary);
        }
        .project-card__participants {
            font-size: 0.78rem;
            color: var(--color-tertiary);
            margin-top: 6px;
        }
        .project-card__participants strong { color: var(--color-secondary); font-weight: 500; }

        .project-card__stepper {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
        }
        .step-mini {
            position: relative;
            padding: 8px 8px 8px 32px;
            background: var(--color-light);
            border: 1px solid rgba(26, 26, 26, 0.08);
            border-left: 3px solid rgba(26, 26, 26, 0.2);
            font-size: 0.72rem;
            line-height: 1.2;
        }
        .step-mini__num {
            position: absolute;
            left: 6px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px; height: 20px; border-radius: 50%;
            background: rgba(26, 26, 26, 0.06);
            color: var(--color-tertiary);
            display: inline-flex; align-items: center; justify-content: center;
            font-family: var(--font-serif);
            font-weight: 600;
            font-size: 0.75rem;
        }
        .step-mini__name { display: block; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }

        .step-mini--done { border-left-color: #16a34a; background: rgba(22, 163, 74, 0.05); }
        .step-mini--done .step-mini__num { background: rgba(22, 163, 74, 0.15); color: #15803d; }
        .step-mini--current { border-left-color: #2563eb; background: rgba(37, 99, 235, 0.05); box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.15); }
        .step-mini--current .step-mini__num { background: #2563eb; color: #fff; }
        .step-mini--pending { opacity: 0.7; }

        .project-card__section-label {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--color-tertiary);
            margin-bottom: 4px;
            font-weight: 600;
        }

        .project-card .git-history { margin-top: var(--space-xs); }
        .project-card__git-empty {
            font-size: 0.78rem;
            color: var(--color-tertiary);
            font-style: italic;
        }

        .journal-wrap { flex: 1; min-height: 0; overflow: auto; border: 1px solid rgba(26, 26, 26, 0.1); background: var(--color-white); margin-bottom: var(--space-sm); }
        table.journal { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.82rem; table-layout: fixed; }
        table.journal th, table.journal td { border-right: 1px solid rgba(26, 26, 26, 0.1); border-bottom: 1px solid rgba(26, 26, 26, 0.1); padding: 6px 4px; text-align: center; vertical-align: middle; }
        table.journal thead th { background-color: var(--color-primary); color: #fff; font-weight: 500; font-size: 0.72rem; letter-spacing: 0.04em; padding: 8px 4px; position: sticky; top: 0; z-index: 2; cursor: pointer; transition: background 0.15s; }
        table.journal thead th:first-child { text-align: left; cursor: default; width: 220px; z-index: 3; }
        table.journal thead th[data-date-index]:hover { background-color: var(--color-accent-dark); }
        table.journal thead th.is-selected { background-color: var(--color-accent); box-shadow: inset 0 -3px 0 var(--color-accent-dark); }
        table.journal tbody td:first-child { text-align: left; font-weight: 500; background-color: var(--color-light); position: sticky; left: 0; z-index: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        table.journal tbody tr:hover td:not(:first-child) { background-color: rgba(139, 105, 20, 0.04); }
        table.journal td.journal-cell { padding: 4px; position: relative; cursor: pointer; transition: background 0.15s; }
        table.journal td.journal-cell:hover { background-color: rgba(139, 105, 20, 0.08); }
        table.journal td.journal-cell.is-highlighted { background-color: rgba(139, 105, 20, 0.1); }

        .journal-mark { display: inline-flex; align-items: center; justify-content: center; min-width: 24px; height: 24px; font-weight: 600; font-size: 0.85rem; border-radius: 2px; }
        .journal-mark--grade-5 { color: #16a34a; }
        .journal-mark--grade-4 { color: #2563eb; }
        .journal-mark--grade-3 { color: #d97706; }
        .journal-mark--grade-2 { color: #dc2626; }
        .journal-mark--dot { color: var(--color-accent); font-size: 1rem; }
        .journal-mark--absent { color: #dc2626; font-weight: 700; background-color: rgba(220, 38, 38, 0.08); }

        .journal-note-dot {
            display: inline-flex; align-items: center; justify-content: center;
            width: 26px; height: 26px; border-radius: 50%;
            background: rgba(217, 119, 6, 0.14); color: #b45309;
            font-size: 0.9rem; line-height: 1; font-weight: 700;
            transition: background 0.15s, transform 0.15s, box-shadow 0.15s;
            user-select: none;
        }
        .journal-cell:hover .journal-note-dot {
            background: rgba(217, 119, 6, 0.32);
            transform: scale(1.1);
            box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.12);
        }

        .journal-lesson { padding: var(--space-md); background: linear-gradient(135deg, #fafaf7 0%, #f0ece0 100%); border-left: 4px solid var(--color-accent); transition: all 0.2s ease-out; min-height: 90px; }
        .journal-lesson__date { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-accent-dark); font-weight: 600; margin-bottom: 6px; }
        .journal-lesson__title { font-family: var(--font-serif); font-size: 1.15rem; font-weight: 500; line-height: 1.25; color: var(--color-primary); margin-bottom: 8px; }
        .journal-lesson__homework { font-size: 0.85rem; color: var(--color-secondary); line-height: 1.5; }
        .journal-lesson__homework strong { color: var(--color-accent-dark); font-weight: 600; }
        .journal-lesson--empty .journal-lesson__title { color: var(--color-tertiary); font-size: 0.95rem; font-family: var(--font-sans); font-weight: 400; }

        .note-popover {
            position: fixed; z-index: 20000; width: 340px; background: var(--color-white);
            border: 1px solid rgba(26, 26, 26, 0.15); border-left: 4px solid var(--color-accent);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15), 0 20px 48px rgba(0, 0, 0, 0.1);
            padding: 14px 16px; font-family: var(--font-sans); font-size: 0.85rem;
            opacity: 0; transform: translateY(-6px) scale(0.98);
            transition: opacity 0.18s ease-out, transform 0.18s ease-out;
            pointer-events: none;
        }
        .note-popover.is-visible { opacity: 1; transform: translateY(0) scale(1); pointer-events: auto; }
        .note-popover__header { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 8px; }
        .note-popover__student { font-family: var(--font-serif); font-size: 1.05rem; font-weight: 500; line-height: 1.15; color: var(--color-primary); }
        .note-popover__meta { font-size: 0.7rem; color: var(--color-tertiary); margin-top: 4px; }
        .note-popover__mark { display: inline-flex; align-items: center; justify-content: center; min-width: 30px; height: 30px; border-radius: 3px; font-size: 0.95rem; font-weight: 700; background: rgba(139, 105, 20, 0.1); padding: 0 8px; flex-shrink: 0; }
        .note-popover__mark--grade-5 { background: rgba(22, 163, 74, 0.12); color: #16a34a; }
        .note-popover__mark--grade-4 { background: rgba(37, 99, 235, 0.12); color: #2563eb; }
        .note-popover__mark--grade-3 { background: rgba(217, 119, 6, 0.15); color: #d97706; }
        .note-popover__mark--grade-2 { background: rgba(220, 38, 38, 0.12); color: #dc2626; }
        .note-popover__mark--dot    { background: rgba(139, 105, 20, 0.14); color: var(--color-accent-dark); }
        .note-popover__mark--absent { background: rgba(220, 38, 38, 0.12); color: #dc2626; }
        .note-popover__mark--none   { background: rgba(26, 26, 26, 0.06); color: var(--color-tertiary); }
        .note-popover__label { display: block; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-tertiary); margin-bottom: 4px; }
        .note-popover__textarea { width: 100%; min-height: 80px; padding: 8px 10px; font-family: var(--font-sans); font-size: 0.85rem; line-height: 1.45; border: 1px solid rgba(26, 26, 26, 0.15); background: #fdfcf9; color: var(--color-primary); resize: vertical; transition: border-color 0.15s; }
        .note-popover__textarea:focus { outline: none; border-color: var(--color-accent); background: #fff; }
        .note-popover__actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px; }
        .note-popover__btn { padding: 6px 14px; font-size: 0.78rem; font-weight: 500; font-family: var(--font-sans); border: 1px solid rgba(26, 26, 26, 0.15); background: #fff; color: var(--color-secondary); cursor: pointer; transition: 0.15s; }
        .note-popover__btn:hover { background: rgba(0, 0, 0, 0.04); color: var(--color-primary); }
        .note-popover__btn--primary { background: var(--color-accent); color: #fff; border-color: var(--color-accent); }
        .note-popover__btn--primary:hover { background: var(--color-accent-light); color: #fff; }
        .note-popover__btn--danger { color: #dc2626; }
        .note-popover__btn--danger:hover { background: rgba(220, 38, 38, 0.08); }
        .note-popover__hint { font-size: 0.68rem; color: var(--color-tertiary); margin-top: 8px; font-style: italic; }

        .students-layout { display: grid; grid-template-columns: 1fr 340px; gap: var(--space-md); align-items: start; }
        @media (max-width: 1100px) { .students-layout { grid-template-columns: 1fr; } }
        .students-block { background: var(--color-white); }

        .git-history { background: #1a1a1a; border-radius: 3px; padding: 12px 0; font-family: var(--font-mono); font-size: 0.75rem; color: #d4d4d4; line-height: 1.5; overflow: hidden; }
        .git-history__header { display: flex; align-items: center; gap: 8px; padding: 0 14px 10px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); margin-bottom: 8px; color: #8b6914; font-weight: 500; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.08em; }
        .git-history__branch { display: inline-flex; align-items: center; gap: 4px; padding: 1px 8px; border-radius: 10px; background: rgba(139, 105, 20, 0.2); color: #d4b35a; font-size: 0.68rem; letter-spacing: 0.02em; text-transform: none; }
        .git-history__list { display: flex; flex-direction: column; padding: 0 4px; }
        .git-history__item { display: grid; grid-template-columns: 12px 70px 1fr; gap: 10px; padding: 5px 10px; border-radius: 2px; transition: background 0.15s; align-items: baseline; }
        .git-history__item:hover { background: rgba(255, 255, 255, 0.04); }
        .git-history__graph { position: relative; height: 100%; display: flex; align-items: center; justify-content: center; }
        .git-history__graph::before { content: ''; position: absolute; top: -5px; bottom: -5px; left: 50%; width: 1px; background: rgba(139, 105, 20, 0.4); margin-left: -0.5px; }
        .git-history__item:first-child .git-history__graph::before { top: 50%; }
        .git-history__item:last-child .git-history__graph::before { bottom: 50%; }
        .git-history__dot { position: relative; width: 8px; height: 8px; border-radius: 50%; background: #8b6914; z-index: 1; box-shadow: 0 0 0 2px #1a1a1a; }
        .git-history__item:first-child .git-history__dot { background: #d4b35a; box-shadow: 0 0 0 2px #1a1a1a, 0 0 8px rgba(212, 179, 90, 0.5); }
        .git-history__hash { color: #8b6914; font-size: 0.7rem; letter-spacing: 0.02em; }
        .git-history__content { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .git-history__msg { color: #e5e5e5; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .git-history__meta { color: rgba(255, 255, 255, 0.45); font-size: 0.68rem; }

        .student-header { display: flex; align-items: center; gap: var(--space-md); padding: var(--space-md); background: linear-gradient(135deg, #fafaf7 0%, #f0ece0 100%); border: 1px solid rgba(139, 105, 20, 0.2); border-left: 4px solid var(--color-accent); }
        .student-header__avatar { width: 56px; height: 56px; border-radius: 50%; background: var(--color-accent); color: #fff; display: flex; align-items: center; justify-content: center; font-family: var(--font-serif); font-size: 1.5rem; font-weight: 600; flex-shrink: 0; }
        .student-header__info { flex: 1; }
        .student-header__name { font-family: var(--font-serif); font-size: 1.4rem; font-weight: 500; line-height: 1.1; }
        .student-header__meta { font-size: 0.82rem; color: var(--color-tertiary); margin-top: 4px; }
        .student-header__topic { font-size: 0.9rem; color: var(--color-accent-dark); margin-top: 6px; font-weight: 500; }

        .student-phases { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        @media (max-width: 900px) { .student-phases { grid-template-columns: repeat(2, 1fr); } }
        .student-phase-step { position: relative; padding: 14px 14px 14px 46px; background: #fff; border: 1px solid rgba(26, 26, 26, 0.12); border-left: 4px solid rgba(26, 26, 26, 0.2); display: flex; flex-direction: column; gap: 6px; min-height: 96px; }
        .student-phase-step__num { position: absolute; left: 10px; top: 14px; width: 28px; height: 28px; border-radius: 50%; background: rgba(26, 26, 26, 0.06); color: var(--color-tertiary); display: flex; align-items: center; justify-content: center; font-family: var(--font-serif); font-weight: 600; font-size: 0.95rem; }
        .student-phase-step__name { font-weight: 500; font-size: 0.9rem; line-height: 1.25; }
        .student-phase-step__status { margin-top: auto; font-size: 0.72rem; font-weight: 500; padding: 2px 8px; align-self: flex-start; border: 1px solid; border-radius: 2px; }
        .student-phase-step--done { border-left-color: #16a34a; }
        .student-phase-step--done .student-phase-step__num { background: rgba(22, 163, 74, 0.15); color: #15803d; }
        .student-phase-step--done .student-phase-step__status { border-color: #16a34a; color: #15803d; }
        .student-phase-step--current { border-left-color: #2563eb; box-shadow: 0 0 0 1px #2563eb inset, 0 4px 12px rgba(37, 99, 235, 0.08); }
        .student-phase-step--current .student-phase-step__num { background: #2563eb; color: #fff; }
        .student-phase-step--current .student-phase-step__status { border-color: #2563eb; color: #1d4ed8; }
        .student-phase-step--pending { opacity: 0.7; }
        .student-phase-step--pending .student-phase-step__status { border-color: rgba(26, 26, 26, 0.2); color: var(--color-tertiary); }

        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-sm); margin-bottom: var(--space-md); }
        .summary-card { position: relative; padding: 16px 18px; background: linear-gradient(135deg, #fafaf7 0%, #f0ece0 100%); border: 1px solid rgba(139, 105, 20, 0.15); border-left: 3px solid var(--color-accent); overflow: hidden; }
        .summary-card--green { border-left-color: #16a34a; }
        .summary-card--blue  { border-left-color: #2563eb; }
        .summary-card--orange{ border-left-color: #d97706; }
        .summary-card--red   { border-left-color: #dc2626; }
        .summary-card__label { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-tertiary); margin-bottom: 8px; }
        .summary-card__value { font-family: var(--font-serif); font-size: 2rem; font-weight: 600; color: var(--color-primary); line-height: 1; margin-bottom: 6px; }
        .summary-card__hint { font-size: 0.72rem; color: var(--color-accent-dark); }

        .grade-dist { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: var(--space-md); }
        .grade-dist__item { padding: 14px 12px; border: 1px solid rgba(26, 26, 26, 0.1); background: var(--color-white); text-align: center; transition: transform 0.15s, box-shadow 0.15s; }
        .grade-dist__item:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .grade-dist__mark { font-family: var(--font-serif); font-size: 2.2rem; font-weight: 600; line-height: 1; }
        .grade-dist__mark--5 { color: #16a34a; }
        .grade-dist__mark--4 { color: #2563eb; }
        .grade-dist__mark--3 { color: #d97706; }
        .grade-dist__mark--2 { color: #dc2626; }
        .grade-dist__count { font-size: 0.85rem; color: var(--color-secondary); margin-top: 6px; }
        .grade-dist__bar { height: 6px; background: rgba(26, 26, 26, 0.08); margin-top: 8px; border-radius: 2px; overflow: hidden; }
        .grade-dist__bar-fill { height: 100%; transition: width 0.4s ease-out; }
        .grade-dist__bar-fill--5 { background: #16a34a; }
        .grade-dist__bar-fill--4 { background: #2563eb; }
        .grade-dist__bar-fill--3 { background: #d97706; }
        .grade-dist__bar-fill--2 { background: #dc2626; }

        table.student-summary { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.82rem; background: var(--color-white); border: 1px solid rgba(26, 26, 26, 0.1); }
        table.student-summary th, table.student-summary td { border-bottom: 1px solid rgba(26, 26, 26, 0.08); padding: 10px 12px; text-align: left; vertical-align: middle; }
        table.student-summary thead th { background-color: var(--color-primary); color: #fff; font-weight: 500; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; position: sticky; top: 0; z-index: 2; }
        table.student-summary tbody tr:hover { background-color: rgba(139, 105, 20, 0.04); }
        table.student-summary td.num { text-align: center; font-variant-numeric: tabular-nums; }
        table.student-summary td.avg { font-family: var(--font-serif); font-size: 1.05rem; font-weight: 600; text-align: center; }
        table.student-summary td.avg--5 { color: #16a34a; }
        table.student-summary td.avg--4 { color: #2563eb; }
        table.student-summary td.avg--3 { color: #d97706; }
        table.student-summary td.avg--2 { color: #dc2626; }
        table.student-summary td.avg--none { color: var(--color-tertiary); font-size: 0.9rem; }

        .summary-bar { display: inline-block; width: 80px; height: 6px; background: rgba(26, 26, 26, 0.08); border-radius: 2px; overflow: hidden; vertical-align: middle; margin-right: 8px; }
        .summary-bar__fill { height: 100%; background: #16a34a; }

        .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: var(--space-sm); margin-bottom: var(--space-md); }
        .kpi-card { position: relative; padding: 16px 18px; background: linear-gradient(135deg, #fafaf7 0%, #f0ece0 100%); border: 1px solid rgba(139, 105, 20, 0.15); border-left: 3px solid var(--color-accent); overflow: hidden; transition: transform 0.2s ease-out, box-shadow 0.2s ease-out; }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(139, 105, 20, 0.12); }
        .kpi-card::after { content: ''; position: absolute; right: -30px; bottom: -30px; width: 90px; height: 90px; border-radius: 50%; background: radial-gradient(circle, rgba(139, 105, 20, 0.08) 0%, transparent 70%); }
        .kpi-card__label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-tertiary); margin-bottom: 8px; position: relative; z-index: 1; }
        .kpi-card__value { font-family: var(--font-serif); font-size: 2.2rem; font-weight: 600; color: var(--color-primary); line-height: 1; margin-bottom: 6px; position: relative; z-index: 1; }
        .kpi-card__hint { font-size: 0.72rem; color: var(--color-accent-dark); position: relative; z-index: 1; }

        .dash-toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; padding: 10px 0; margin-bottom: var(--space-md); border-bottom: 1px solid rgba(26, 26, 26, 0.08); }
        .dash-toolbar__group { display: flex; align-items: center; gap: 4px; }
        .dash-toolbar__label { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-tertiary); margin-right: 4px; }
        .dash-toolbar__btn { padding: 5px 11px; font-size: 0.75rem; font-weight: 500; border: 1px solid rgba(26, 26, 26, 0.15); background: #fff; color: var(--color-secondary); cursor: pointer; font-family: var(--font-sans); transition: 0.15s ease-out; }
        .dash-toolbar__btn:hover { background: rgba(139, 105, 20, 0.08); color: var(--color-primary); }
        .dash-toolbar__btn.is-active { background: var(--color-accent); color: #fff; border-color: var(--color-accent); }
        .filter-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; background: rgba(139, 105, 20, 0.14); color: var(--color-accent-dark); border-radius: 14px; font-size: 0.72rem; font-weight: 500; cursor: pointer; margin-left: auto; transition: 0.15s; }
        .filter-chip:hover { background: rgba(139, 105, 20, 0.24); }
        .filter-chip__x { font-size: 0.95rem; line-height: 1; opacity: 0.7; }

        .dash-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); grid-auto-rows: minmax(260px, auto); gap: var(--space-md); flex: 1; }
        .dash-card { padding: var(--space-md); border: 1px solid rgba(26, 26, 26, 0.1); background: var(--color-white); display: flex; flex-direction: column; min-height: 260px; min-width: 0; }
        .dash-card--wide { grid-column: span 2; }
        .dash-card--map { grid-column: span 3; min-height: 500px; }
        .dash-card__title { font-family: var(--font-serif); font-size: 1.15rem; margin-bottom: var(--space-sm); display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
        .dash-card__hint { font-family: var(--font-sans); font-size: 0.72rem; color: var(--color-tertiary); font-weight: 400; }
        .dash-card__body { flex: 1; min-height: 200px; position: relative; }
        .dash-card canvas { width: 100% !important; height: 100% !important; cursor: pointer; display: block; }

        .russia-map-wrap { position: relative; flex: 1; min-height: 420px; background: linear-gradient(180deg, #fbfaf6 0%, #eee9dc 100%); border: 1px solid rgba(26, 26, 26, 0.08); overflow: hidden; }
        .russia-map { width: 100%; height: 100%; display: block; }
        .russia-map .map-grid { stroke: rgba(26, 26, 26, 0.06); stroke-width: 0.6; stroke-dasharray: 2 3; }
        .russia-map .map-grid-label { font-family: var(--font-sans); font-size: 8px; fill: rgba(26, 26, 26, 0.25); letter-spacing: 0.05em; }
        .russia-outline { fill: url(#map-fill); stroke: rgba(139, 105, 20, 0.45); stroke-width: 1.8; stroke-linejoin: round; stroke-linecap: round; filter: url(#map-glow); }
        .uni-point { cursor: pointer; transition: opacity 0.25s ease-out; }
        .uni-point.is-dimmed { opacity: 0.15; }
        .uni-point__dot { transition: r 0.25s ease-out, filter 0.25s, fill 0.3s; transform-origin: center; }
        .uni-point__halo { transition: opacity 0.3s, r 0.3s, fill 0.3s; }
        .uni-point__pulse { fill: none; stroke-width: 2; opacity: 0; transition: opacity 0.2s, stroke 0.3s; pointer-events: none; }
        .uni-point__label { font-family: var(--font-sans); font-size: 11px; font-weight: 600; fill: var(--color-secondary); paint-order: stroke; stroke: #fff; stroke-width: 3px; stroke-linejoin: round; pointer-events: none; }
        .uni-point.is-dimmed .uni-point__label { opacity: 0.2; }
        .uni-point:hover .uni-point__dot { r: 15; filter: brightness(1.1); }
        .uni-point:hover .uni-point__halo { opacity: 0.28; r: 26; }
        .uni-point.is-selected .uni-point__dot { r: 16; stroke-width: 3; }
        .uni-point.is-selected .uni-point__halo { opacity: 0.35; r: 30; }
        .uni-point.is-selected .uni-point__pulse { opacity: 0.55; animation: pulse-marker 2s ease-out infinite; }
        @keyframes pulse-marker { 0% { r: 16; opacity: 0.55; } 100% { r: 34; opacity: 0; } }

        .map-info { position: absolute; top: 16px; left: 16px; min-width: 200px; padding: 10px 14px; background: rgba(255, 255, 255, 0.97); border-left: 3px solid var(--color-accent); box-shadow: 0 4px 14px rgba(0, 0, 0, 0.1); font-family: var(--font-sans); font-size: 0.8rem; color: var(--color-primary); opacity: 0; transform: translateY(-6px); transition: opacity 0.2s, transform 0.2s; pointer-events: none; z-index: 10; }
        .map-info.is-visible { opacity: 1; transform: translateY(0); }
        .map-info__phase { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 3px; }
        .map-info__name { font-family: var(--font-serif); font-size: 1.05rem; font-weight: 500; line-height: 1.2; margin-bottom: 4px; }
        .map-info__projects { font-size: 0.72rem; color: var(--color-tertiary); }

        .map-legend { display: flex; flex-wrap: wrap; gap: 0.5rem var(--space-md); margin-top: var(--space-sm); padding-top: var(--space-sm); border-top: 1px solid rgba(26, 26, 26, 0.08); }
        .map-legend__item { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.75rem; color: var(--color-secondary); cursor: pointer; padding: 2px 6px; border-radius: 10px; }
        .map-legend__item:hover { background: rgba(139, 105, 20, 0.1); }
        .map-legend__item.is-active { background: rgba(139, 105, 20, 0.2); font-weight: 600; }
        .map-legend__dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; box-shadow: 0 0 0 1px rgba(0,0,0,0.15); }

        .parent-child-picker { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 14px; background: var(--color-light); border: 1px solid rgba(26, 26, 26, 0.08); margin-bottom: var(--space-md); }
        .parent-child-picker__label { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-tertiary); }
        .parent-child-picker select { padding: 6px 10px; border: 1px solid rgba(26, 26, 26, 0.15); background: #fff; font-family: var(--font-sans); font-size: 0.85rem; }

        .bad-marks-banner { display: flex; align-items: center; gap: 10px; padding: 12px 16px; margin-bottom: var(--space-sm); background: #fef2f2; border-left: 4px solid #dc2626; color: #7f1d1d; font-size: 0.88rem; flex-wrap: wrap; }
        .bad-marks-banner__icon { font-size: 1.3rem; flex-shrink: 0; }
        .bad-marks-banner__body { flex: 1; }
        .bad-marks-banner__list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
        .bad-marks-banner__pill { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; background: #fff; border: 1px solid rgba(220, 38, 38, 0.3); border-radius: 12px; font-size: 0.75rem; font-weight: 500; color: #7f1d1d; }
        .bad-marks-banner__pill strong { color: #dc2626; font-weight: 700; }

        .parent-visits-chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; font-weight: 600; background: rgba(139, 105, 20, 0.12); color: var(--color-accent-dark); white-space: nowrap; }
        .parent-visits-chip--active { background: rgba(22, 163, 74, 0.15); color: #15803d; }
        .parent-visits-chip--low    { background: rgba(217, 119, 6, 0.15); color: #b45309; }
        .parent-visits-chip--cold   { background: rgba(220, 38, 38, 0.1);  color: #b91c1c; }
        .parent-visits-chip--trend-up   { color: #16a34a; }
        .parent-visits-chip--trend-down { color: #dc2626; }

        .journal-parent-badge { display: inline-flex; align-items: center; justify-content: center; width: 8px; height: 8px; border-radius: 50%; background: #16a34a; margin-left: 6px; vertical-align: middle; box-shadow: 0 0 0 2px rgba(22, 163, 74, 0.15); }
        .journal-parent-badge--low  { background: #d97706; box-shadow: 0 0 0 2px rgba(217, 119, 6, 0.15); }
        .journal-parent-badge--cold { background: #dc2626; box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.15); }

        /* ===== Cookie-плашка ===== */
        .cookie-banner {
            position: fixed;
            left: 16px;
            right: 16px;
            bottom: 16px;
            z-index: 9999;
            background: var(--color-white);
            border: 1px solid rgba(26, 26, 26, 0.15);
            border-left: 4px solid var(--color-accent);
            box-shadow:
                0 8px 24px rgba(0, 0, 0, 0.12),
                0 20px 48px rgba(0, 0, 0, 0.08);
            padding: 14px 18px;
            display: none;
            gap: var(--space-md);
            align-items: flex-start;
            font-family: var(--font-sans);
            font-size: 0.85rem;
            color: var(--color-primary);
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            transition: opacity 0.25s ease-out, transform 0.25s ease-out;
        }

        .cookie-banner.is-visible { display: flex; }

        .cookie-banner__icon { font-size: 1.4rem; flex-shrink: 0; line-height: 1; }

        .cookie-banner__body { flex: 1; min-width: 0; }

        .cookie-banner__title {
            font-family: var(--font-serif);
            font-size: 1.05rem;
            font-weight: 500;
            margin-bottom: 4px;
            line-height: 1.2;
        }

        .cookie-banner__text {
            color: var(--color-secondary);
            line-height: 1.5;
            font-size: 0.82rem;
        }

        .cookie-banner__text strong {
            color: var(--color-accent-dark);
            font-weight: 600;
        }

        .cookie-banner__actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
            align-self: center;
        }

        .cookie-banner__btn {
            padding: 8px 18px;
            font-size: 0.82rem;
            font-weight: 500;
            font-family: var(--font-sans);
            border: 1px solid var(--color-accent);
            background: var(--color-accent);
            color: var(--color-white);
            cursor: pointer;
            transition: var(--transition-base);
            white-space: nowrap;
        }
        .cookie-banner__btn:hover {
            background: var(--color-accent-light);
            border-color: var(--color-accent-light);
        }

        .cookie-banner__btn--ghost {
            background: transparent;
            color: var(--color-secondary);
            border-color: rgba(26, 26, 26, 0.2);
        }
        .cookie-banner__btn--ghost:hover {
            background: rgba(0, 0, 0, 0.04);
            color: var(--color-primary);
            border-color: rgba(26, 26, 26, 0.35);
        }

        .no-reco-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 16px;
            font-size: 0.72rem;
            color: var(--color-tertiary);
            border-top: 1px solid rgba(26, 26, 26, 0.06);
            background: var(--color-light);
        }
        .no-reco-note__icon {
            font-size: 0.85rem;
            color: var(--color-accent-dark);
        }

        @media (max-width: 720px) {
            .cookie-banner {
                flex-direction: column;
                align-items: stretch;
                padding: 12px 14px;
            }
            .cookie-banner__actions {
                align-self: stretch;
                justify-content: flex-end;
            }
        }

        @media (max-width: 1100px) { .dash-grid { grid-template-columns: 1fr 1fr; } .dash-card--wide { grid-column: span 2; } .dash-card--map { grid-column: span 2; } }
        @media (max-width: 720px) { .dash-grid { grid-template-columns: 1fr; } .dash-card--wide, .dash-card--map { grid-column: span 1; } }
        @media (max-width: 900px) { .mainnav { overflow-x: auto; } }
    </style>
</head>
<body class="<?= $currentUser === null ? 'auth-body' : ('role-' . $viewRole) ?>">
<?php if ($currentUser === null): ?>
    <div class="auth-card">
        <h1 class="auth-card__brand">Кладезь</h1>
        <p class="auth-card__subtitle">CRM «ИТ Школа РТК» — вход в систему</p>
        <?php if ($error !== ''): ?><div class="auth-error"><?= e($error) ?></div><?php endif; ?>
        <form class="auth-form" method="post" action="index.php">
            <label for="login">Логин</label>
            <input type="text" id="login" name="login" autocomplete="username" autofocus value="<?= e((string) ($_POST['login'] ?? '')) ?>">
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
                    <div class="role-switch__row">
                        <a class="role-switch__btn <?= $viewRole === 'supervisor' ? 'active' : '' ?>" role="tab" aria-selected="<?= $viewRole === 'supervisor' ? 'true' : 'false' ?>" href="?role=supervisor">
                            <span class="role-switch__emoji" aria-hidden="true">🧭</span>
                            <span class="role-switch__label">Руководитель</span>
                        </a>
                        <a class="role-switch__btn <?= $viewRole === 'manager' ? 'active' : '' ?>" role="tab" aria-selected="<?= $viewRole === 'manager' ? 'true' : 'false' ?>" href="?role=manager<?= $viewUniId ? '&uni_id=' . (int) $viewUniId : '' ?>">
                            <span class="role-switch__emoji" aria-hidden="true">📋</span>
                            <span class="role-switch__label"><?= e($managerLabel) ?> · Менеджер</span>
                        </a>
                    </div>
                    <div class="role-switch__row">
                        <a class="role-switch__btn <?= $viewRole === 'university' ? 'active' : '' ?>" role="tab" aria-selected="<?= $viewRole === 'university' ? 'true' : 'false' ?>" href="?role=university<?= $viewUniId ? '&uni_id=' . (int) $viewUniId : '' ?>">
                            <span class="role-switch__emoji" aria-hidden="true">🎓</span>
                            <span class="role-switch__label">Представитель Вуза</span>
                        </a>
                        <a class="role-switch__btn <?= $viewRole === 'student' ? 'active' : '' ?>" role="tab" aria-selected="<?= $viewRole === 'student' ? 'true' : 'false' ?>" href="?role=student<?= $viewStudentId ? '&student_id=' . (int) $viewStudentId : '' ?>">
                            <span class="role-switch__emoji" aria-hidden="true">🎒</span>
                            <span class="role-switch__label">Студент</span>
                        </a>
                        <a class="role-switch__btn <?= $viewRole === 'parent' ? 'active' : '' ?>" role="tab" aria-selected="<?= $viewRole === 'parent' ? 'true' : 'false' ?>" href="?role=parent<?= $viewParentChildId ? '&child_id=' . (int) $viewParentChildId : '' ?>">
                            <span class="role-switch__emoji" aria-hidden="true">👪</span>
                            <span class="role-switch__label">Родитель</span>
                        </a>
                    </div>
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
                                <?php if ($currentPhaseNum === 11): ?>
                                    <span class="panel__hint panel__hint--phase">
                                        👨‍🎓 Проект в фазе «Ведение занятий» — ниже статистика студентов и сводка по журналу
                                    </span>
                                <?php endif; ?>
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
                                        <span class="wstep__bar" style="background-color: <?= e($item['color']) ?>33">
                                            <span class="wstep__bar-fill" style="width: <?= (int) $item['fill'] ?>%; background-color: <?= e($item['color']) ?>"></span>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>

                    <?php if ($currentPhaseNum === 11): ?>
                        <section class="panel panel--students">
                            <div class="panel__head">
                                <div>
                                    <h2 class="panel__title">Статистика студентов</h2>
                                    <p class="panel__subtitle">
                                        Проектная работа и сводка по классно-урочной системе
                                        · курс «<?= e($currentProject['title']) ?>»
                                        · студентов: <?= count($mockStudents) ?>
                                        · проектов: <?= count($mockProjects) ?>
                                    </p>
                                </div>
                            </div>

                            <div class="tabs" role="tablist">
                                <button class="tab is-active" data-tab="mgr-project" role="tab" aria-selected="true">📋 Проекты</button>
                                <button class="tab" data-tab="mgr-journal-summary" role="tab" aria-selected="false">📊 Сводка по журналу</button>
                            </div>

                            <div class="tab-content is-active" data-tab-content="mgr-project" role="tabpanel">
                                <p class="panel__subtitle" style="margin-bottom: var(--space-sm);">
                                    Строки — студенческие проекты. У одного студента может быть несколько проектов,
                                    несколько студентов могут работать над одним проектом.
                                </p>

                                <div class="phase-timeline-wrap">
                                    <table class="students-timeline">
                                        <thead>
                                            <tr>
                                                <th style="width: 250px;">Проект</th>
                                                <th style="width: 130px;">Тип</th>
                                                <th style="width: 240px;">Участники</th>
                                                <th>История проектных фаз</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($mockProjects as $proj): ?>
                                                <?php
                                                $curPhase = project_current_phase($proj);
                                                $curPhaseName = $studentPhaseByNum[$curPhase]['name'] ?? '—';
                                                $type = $projectTypes[$proj['type']] ?? ['label' => $proj['type'], 'color' => '#8b6914', 'icon' => '📌'];
                                                ?>
                                                <tr>
                                                    <td class="project-cell-title" title="<?= e($proj['title']) ?>">
                                                        <?= e($proj['title']) ?>
                                                        <span class="student-sub">
                                                            Текущая фаза: <?= e($curPhaseName) ?> (<?= (int) $curPhase ?>/4)
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="project-type-chip" style="--type-color: <?= e($type['color']) ?>;">
                                                            <?= $type['icon'] ?> <?= e($type['label']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="participant-list">
                                                            <?php foreach ($proj['students'] as $sid): ?>
                                                                <?php
                                                                $st = $studentById[(int) $sid] ?? null;
                                                                if (!$st) continue;
                                                                $pv = $parentVisitsMock[(int) $sid] ?? null;
                                                                $chipCls = '';
                                                                if ($pv !== null) {
                                                                    if ($pv['count'] >= 10) $chipCls = 'parent-visits-chip--active';
                                                                    elseif ($pv['count'] >= 5) $chipCls = 'parent-visits-chip--low';
                                                                    else $chipCls = 'parent-visits-chip--cold';
                                                                }
                                                                ?>
                                                                <span class="participant-row" title="<?= e($st['name']) ?> · <?= e($st['group']) ?>">
                                                                    <span class="participant-row__name"><?= e($st['name']) ?></span>
                                                                    <?php if ($pv !== null): ?>
                                                                        <span class="parent-visits-chip <?= $chipCls ?>" title="Активность родителя: <?= (int) $pv['count'] ?> визитов, последний <?= e($pv['last']) ?>">
                                                                            👪 <?= (int) $pv['count'] ?>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if (empty($proj['history'])): ?>
                                                            <span class="phase-timeline-empty">Нет записей</span>
                                                        <?php else: ?>
                                                            <div class="phase-timeline">
                                                                <?php $prevNum = 0; ?>
                                                                <?php foreach ($proj['history'] as $idx => $h): ?>
                                                                    <?php
                                                                    $sp = $studentPhaseByNum[(int) $h['num']] ?? null;
                                                                    if (!$sp) continue;
                                                                    $isReturn = $prevNum > 0 && (int) $h['num'] < $prevNum;
                                                                    ?>
                                                                    <?php if ($idx > 0): ?>
                                                                        <span class="phase-arrow <?= $isReturn ? 'phase-arrow--return' : '' ?>" title="<?= $isReturn ? 'Возврат' : 'Переход' ?>"><?= $isReturn ? '↩' : '→' ?></span>
                                                                    <?php endif; ?>
                                                                    <span class="phase-chip" style="--phase-color: <?= e($sp['color']) ?>;" title="<?= e($sp['name']) ?> · <?= e($h['date']) ?> · <?= e($h['note']) ?>">
                                                                        <span class="phase-chip__num"><?= (int) $sp['num'] ?></span><?= e($sp['name']) ?><span class="phase-chip__date"><?= e($h['date']) ?></span>
                                                                    </span>
                                                                    <?php $prevNum = (int) $h['num']; ?>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <section class="legend" style="margin-top: var(--space-sm);">
                                    <h3 class="legend__title">Легенда</h3>
                                    <div class="legend__items">
                                        <?php foreach ($projectTypes as $t): ?>
                                            <span class="legend__item">
                                                <span class="project-type-chip" style="--type-color: <?= e($t['color']) ?>; margin:0;">
                                                    <?= $t['icon'] ?> <?= e($t['label']) ?>
                                                </span>
                                            </span>
                                        <?php endforeach; ?>
                                        <span class="legend__item"><span class="legend__swatch" style="background:transparent;border:none;color:#b45309;font-weight:700;font-size:1.1rem;">↩</span>Возврат</span>
                                        <span class="legend__item"><span class="legend__swatch" style="background:transparent;border:none;color:var(--color-tertiary);font-weight:700;font-size:1.1rem;">→</span>Переход</span>
                                        <span class="legend__item"><span class="parent-visits-chip parent-visits-chip--active" style="margin:0;">👪 N</span>Активность родителя</span>
                                    </div>
                                </section>
                            </div>

                            <div class="tab-content" data-tab-content="mgr-journal-summary" role="tabpanel">
                                <p class="panel__subtitle" style="margin-bottom: var(--space-md);">
                                    Агрегированные показатели классно-урочной работы по курсу.
                                    Детальный журнал доступен преподавателю; менеджеру — только сводка.
                                </p>

                                <div class="summary-grid">
                                    <div class="summary-card summary-card--blue">
                                        <div class="summary-card__label">Средний балл по курсу</div>
                                        <div class="summary-card__value"><?= $avgGrade !== null ? e((string) $avgGrade) : '—' ?></div>
                                        <div class="summary-card__hint">по <?= (int) $journalSummary['gradeCount'] ?> выставленным оценкам</div>
                                    </div>
                                    <div class="summary-card summary-card--green">
                                        <div class="summary-card__label">Посещаемость</div>
                                        <div class="summary-card__value"><?= $attendance !== null ? e((string) $attendance) . '%' : '—' ?></div>
                                        <div class="summary-card__hint"><?= (int) $totalPresent ?> присутствий · <?= (int) $totalAbsent ?> пропусков</div>
                                    </div>
                                    <div class="summary-card summary-card--red">
                                        <div class="summary-card__label">Пропуски занятий</div>
                                        <div class="summary-card__value"><?= (int) $totalAbsent ?></div>
                                        <div class="summary-card__hint">всего за период</div>
                                    </div>
                                    <div class="summary-card summary-card--orange">
                                        <div class="summary-card__label">Проведено занятий</div>
                                        <div class="summary-card__value"><?= count($journalDates) ?></div>
                                        <div class="summary-card__hint">по расписанию</div>
                                    </div>
                                </div>

                                <h3 class="panel__title" style="font-size:1.05rem; margin-bottom: var(--space-sm);">Распределение оценок по курсу</h3>
                                <?php
                                $distMax = max(1, max(array_values($journalSummary['dist'])));
                                $distTotal = array_sum($journalSummary['dist']);
                                ?>
                                <div class="grade-dist">
                                    <?php foreach ([5, 4, 3, 2] as $g): ?>
                                        <?php $c = (int) $journalSummary['dist'][$g]; ?>
                                        <div class="grade-dist__item">
                                            <div class="grade-dist__mark grade-dist__mark--<?= $g ?>"><?= $g ?></div>
                                            <div class="grade-dist__count">
                                                <?= $c ?> шт.
                                                <?php if ($distTotal > 0): ?> · <?= round($c / $distTotal * 100, 0) ?>%<?php endif; ?>
                                            </div>
                                            <div class="grade-dist__bar">
                                                <div class="grade-dist__bar-fill grade-dist__bar-fill--<?= $g ?>"
                                                     style="width: <?= (int) round($c / $distMax * 100) ?>%"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <h3 class="panel__title" style="font-size:1.05rem; margin: var(--space-md) 0 var(--space-sm);">Разбивка по студентам</h3>
                                <div style="overflow:auto; max-height: 460px;">
                                    <table class="student-summary">
                                        <thead>
                                            <tr>
                                                <th>Студент</th>
                                                <th style="text-align:center;">Средний балл</th>
                                                <th style="text-align:center;">5 / 4 / 3 / 2</th>
                                                <th style="text-align:center;">Посещаемость</th>
                                                <th style="text-align:center;">Пропуски</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($perStudentSummary as $sid => $row): ?>
                                                <?php
                                                $avg = $row['avg'];
                                                $avgCls = 'avg--none';
                                                if ($avg !== null) {
                                                    if ($avg >= 4.5) $avgCls = 'avg--5';
                                                    elseif ($avg >= 3.5) $avgCls = 'avg--4';
                                                    elseif ($avg >= 2.5) $avgCls = 'avg--3';
                                                    else $avgCls = 'avg--2';
                                                }
                                                $att = $row['attendance'];
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= e($row['student']['name']) ?></strong>
                                                        <span class="student-sub" style="display:block; font-size:0.7rem; color:var(--color-tertiary);"><?= e($row['student']['group']) ?></span>
                                                    </td>
                                                    <td class="avg <?= $avgCls ?>">
                                                        <?= $avg !== null ? e(number_format($avg, 2)) : '—' ?>
                                                    </td>
                                                    <td class="num">
                                                        <?php foreach ([5, 4, 3, 2] as $g): ?>
                                                            <span class="journal-mark journal-mark--grade-<?= $g ?>" style="min-width:20px; height:20px; font-size:0.75rem;"><?= (int) $row['dist'][$g] ?></span>
                                                        <?php endforeach; ?>
                                                    </td>
                                                    <td class="num">
                                                        <?php if ($att !== null): ?>
                                                            <span class="summary-bar"><span class="summary-bar__fill" style="width:<?= (int) $att ?>%"></span></span>
                                                            <?= e((string) $att) ?>%
                                                        <?php else: ?>—<?php endif; ?>
                                                    </td>
                                                    <td class="num" style="color: <?= (int) $row['absent'] > 0 ? '#dc2626' : 'var(--color-tertiary)' ?>; font-weight: 600;">
                                                        <?= (int) $row['absent'] ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>

                    <section class="panel panel--matrix">
                        <div class="panel__head">
                            <div>
                                <h2 class="panel__title">Сетка проектов</h2>
                                <p class="panel__subtitle">
                                    Строки — ВУЗы, столбцы — ИТ-продукты. Клетки с взаимодействием — цветные листочки с номером фазы.
                                </p>
                                <span class="panel__hint">
                                    ◆ Перетащите листок — рядом появятся карманы «Предыдущая» и «Следующая» фаза. Фазы с 🔒 требуют подтверждения вуза.
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
                                                        <img class="uni__crest" src="<?= e($university['crest']) ?>" alt="Герб: <?= e($university['short']) ?>" onerror="this.outerHTML='&lt;span class=&quot;uni__initial&quot;&gt;<?= e($university['initial']) ?>&lt;/span&gt;'">
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
                                                if ($phase !== null && (int) $phase['num'] > 0) $cellClasses[] = 'phase';
                                                else $cellClasses[] = 'empty';
                                                if ($interactionId !== null) $cellClasses[] = 'clickable';
                                                if ($isCurrent) $cellClasses[] = 'is-current-project';
                                                if ($isPending) $cellClasses[] = 'is-pending';
                                                $cellClass = implode(' ', $cellClasses);
                                                $cellTitle = $university['name'] . ' · ' . $product['name'];
                                                if ($phase !== null && (int) $phase['num'] > 0) $cellTitle .= ' → ' . $phase['name'];
                                                ?>
                                                <?php if ($interactionId !== null && $phase !== null && (int) $phase['num'] > 0): ?>
                                                    <td class="<?= e($cellClass) ?>" title="<?= e($cellTitle) ?>"
                                                        data-project="<?= (int) $interactionId ?>"
                                                        data-phase-id="<?= (int) $phase['id'] ?>"
                                                        data-phase-num="<?= (int) $phase['num'] ?>"
                                                        draggable="true" tabindex="0" role="link" aria-label="<?= e($cellTitle) ?>">
                                                        <span class="sticker-cell" style="--phase-color: <?= e($phase['color']) ?>;"><?= (int) $phase['num'] ?></span>
                                                    </td>
                                                <?php elseif ($interactionId !== null): ?>
                                                    <td class="<?= e($cellClass) ?>" title="<?= e($cellTitle) ?>" data-project="<?= (int) $interactionId ?>" tabindex="0" role="link">—</td>
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
                            <span class="notification__icon"><?= $universityNotification['type'] === 'pending' ? '⏳' : 'ℹ' ?></span>
                            <div class="notification__body"><?= e($universityNotification['message']) ?></div>
                            <?php if ($universityNotification['type'] === 'pending' && $pendingChange !== null): ?>
                                <div class="notification__actions">
                                    <form method="post" action="index.php?action=confirm_phase" style="display:inline;"><button type="submit" class="notification__btn notification__btn--primary">Подтвердить</button></form>
                                    <form method="post" action="index.php?action=reject_phase" style="display:inline;"><button type="submit" class="notification__btn notification__btn--danger">Отклонить</button></form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <section class="panel panel--workflow">
                        <div class="panel__head">
                            <div>
                                <h2 class="panel__title">Рабочий процесс вуза</h2>
                                <p class="panel__subtitle">Все взаимодействия: <strong><?= e($selectedUni['name'] ?? '—') ?></strong> · проектов: <?= count($uniProjects) ?></p>
                            </div>
                            <label class="project-select">
                                Вуз:
                                <select onchange="location.href = 'index.php?role=university&uni_id=' + this.value;">
                                    <?php foreach ($universities as $u): ?>
                                        <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $viewUniId ? 'selected' : '' ?>><?= e($u['short']) ?> — <?= e($u['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>

                        <div class="workflow-scroll workflow-scroll--uni">
                            <?php if ($uniProjects === []): ?>
                                <div class="uni-empty">У выбранного вуза пока нет активных взаимодействий.</div>
                            <?php else: ?>
                                <?php foreach ($uniProjects as $proj): ?>
                                    <div class="uni-workflow">
                                        <div class="uni-workflow__head">
                                            <span class="uni-workflow__title" title="<?= e($proj['title']) ?>"><?= e($proj['short']) ?></span>
                                            <span class="uni-workflow__meta">
                                                <?php if ($proj['phase'] !== null): ?>
                                                    фаза <?= (int) $proj['phase']['num'] ?> «<?= e($proj['phase']['name']) ?>»
                                                    <?php if (!empty($proj['phase']['requires_confirmation'])): ?> 🔒<?php endif; ?>
                                                <?php else: ?>фаза не задана<?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="workflow-track workflow-track--compact">
                                            <?php foreach ($proj['workflow'] as $item): ?>
                                                <?php
                                                $stateClass = 'wstep--' . $item['state'];
                                                $sideClass = $item['side'] === 'both' ? ' wstep__side--both' : '';
                                                ?>
                                                <div class="wstep <?= $stateClass ?><?= $item['isCurrent'] ? ' current' : '' ?>" title="<?= e($item['name']) ?> · <?= e($item['sideLabel']) ?> · <?= e($item['stateLabel']) ?>">
                                                    <span class="wstep__num"><?= (int) $item['num'] ?></span>
                                                    <span class="wstep__side<?= $sideClass ?>"><?= e($item['side']) ?></span>
                                                    <?php if ($item['isCurrent']): ?><span class="wstep__flag">◆</span><?php endif; ?>
                                                    <span class="wstep__name"><?= e($item['name']) ?></span>
                                                    <span class="wstep__status"><?= e($item['stateLabel']) ?></span>
                                                    <span class="wstep__bar" style="background-color: <?= e($item['color']) ?>33"><span class="wstep__bar-fill" style="width: <?= (int) $item['fill'] ?>%; background-color: <?= e($item['color']) ?>"></span></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="panel panel--tabs">
                        <div class="tabs" role="tablist">
                            <button class="tab is-active" data-tab="project" role="tab" aria-selected="true">📋 Проекты</button>
                            <button class="tab" data-tab="journal" role="tab" aria-selected="false">📓 Журнал</button>
                        </div>

                        <div class="tab-content is-active" data-tab-content="project" role="tabpanel">
                            <p class="panel__subtitle" style="margin-bottom: var(--space-sm);">
                                Проекты студентов и их фазовые истории. Возможны возвраты к предыдущим фазам (↩).
                            </p>

                            <div class="phase-timeline-wrap">
                                <table class="students-timeline">
                                    <thead>
                                        <tr>
                                            <th style="width: 250px;">Проект</th>
                                            <th style="width: 130px;">Тип</th>
                                            <th style="width: 240px;">Участники</th>
                                            <th>История фаз</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mockProjects as $proj): ?>
                                            <?php
                                            $curPhase = project_current_phase($proj);
                                            $curPhaseName = $studentPhaseByNum[$curPhase]['name'] ?? '—';
                                            $type = $projectTypes[$proj['type']] ?? ['label' => $proj['type'], 'color' => '#8b6914', 'icon' => '📌'];
                                            ?>
                                            <tr>
                                                <td class="project-cell-title" title="<?= e($proj['title']) ?>">
                                                    <?= e($proj['title']) ?>
                                                    <span class="student-sub">
                                                        Текущая фаза: <?= e($curPhaseName) ?> (<?= (int) $curPhase ?>/4)
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="project-type-chip" style="--type-color: <?= e($type['color']) ?>;">
                                                        <?= $type['icon'] ?> <?= e($type['label']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="participant-list">
                                                        <?php foreach ($proj['students'] as $sid): ?>
                                                            <?php
                                                            $st = $studentById[(int) $sid] ?? null;
                                                            if (!$st) continue;
                                                            ?>
                                                            <span class="participant-row">
                                                                <span class="participant-row__name"><?= e($st['name']) ?></span>
                                                                <span style="color:var(--color-tertiary);font-size:0.7rem;"><?= e($st['group']) ?></span>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (empty($proj['history'])): ?>
                                                        <span class="phase-timeline-empty">Нет записей</span>
                                                    <?php else: ?>
                                                        <div class="phase-timeline">
                                                            <?php $prevNum = 0; ?>
                                                            <?php foreach ($proj['history'] as $idx => $h): ?>
                                                                <?php
                                                                $sp = $studentPhaseByNum[(int) $h['num']] ?? null;
                                                                if (!$sp) continue;
                                                                $isReturn = $prevNum > 0 && (int) $h['num'] < $prevNum;
                                                                ?>
                                                                <?php if ($idx > 0): ?>
                                                                    <span class="phase-arrow <?= $isReturn ? 'phase-arrow--return' : '' ?>"><?= $isReturn ? '↩' : '→' ?></span>
                                                                <?php endif; ?>
                                                                <span class="phase-chip" style="--phase-color: <?= e($sp['color']) ?>;" title="<?= e($sp['name']) ?> · <?= e($h['date']) ?> · <?= e($h['note']) ?>">
                                                                    <span class="phase-chip__num"><?= (int) $sp['num'] ?></span><?= e($sp['name']) ?><span class="phase-chip__date"><?= e($h['date']) ?></span>
                                                                </span>
                                                                <?php $prevNum = (int) $h['num']; ?>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <section class="legend" style="margin-top: var(--space-sm);">
                                <h3 class="legend__title">Легенда</h3>
                                <div class="legend__items">
                                    <?php foreach ($projectTypes as $t): ?>
                                        <span class="legend__item">
                                            <span class="project-type-chip" style="--type-color: <?= e($t['color']) ?>; margin:0;">
                                                <?= $t['icon'] ?> <?= e($t['label']) ?>
                                            </span>
                                        </span>
                                    <?php endforeach; ?>
                                    <span class="legend__item"><span class="legend__swatch" style="background:transparent;border:none;color:#b45309;font-weight:700;font-size:1.1rem;">↩</span>Возврат</span>
                                    <span class="legend__item"><span class="legend__swatch" style="background:transparent;border:none;color:var(--color-tertiary);font-weight:700;font-size:1.1rem;">→</span>Переход</span>
                                </div>
                            </section>
                        </div>

                        <div class="tab-content" data-tab-content="journal" role="tabpanel">
                            <div style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap: var(--space-sm); margin-bottom: var(--space-sm);">
                                <div>
                                    <h2 class="panel__title" style="font-size:1.2rem;">Журнал занятий</h2>
                                    <p class="panel__subtitle">
                                        Клик по дате — тема урока и домашнее задание. Клик по ячейке — заметка преподавателя. Рядом с именем — активность родителя в CRM.
                                    </p>
                                </div>
                                <div class="legend" style="margin:0; padding:6px 12px; background:rgba(139,105,20,0.06);">
                                    <div class="legend__items" style="gap:0.5rem var(--space-md);">
                                        <span class="legend__item"><span class="journal-mark journal-mark--grade-5">5</span>отлично</span>
                                        <span class="legend__item"><span class="journal-mark journal-mark--grade-4">4</span>хорошо</span>
                                        <span class="legend__item"><span class="journal-mark journal-mark--grade-3">3</span>удовл.</span>
                                        <span class="legend__item"><span class="journal-mark journal-mark--grade-2">2</span>неуд.</span>
                                        <span class="legend__item"><span class="journal-mark journal-mark--absent">н</span>отсутствовал</span>
                                        <span class="legend__item"><span class="journal-note-dot" style="pointer-events:none;">✎</span>есть заметка</span>
                                    </div>
                                </div>
                            </div>

                            <div class="journal-wrap">
                                <table class="journal" id="journal-table">
                                    <thead>
                                        <tr>
                                            <th>Студент</th>
                                            <?php foreach ($journalDates as $i => $d): ?>
                                                <th data-date-index="<?= (int) $i ?>"><?= e($d) ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mockStudents as $st): ?>
                                            <?php
                                            $sid = (int) $st['id'];
                                            $pv = $parentVisitsMock[$sid] ?? null;
                                            $badgeCls = '';
                                            if ($pv !== null) {
                                                if ($pv['count'] >= 10) $badgeCls = '';
                                                elseif ($pv['count'] >= 5) $badgeCls = 'journal-parent-badge--low';
                                                else $badgeCls = 'journal-parent-badge--cold';
                                            }
                                            ?>
                                            <tr data-student="<?= $sid ?>">
                                                <td title="<?= e($st['name']) ?>">
                                                    <?= e($st['name']) ?>
                                                    <?php if ($pv !== null): ?>
                                                        <span class="journal-parent-badge <?= $badgeCls ?>" title="Родитель в CRM: <?= (int) $pv['count'] ?> визитов, последний <?= e($pv['last']) ?>"></span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php foreach ($journalDates as $i => $d): ?>
                                                    <?php
                                                    $mark = $journalMarks[$sid][$i] ?? '';
                                                    $markClass = '';
                                                    if ($mark === '5') $markClass = 'journal-mark--grade-5';
                                                    elseif ($mark === '4') $markClass = 'journal-mark--grade-4';
                                                    elseif ($mark === '3') $markClass = 'journal-mark--grade-3';
                                                    elseif ($mark === '2') $markClass = 'journal-mark--grade-2';
                                                    elseif ($mark === '·') $markClass = 'journal-mark--dot';
                                                    elseif ($mark === 'н') $markClass = 'journal-mark--absent';
                                                    $hasNote = isset($journalNotes[$sid][$i]);
                                                    $showMark = ($mark !== '' && $mark !== '·');
                                                    ?>
                                                    <td class="journal-cell <?= $hasNote ? 'has-note' : '' ?>"
                                                        data-date-index="<?= (int) $i ?>"
                                                        data-student-id="<?= $sid ?>">
                                                        <?php if ($showMark): ?>
                                                            <span class="journal-mark <?= $markClass ?>"><?= e($mark) ?></span>
                                                        <?php elseif ($hasNote): ?>
                                                            <span class="journal-note-dot" title="Есть заметка преподавателя">✎</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="journal-lesson journal-lesson--empty" id="journal-lesson">
                                <div class="journal-lesson__date" id="journal-lesson-date">Урок не выбран</div>
                                <div class="journal-lesson__title" id="journal-lesson-topic">Выберите дату в журнале, чтобы увидеть тему урока и домашнее задание.</div>
                                <div class="journal-lesson__homework" id="journal-lesson-homework"></div>
                            </div>

                            <script type="application/json" id="journal-lessons-json"><?= json_encode($journalLessons, JSON_UNESCAPED_UNICODE) ?></script>
                            <script type="application/json" id="journal-dates-json"><?= json_encode($journalDates, JSON_UNESCAPED_UNICODE) ?></script>
                            <script type="application/json" id="journal-notes-json"><?= json_encode($journalNotes, JSON_UNESCAPED_UNICODE) ?></script>
                            <script type="application/json" id="journal-students-json"><?= json_encode(array_map(function ($s) { return ['id' => $s['id'], 'name' => $s['name']]; }, $mockStudents), JSON_UNESCAPED_UNICODE) ?></script>
                            <script type="application/json" id="journal-marks-json"><?= json_encode($journalMarks, JSON_UNESCAPED_UNICODE) ?></script>
                        </div>
                    </section>
                </div>
            <?php elseif ($viewRole === 'student'): ?>
                <div class="workspace workspace--student">
                    <section class="panel" style="flex:1; min-height:0; display:flex; flex-direction:column;">
                        <?php if ($selectedStudent === null): ?>
                            <div class="uni-empty">Студент не найден.</div>
                        <?php else: ?>
                            <?php
                            $sid = (int) $selectedStudent['id'];
                            $studentMarks = $journalMarks[$sid] ?? [];
                            $studentNotes = $journalNotes[$sid] ?? [];
                            $myProjectIds = $projectsByStudent[$sid] ?? [];
                            ?>

                            <div class="student-header" style="margin-bottom: var(--space-md);">
                                <div class="student-header__avatar"><?= e(mb_substr($selectedStudent['name'], 0, 1, 'UTF-8')) ?></div>
                                <div class="student-header__info">
                                    <div class="student-header__name"><?= e($selectedStudent['name']) ?></div>
                                    <div class="student-header__meta">Группа <?= e($selectedStudent['group']) ?> · курс «<?= e($currentProject['title']) ?>» · проектов: <?= count($myProjectIds) ?></div>
                                </div>
                            </div>

                            <div class="tabs" role="tablist">
                                <button class="tab is-active" data-tab="student-project" role="tab" aria-selected="true">📋 Мои проекты</button>
                                <button class="tab" data-tab="student-journal" role="tab" aria-selected="false">📓 Журнал</button>
                            </div>

                            <div class="tab-content is-active" data-tab-content="student-project" role="tabpanel">
                                <?php if ($myProjectIds === []): ?>
                                    <div class="uni-empty">У вас пока нет проектов.</div>
                                <?php else: ?>
                                    <div class="projects-grid">
                                        <?php foreach ($myProjectIds as $pid): ?>
                                            <?php
                                            $proj = $projectById[$pid] ?? null;
                                            if (!$proj) continue;
                                            $curPhase = project_current_phase($proj);
                                            $type = $projectTypes[$proj['type']] ?? ['label' => $proj['type'], 'color' => '#8b6914', 'icon' => '📌'];
                                            ?>
                                            <article class="project-card" style="--type-color: <?= e($type['color']) ?>;">
                                                <header class="project-card__head">
                                                    <div>
                                                        <div class="project-card__title"><?= e($proj['title']) ?></div>
                                                        <div class="project-card__participants">
                                                            <strong>Участники:</strong>
                                                            <?php
                                                            $names = [];
                                                            foreach ($proj['students'] as $psid) {
                                                                $st = $studentById[(int) $psid] ?? null;
                                                                if (!$st) continue;
                                                                $names[] = e($st['name']);
                                                            }
                                                            echo implode(', ', $names);
                                                            ?>
                                                        </div>
                                                    </div>
                                                    <span class="project-type-chip" style="--type-color: <?= e($type['color']) ?>;">
                                                        <?= $type['icon'] ?> <?= e($type['label']) ?>
                                                    </span>
                                                </header>

                                                <div class="project-card__section-label">Текущая фаза</div>
                                                <div class="project-card__stepper">
                                                    <?php foreach ($studentPhases as $sp): ?>
                                                        <?php
                                                        $spNum = (int) $sp['num'];
                                                        $cls = 'step-mini--pending';
                                                        if ($spNum < $curPhase) $cls = 'step-mini--done';
                                                        elseif ($spNum === $curPhase) $cls = 'step-mini--current';
                                                        ?>
                                                        <div class="step-mini <?= $cls ?>">
                                                            <span class="step-mini__num"><?= $spNum ?></span>
                                                            <span class="step-mini__name"><?= e($sp['name']) ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>

                                                <?php if (!empty($proj['history'])): ?>
                                                    <div>
                                                        <div class="project-card__section-label">История фаз</div>
                                                        <div class="phase-timeline">
                                                            <?php $prevNum = 0; ?>
                                                            <?php foreach ($proj['history'] as $idx => $h): ?>
                                                                <?php
                                                                $sp = $studentPhaseByNum[(int) $h['num']] ?? null;
                                                                if (!$sp) continue;
                                                                $isReturn = $prevNum > 0 && (int) $h['num'] < $prevNum;
                                                                ?>
                                                                <?php if ($idx > 0): ?>
                                                                    <span class="phase-arrow <?= $isReturn ? 'phase-arrow--return' : '' ?>"><?= $isReturn ? '↩' : '→' ?></span>
                                                                <?php endif; ?>
                                                                <span class="phase-chip" style="--phase-color: <?= e($sp['color']) ?>;" title="<?= e($h['note']) ?>">
                                                                    <span class="phase-chip__num"><?= (int) $sp['num'] ?></span><?= e($sp['name']) ?><span class="phase-chip__date"><?= e($h['date']) ?></span>
                                                                </span>
                                                                <?php $prevNum = (int) $h['num']; ?>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($proj['git'])): ?>
                                                    <div>
                                                        <div class="project-card__section-label">Git-история проекта</div>
                                                        <div class="git-history">
                                                            <div class="git-history__header">main <span class="git-history__branch">⎇</span></div>
                                                            <div class="git-history__list">
                                                                <?php foreach ($proj['git'] as $commit): ?>
                                                                    <div class="git-history__item">
                                                                        <div class="git-history__graph"><span class="git-history__dot"></span></div>
                                                                        <span class="git-history__hash"><?= e($commit['hash']) ?></span>
                                                                        <div class="git-history__content">
                                                                            <span class="git-history__msg"><?= e($commit['message']) ?></span>
                                                                            <span class="git-history__meta"><?= e($commit['author']) ?> · <?= e($commit['date']) ?></span>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="tab-content" data-tab-content="student-journal" role="tabpanel">
                                <p class="panel__subtitle" style="margin-bottom: var(--space-sm);">
                                    Мои оценки и заметки преподавателя. Клик по дате — тема урока и домашнее задание.
                                </p>
                                <div class="journal-wrap">
                                    <table class="journal" id="journal-table-student">
                                        <thead>
                                            <tr>
                                                <th>Дата</th>
                                                <th>Отметка</th>
                                                <th>Заметка преподавателя</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($journalDates as $i => $d): ?>
                                                <?php
                                                $mark = $studentMarks[$i] ?? '';
                                                $markClass = '';
                                                if ($mark === '5') $markClass = 'journal-mark--grade-5';
                                                elseif ($mark === '4') $markClass = 'journal-mark--grade-4';
                                                elseif ($mark === '3') $markClass = 'journal-mark--grade-3';
                                                elseif ($mark === '2') $markClass = 'journal-mark--grade-2';
                                                elseif ($mark === '·') $markClass = 'journal-mark--dot';
                                                elseif ($mark === 'н') $markClass = 'journal-mark--absent';
                                                $noteText = $studentNotes[$i] ?? '';
                                                $showMark = ($mark !== '' && $mark !== '·');
                                                ?>
                                                <tr data-date-index="<?= (int) $i ?>">
                                                    <td style="font-weight: 600;"><?= e($d) ?></td>
                                                    <td style="text-align: center;">
                                                        <?php if ($showMark): ?>
                                                            <span class="journal-mark <?= $markClass ?>" style="font-size:1rem; min-width:32px; height:32px;"><?= e($mark) ?></span>
                                                        <?php else: ?>
                                                            <span style="color:rgba(26,26,26,0.15);">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align:left; font-size:0.82rem; color: var(--color-secondary);">
                                                        <?= $noteText !== '' ? e($noteText) : '<span style="color:rgba(26,26,26,0.35);font-style:italic;">—</span>' ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="journal-lesson journal-lesson--empty" id="student-journal-lesson">
                                    <div class="journal-lesson__date" id="student-journal-date">Урок не выбран</div>
                                    <div class="journal-lesson__title" id="student-journal-topic">Выберите дату в таблице, чтобы увидеть тему урока и домашнее задание.</div>
                                    <div class="journal-lesson__homework" id="student-journal-homework"></div>
                                </div>

                                <script type="application/json" id="student-lessons-json"><?= json_encode($journalLessons, JSON_UNESCAPED_UNICODE) ?></script>
                                <script type="application/json" id="student-dates-json"><?= json_encode($journalDates, JSON_UNESCAPED_UNICODE) ?></script>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            <?php elseif ($viewRole === 'parent'): ?>
                <div class="workspace workspace--parent">
                    <?php if ($parentChild === null): ?>
                        <div class="uni-empty">Ребёнок не выбран.</div>
                    <?php else: ?>
                        <?php
                        $cid = (int) $parentChild['id'];
                        $childMarks = $journalMarks[$cid] ?? [];
                        $childProjectIds = $projectsByStudent[$cid] ?? [];
                        $pv = $parentVisitsMock[$cid] ?? null;
                        $myVisits = $_SESSION['parent_visits'][$cid] ?? [];
                        $myTotalVisits = array_sum($myVisits);
                        ?>

                        <section class="parent-child-picker">
                            <span class="parent-child-picker__label">Ребёнок:</span>
                            <select onchange="location.href='index.php?role=parent&child_id=' + this.value;">
                                <?php foreach ($mockStudents as $s): ?>
                                    <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $cid ? 'selected' : '' ?>><?= e($s['name']) ?> · <?= e($s['group']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span style="flex:1"></span>
                            <span class="parent-visits-chip parent-visits-chip--active" title="Ваши визиты в CRM (всего за сессию)">
                                👪 Ваши визиты: <?= (int) $myTotalVisits ?>
                            </span>
                            <?php if ($pv !== null): ?>
                                <span class="parent-visits-chip" title="Ваша активность за последний месяц">
                                    За месяц: <?= (int) $pv['count'] ?> · последний: <?= e($pv['last']) ?>
                                </span>
                            <?php endif; ?>
                        </section>

                        <?php if (!empty($parentBadMarks)): ?>
                            <div class="bad-marks-banner">
                                <span class="bad-marks-banner__icon">⚠️</span>
                                <div class="bad-marks-banner__body">
                                    <strong>Внимание!</strong> У вашего ребёнка <?= count($parentBadMarks) ?> <?= count($parentBadMarks) === 1 ? 'низкая отметка' : 'низких отметок' ?> за последний период.
                                    <div class="bad-marks-banner__list">
                                        <?php foreach ($parentBadMarks as $bm): ?>
                                            <span class="bad-marks-banner__pill">
                                                <strong><?= e($bm['mark']) ?></strong>
                                                <?= e($bm['date']) ?> — <?= e($bm['topic']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="notification notification--success">
                                <span class="notification__icon">✓</span>
                                <div class="notification__body">Низких отметок за период не зафиксировано. Так держать!</div>
                            </div>
                        <?php endif; ?>

                        <section class="panel">
                            <div class="student-header">
                                <div class="student-header__avatar"><?= e(mb_substr($parentChild['name'], 0, 1, 'UTF-8')) ?></div>
                                <div class="student-header__info">
                                    <div class="student-header__name"><?= e($parentChild['name']) ?></div>
                                    <div class="student-header__meta">Группа <?= e($parentChild['group']) ?> · курс «<?= e($currentProject['title']) ?>» · проектов: <?= count($childProjectIds) ?></div>
                                </div>
                            </div>
                        </section>

                        <section class="panel panel--tabs">
                            <div class="tabs" role="tablist">
                                <button class="tab is-active" data-tab="journal" role="tab" aria-selected="true">📓 Журнал</button>
                                <button class="tab" data-tab="project" role="tab" aria-selected="false">📋 Проекты</button>
                            </div>

                            <div class="tab-content is-active" data-tab-content="journal" role="tabpanel">
                                <p class="panel__subtitle" style="margin-bottom: var(--space-sm);">
                                    Оценки вашего ребёнка и заметки преподавателя. Клик по дате — тема урока и домашнее задание.
                                </p>
                                <div class="journal-wrap">
                                    <table class="journal" id="journal-table-parent">
                                        <thead>
                                            <tr>
                                                <th>Дата</th>
                                                <th>Отметка</th>
                                                <th>Заметка преподавателя</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($journalDates as $i => $d): ?>
                                                <?php
                                                $mark = $childMarks[$i] ?? '';
                                                $markClass = '';
                                                if ($mark === '5') $markClass = 'journal-mark--grade-5';
                                                elseif ($mark === '4') $markClass = 'journal-mark--grade-4';
                                                elseif ($mark === '3') $markClass = 'journal-mark--grade-3';
                                                elseif ($mark === '2') $markClass = 'journal-mark--grade-2';
                                                elseif ($mark === '·') $markClass = 'journal-mark--dot';
                                                elseif ($mark === 'н') $markClass = 'journal-mark--absent';
                                                $noteText = $journalNotes[$cid][$i] ?? '';
                                                $showMark = ($mark !== '' && $mark !== '·');
                                                ?>
                                                <tr data-date-index="<?= (int) $i ?>">
                                                    <td style="font-weight: 600;"><?= e($d) ?></td>
                                                    <td style="text-align: center;">
                                                        <?php if ($showMark): ?>
                                                            <span class="journal-mark <?= $markClass ?>" style="font-size:1rem; min-width:32px; height:32px;"><?= e($mark) ?></span>
                                                        <?php else: ?>
                                                            <span style="color:rgba(26,26,26,0.15);">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align:left; font-size:0.82rem; color: var(--color-secondary);">
                                                        <?= $noteText !== '' ? e($noteText) : '<span style="color:rgba(26,26,26,0.35);font-style:italic;">—</span>' ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="journal-lesson journal-lesson--empty" id="parent-journal-lesson">
                                    <div class="journal-lesson__date" id="parent-journal-date">Урок не выбран</div>
                                    <div class="journal-lesson__title" id="parent-journal-topic">Выберите дату в таблице, чтобы увидеть тему урока и домашнее задание.</div>
                                    <div class="journal-lesson__homework" id="parent-journal-homework"></div>
                                </div>

                                <script type="application/json" id="parent-lessons-json"><?= json_encode($journalLessons, JSON_UNESCAPED_UNICODE) ?></script>
                                <script type="application/json" id="parent-dates-json"><?= json_encode($journalDates, JSON_UNESCAPED_UNICODE) ?></script>
                            </div>

                            <div class="tab-content" data-tab-content="project" role="tabpanel">
                                <p class="panel__subtitle" style="margin-bottom: var(--space-sm);">
                                    Проекты вашего ребёнка. Возвраты к предыдущим фазам (↩) — нормальный процесс доработки.
                                </p>

                                <?php if ($childProjectIds === []): ?>
                                    <div class="uni-empty">Пока нет записей по проектам.</div>
                                <?php else: ?>
                                    <div class="projects-grid">
                                        <?php foreach ($childProjectIds as $pid): ?>
                                            <?php
                                            $proj = $projectById[$pid] ?? null;
                                            if (!$proj) continue;
                                            $curPhase = project_current_phase($proj);
                                            $type = $projectTypes[$proj['type']] ?? ['label' => $proj['type'], 'color' => '#8b6914', 'icon' => '📌'];
                                            ?>
                                            <article class="project-card" style="--type-color: <?= e($type['color']) ?>;">
                                                <header class="project-card__head">
                                                    <div>
                                                        <div class="project-card__title"><?= e($proj['title']) ?></div>
                                                        <div class="project-card__participants">
                                                            <strong>Участники:</strong>
                                                            <?php
                                                            $names = [];
                                                            foreach ($proj['students'] as $psid) {
                                                                $st = $studentById[(int) $psid] ?? null;
                                                                if (!$st) continue;
                                                                $names[] = e($st['name']);
                                                            }
                                                            echo implode(', ', $names);
                                                            ?>
                                                        </div>
                                                    </div>
                                                    <span class="project-type-chip" style="--type-color: <?= e($type['color']) ?>;">
                                                        <?= $type['icon'] ?> <?= e($type['label']) ?>
                                                    </span>
                                                </header>

                                                <div class="project-card__section-label">Текущая фаза</div>
                                                <div class="project-card__stepper">
                                                    <?php foreach ($studentPhases as $sp): ?>
                                                        <?php
                                                        $spNum = (int) $sp['num'];
                                                        $cls = 'step-mini--pending';
                                                        if ($spNum < $curPhase) $cls = 'step-mini--done';
                                                        elseif ($spNum === $curPhase) $cls = 'step-mini--current';
                                                        ?>
                                                        <div class="step-mini <?= $cls ?>">
                                                            <span class="step-mini__num"><?= $spNum ?></span>
                                                            <span class="step-mini__name"><?= e($sp['name']) ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>

                                                <?php if (!empty($proj['history'])): ?>
                                                    <div>
                                                        <div class="project-card__section-label">История фаз</div>
                                                        <div class="phase-timeline">
                                                            <?php $prevNum = 0; ?>
                                                            <?php foreach ($proj['history'] as $idx => $h): ?>
                                                                <?php
                                                                $sp = $studentPhaseByNum[(int) $h['num']] ?? null;
                                                                if (!$sp) continue;
                                                                $isReturn = $prevNum > 0 && (int) $h['num'] < $prevNum;
                                                                ?>
                                                                <?php if ($idx > 0): ?>
                                                                    <span class="phase-arrow <?= $isReturn ? 'phase-arrow--return' : '' ?>"><?= $isReturn ? '↩' : '→' ?></span>
                                                                <?php endif; ?>
                                                                <span class="phase-chip" style="--phase-color: <?= e($sp['color']) ?>;" title="<?= e($h['note']) ?>">
                                                                    <span class="phase-chip__num"><?= (int) $sp['num'] ?></span><?= e($sp['name']) ?><span class="phase-chip__date"><?= e($h['date']) ?></span>
                                                                </span>
                                                                <?php $prevNum = (int) $h['num']; ?>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="workspace workspace--dash">
                    <section class="panel panel--dashboard">
                        <div class="panel__head">
                            <div>
                                <h2 class="panel__title">Дашборд руководителя</h2>
                                <p class="panel__subtitle">Фильтры периода и типа влияют на все графики и карту. Клик по фазе, вузу или маркеру — cross-filtering.</p>
                            </div>
                        </div>

                        <div class="kpi-row">
                            <div class="kpi-card"><div class="kpi-card__label">Всего проектов</div><div class="kpi-card__value"><?= (int) $totalInteractions ?></div><div class="kpi-card__hint">взаимодействий «вуз × продукт»</div></div>
                            <div class="kpi-card"><div class="kpi-card__label">Активных</div><div class="kpi-card__value"><?= (int) $activeInteractions ?></div><div class="kpi-card__hint">в работе, не завершено</div></div>
                            <div class="kpi-card"><div class="kpi-card__label">Вузов</div><div class="kpi-card__value"><?= count($universities) ?></div><div class="kpi-card__hint">партнёров в системе</div></div>
                            <div class="kpi-card"><div class="kpi-card__label">ИТ-продуктов</div><div class="kpi-card__value"><?= count($products) ?></div><div class="kpi-card__hint">на витрине ИТ Школы</div></div>
                            <div class="kpi-card"><div class="kpi-card__label">Средняя фаза</div><div class="kpi-card__value"><?= e((string) $avgPhase) ?></div><div class="kpi-card__hint">из <?= count($phases) - 1 ?> возможных</div></div>
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
                                <div class="dash-card__title">Динамика активности <span class="dash-card__hint" id="hint-activity">—</span></div>
                                <div class="dash-card__body"><canvas id="chart-activity"></canvas></div>
                            </div>
                            <div class="dash-card">
                                <div class="dash-card__title">Распределение по фазам <span class="dash-card__hint" id="hint-phases">—</span></div>
                                <div class="dash-card__body"><canvas id="chart-phases"></canvas></div>
                            </div>
                            <div class="dash-card">
                                <div class="dash-card__title">Топ вузов <span class="dash-card__hint" id="hint-unis">—</span></div>
                                <div class="dash-card__body"><canvas id="chart-universities"></canvas></div>
                            </div>
                            <div class="dash-card dash-card--wide">
                                <div class="dash-card__title">Фазы по вузам <span class="dash-card__hint" id="hint-stacked">—</span></div>
                                <div class="dash-card__body"><canvas id="chart-directions"></canvas></div>
                            </div>
                            <div class="dash-card dash-card--map">
                                <div class="dash-card__title">Карта вузов <span class="dash-card__hint" id="hint-map">цвет — преобладающая фаза за период</span></div>
                                <div class="russia-map-wrap">
                                    <svg class="russia-map" viewBox="0 0 <?= (int) $mapViewWidth ?> <?= (int) $mapViewHeight ?>" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">
                                        <defs>
                                            <linearGradient id="map-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#f8f4e8"/><stop offset="60%" stop-color="#ede7d3"/><stop offset="100%" stop-color="#e0d6ba"/></linearGradient>
                                            <linearGradient id="map-sea" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#eef3f6"/><stop offset="100%" stop-color="#dfe9ee"/></linearGradient>
                                            <radialGradient id="map-vignette" cx="50%" cy="50%" r="70%"><stop offset="60%" stop-color="rgba(0,0,0,0)"/><stop offset="100%" stop-color="rgba(139,105,20,0.08)"/></radialGradient>
                                            <filter id="map-glow" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="3" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
                                            <filter id="map-dot-shadow" x="-50%" y="-50%" width="200%" height="200%"><feDropShadow dx="0" dy="2" stdDeviation="2.2" flood-color="#000" flood-opacity="0.32"/></filter>
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
                                        <path class="russia-outline" d="M 56 250 Q 60 220 90 200 Q 100 180 85 160 Q 90 145 110 155 Q 130 165 155 190 Q 175 200 200 180 Q 230 160 280 130 Q 320 115 340 160 Q 360 170 380 120 Q 420 90 470 70 Q 520 50 555 105 Q 580 115 615 105 Q 660 115 700 130 Q 760 140 820 155 Q 880 165 940 180 Q 985 195 1000 195 Q 1000 210 975 220 Q 960 235 945 260 Q 930 285 900 280 Q 875 275 855 320 Q 850 350 855 375 Q 840 380 830 340 Q 810 320 780 300 Q 765 300 760 340 Q 745 375 720 420 Q 705 445 690 440 Q 685 410 665 395 Q 645 390 600 390 Q 540 390 470 390 Q 430 400 405 400 Q 375 395 350 370 Q 330 355 305 365 Q 275 375 245 400 Q 215 425 185 445 Q 155 465 130 470 Q 105 465 90 440 Q 82 415 80 390 Q 75 355 65 320 Q 56 295 56 250 Z"/>
                                        <rect width="<?= (int) $mapViewWidth ?>" height="<?= (int) $mapViewHeight ?>" fill="url(#map-vignette)" pointer-events="none"/>
                                        <text x="<?= (int) ($mapViewWidth / 2) ?>" y="<?= (int) ($mapViewHeight - 18) ?>" text-anchor="middle" style="font-family: 'Cormorant Garamond', serif; font-size: 22px; font-weight: 500; fill: rgba(139,105,20,0.28); letter-spacing: 0.2em;" pointer-events="none">РОССИЙСКАЯ ФЕДЕРАЦИЯ</text>
                                        <g class="map-points-layer">
                                            <?php foreach ($mapPoints as $p): ?>
                                                <g class="uni-point" data-uni-id="<?= (int) $p['id'] ?>" data-name="<?= e($p['short']) ?>" data-full="<?= e($p['name']) ?>">
                                                    <circle class="uni-point__pulse" r="16" stroke="<?= e($p['color']) ?>" cx="<?= (float) $p['x'] ?>" cy="<?= (float) $p['y'] ?>"/>
                                                    <circle class="uni-point__halo" r="20" fill="<?= e($p['color']) ?>" cx="<?= (float) $p['x'] ?>" cy="<?= (float) $p['y'] ?>" opacity="0"/>
                                                    <circle class="uni-point__dot" r="11" fill="<?= e($p['color']) ?>" stroke="#ffffff" stroke-width="2" cx="<?= (float) $p['x'] ?>" cy="<?= (float) $p['y'] ?>" filter="url(#map-dot-shadow)"/>
                                                    <text class="uni-point__label" x="<?= (float) $p['x'] ?>" y="<?= (float) ($p['y'] + 28) ?>" text-anchor="middle"><?= e($p['short']) ?></text>
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

        var tabs = document.querySelectorAll('.tab[data-tab]');
        var contents = document.querySelectorAll('.tab-content[data-tab-content]');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var target = tab.dataset.tab;
                tabs.forEach(function (t) { t.classList.toggle('is-active', t === tab); t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
                contents.forEach(function (c) { c.classList.toggle('is-active', c.dataset.tabContent === target); });
            });
        });

        var PHASES = <?= json_encode($phaseMeta, JSON_UNESCAPED_UNICODE) ?>;
        var phaseByNum = {};
        PHASES.forEach(function (p) { phaseByNum[p.num] = p; });

        var cells = Array.prototype.slice.call(document.querySelectorAll('table.matrix td.phase[draggable="true"]'));
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
                + '<div class="phase-pocket__label"><span class="phase-pocket__label-arrow">' + arrow + '</span>' + label + '</div>'
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
            pocket.addEventListener('dragover', function (e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; pocket.classList.add('is-hover'); });
            pocket.addEventListener('dragleave', function () { pocket.classList.remove('is-hover'); });
            pocket.addEventListener('drop', function (e) { e.preventDefault(); if (!source) return; doMove(source.dataset.project, pocket.dataset.phaseId); });
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
                body: JSON.stringify({ project_id: pid, target_phase_id: parseInt(targetPhaseId, 10) })
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
                e.dataTransfer.setData('text/plain', JSON.stringify({ projectId: td.dataset.project, fromPhaseId: td.dataset.phaseId }));
                e.dataTransfer.effectAllowed = 'move';
            });
            td.addEventListener('dragend', function () { cleanup(); });
            td.addEventListener('click', function () {
                if (source !== null) return;
                window.location.href = 'index.php?role=manager&project=' + td.dataset.project;
            });
            td.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); window.location.href = 'index.php?role=manager&project=' + td.dataset.project; }
            });
        });
    })();
    </script>
    <?php endif; ?>

    <?php if ($viewRole === 'university' && $currentUser !== null): ?>
    <script>
    (function () {
        'use strict';

        var tabs = document.querySelectorAll('.tab[data-tab]');
        var contents = document.querySelectorAll('.tab-content[data-tab-content]');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var target = tab.dataset.tab;
                tabs.forEach(function (t) { t.classList.toggle('is-active', t === tab); t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
                contents.forEach(function (c) { c.classList.toggle('is-active', c.dataset.tabContent === target); });
            });
        });

        var journalTable = document.getElementById('journal-table');
        if (!journalTable) return;

        var lessons = JSON.parse(document.getElementById('journal-lessons-json').textContent);
        var dates = JSON.parse(document.getElementById('journal-dates-json').textContent);
        var notes = JSON.parse(document.getElementById('journal-notes-json').textContent);
        var students = JSON.parse(document.getElementById('journal-students-json').textContent);
        var marks = JSON.parse(document.getElementById('journal-marks-json').textContent);

        var headerCells = journalTable.querySelectorAll('thead th[data-date-index]');
        var cellCells = journalTable.querySelectorAll('tbody td.journal-cell[data-date-index]');
        var panelDate = document.getElementById('journal-lesson-date');
        var panelTopic = document.getElementById('journal-lesson-topic');
        var panelHw = document.getElementById('journal-lesson-homework');
        var panel = document.getElementById('journal-lesson');

        function selectDate(idx) {
            headerCells.forEach(function (th) { th.classList.toggle('is-selected', parseInt(th.dataset.dateIndex, 10) === idx); });
            cellCells.forEach(function (td) { td.classList.toggle('is-highlighted', parseInt(td.dataset.dateIndex, 10) === idx); });
            var lesson = lessons[idx];
            if (lesson) {
                panel.classList.remove('journal-lesson--empty');
                panelDate.textContent = 'Дата: ' + (dates[idx] || '—');
                panelTopic.textContent = lesson.topic;
                panelHw.innerHTML = '<strong>Домашнее задание:</strong> ' + lesson.homework;
            } else {
                panel.classList.add('journal-lesson--empty');
                panelDate.textContent = 'Урок не выбран';
                panelTopic.textContent = 'Для выбранной даты ещё нет темы.';
                panelHw.textContent = '';
            }
        }

        headerCells.forEach(function (th) {
            th.addEventListener('click', function () { selectDate(parseInt(th.dataset.dateIndex, 10)); });
        });

        var popover = document.createElement('div');
        popover.className = 'note-popover';
        popover.innerHTML = ''
            + '<div class="note-popover__header">'
            +   '<div>'
            +     '<div class="note-popover__student" id="np-student">—</div>'
            +     '<div class="note-popover__meta" id="np-meta">—</div>'
            +   '</div>'
            +   '<div class="note-popover__mark" id="np-mark">—</div>'
            + '</div>'
            + '<label class="note-popover__label" for="np-textarea">Заметка преподавателя</label>'
            + '<textarea class="note-popover__textarea" id="np-textarea" placeholder="Например: разобрать подробнее тему..."></textarea>'
            + '<div class="note-popover__actions">'
            +   '<button type="button" class="note-popover__btn note-popover__btn--danger" id="np-delete">Удалить</button>'
            +   '<button type="button" class="note-popover__btn" id="np-cancel">Отмена</button>'
            +   '<button type="button" class="note-popover__btn note-popover__btn--primary" id="np-save">Сохранить</button>'
            + '</div>'
            + '<div class="note-popover__hint">Заметку можно привязать к оценке, к «·» или к «н».</div>';
        document.body.appendChild(popover);

        var npStudent = document.getElementById('np-student');
        var npMeta = document.getElementById('np-meta');
        var npMark = document.getElementById('np-mark');
        var npTextarea = document.getElementById('np-textarea');
        var npSave = document.getElementById('np-save');
        var npCancel = document.getElementById('np-cancel');
        var npDelete = document.getElementById('np-delete');
        var activeCell = null;

        function markClass(m) {
            if (m === '5') return 'note-popover__mark--grade-5';
            if (m === '4') return 'note-popover__mark--grade-4';
            if (m === '3') return 'note-popover__mark--grade-3';
            if (m === '2') return 'note-popover__mark--grade-2';
            if (m === '·') return 'note-popover__mark--dot';
            if (m === 'н') return 'note-popover__mark--absent';
            return 'note-popover__mark--none';
        }

        function markLabel(m) {
            if (m === '·') return '·';
            if (m === 'н') return 'н';
            if (m === '') return '—';
            return m;
        }

        function openPopoverFor(cell) {
            activeCell = cell;
            var sid = parseInt(cell.dataset.studentId, 10);
            var di = parseInt(cell.dataset.dateIndex, 10);
            var student = students.find(function (s) { return s.id === sid; });
            var m = (marks[sid] && marks[sid][di]) || '';
            var text = (notes[sid] && notes[sid][di]) || '';

            npStudent.textContent = student ? student.name : '—';
            npMeta.textContent = 'Дата: ' + (dates[di] || '—') + ' · ' + (lessons[di] ? lessons[di].topic : '');
            npMark.textContent = markLabel(m);
            npMark.className = 'note-popover__mark ' + markClass(m);
            npTextarea.value = text;

            var rect = cell.getBoundingClientRect();
            var pw = 340;
            var left = rect.left + rect.width / 2 - pw / 2;
            left = Math.max(8, Math.min(left, window.innerWidth - pw - 8));
            var top = rect.bottom + 10;
            if (top + 260 > window.innerHeight - 8) top = rect.top - 270;
            top = Math.max(8, Math.min(top, window.innerHeight - 270));
            popover.style.left = left + 'px';
            popover.style.top = top + 'px';
            requestAnimationFrame(function () { popover.classList.add('is-visible'); });
            npTextarea.focus();
        }

        function closePopover() { popover.classList.remove('is-visible'); activeCell = null; }

        function renderCellContent(cell, sid, di) {
            var m = (marks[sid] && marks[sid][di]) || '';
            var hasNote = !!(notes[sid] && notes[sid][di]);
            var html = '';
            if (m !== '' && m !== '·') {
                var cls = '';
                if (m === '5') cls = 'journal-mark--grade-5';
                else if (m === '4') cls = 'journal-mark--grade-4';
                else if (m === '3') cls = 'journal-mark--grade-3';
                else if (m === '2') cls = 'journal-mark--grade-2';
                else if (m === 'н') cls = 'journal-mark--absent';
                html = '<span class="journal-mark ' + cls + '">' + m + '</span>';
            } else if (hasNote) {
                html = '<span class="journal-note-dot" title="Есть заметка преподавателя">✎</span>';
            }
            cell.innerHTML = html;
            cell.classList.toggle('has-note', hasNote);
        }

        cellCells.forEach(function (td) {
            td.addEventListener('click', function (e) { e.stopPropagation(); openPopoverFor(td); });
        });

        document.addEventListener('click', function (e) { if (!popover.contains(e.target)) closePopover(); });
        popover.addEventListener('click', function (e) { e.stopPropagation(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closePopover(); });

        npCancel.addEventListener('click', closePopover);

        npSave.addEventListener('click', function () {
            if (!activeCell) return;
            var sid = parseInt(activeCell.dataset.studentId, 10);
            var di = parseInt(activeCell.dataset.dateIndex, 10);
            var text = npTextarea.value.trim();
            if (!notes[sid]) notes[sid] = {};
            if (text === '') delete notes[sid][di];
            else notes[sid][di] = text;
            renderCellContent(activeCell, sid, di);
            closePopover();
        });

        npDelete.addEventListener('click', function () {
            if (!activeCell) return;
            var sid = parseInt(activeCell.dataset.studentId, 10);
            var di = parseInt(activeCell.dataset.dateIndex, 10);
            if (notes[sid]) delete notes[sid][di];
            renderCellContent(activeCell, sid, di);
            closePopover();
        });

        if (dates.length > 0) selectDate(0);
    })();
    </script>
    <?php endif; ?>

    <?php if ($viewRole === 'student' && $currentUser !== null): ?>
    <script>
    (function () {
        'use strict';

        var tabs = document.querySelectorAll('.tab[data-tab]');
        var contents = document.querySelectorAll('.tab-content[data-tab-content]');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var target = tab.dataset.tab;
                tabs.forEach(function (t) { t.classList.toggle('is-active', t === tab); t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
                contents.forEach(function (c) { c.classList.toggle('is-active', c.dataset.tabContent === target); });
            });
        });

        var lessonsJson = document.getElementById('student-lessons-json');
        var datesJson = document.getElementById('student-dates-json');
        if (!lessonsJson || !datesJson) return;
        var lessons = JSON.parse(lessonsJson.textContent);
        var dates = JSON.parse(datesJson.textContent);

        var table = document.getElementById('journal-table-student');
        if (!table) return;
        var rows = table.querySelectorAll('tbody tr[data-date-index]');
        var panelDate = document.getElementById('student-journal-date');
        var panelTopic = document.getElementById('student-journal-topic');
        var panelHw = document.getElementById('student-journal-homework');
        var panel = document.getElementById('student-journal-lesson');

        function selectRow(idx) {
            rows.forEach(function (r) {
                var i = parseInt(r.dataset.dateIndex, 10);
                r.style.background = (i === idx) ? 'rgba(139,105,20,0.08)' : '';
            });
            var lesson = lessons[idx];
            if (lesson) {
                panel.classList.remove('journal-lesson--empty');
                panelDate.textContent = 'Дата: ' + (dates[idx] || '—');
                panelTopic.textContent = lesson.topic;
                panelHw.innerHTML = '<strong>Домашнее задание:</strong> ' + lesson.homework;
            }
        }

        rows.forEach(function (r) {
            r.style.cursor = 'pointer';
            r.addEventListener('click', function () { selectRow(parseInt(r.dataset.dateIndex, 10)); });
        });

        if (rows.length > 0) selectRow(0);
    })();
    </script>
    <?php endif; ?>

    <?php if ($viewRole === 'parent' && $currentUser !== null): ?>
    <script>
    (function () {
        'use strict';
        var tabs = document.querySelectorAll('.tab[data-tab]');
        var contents = document.querySelectorAll('.tab-content[data-tab-content]');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var target = tab.dataset.tab;
                tabs.forEach(function (t) { t.classList.toggle('is-active', t === tab); });
                contents.forEach(function (c) { c.classList.toggle('is-active', c.dataset.tabContent === target); });
            });
        });

        var lessonsJson = document.getElementById('parent-lessons-json');
        var datesJson = document.getElementById('parent-dates-json');
        if (!lessonsJson || !datesJson) return;
        var lessons = JSON.parse(lessonsJson.textContent);
        var dates = JSON.parse(datesJson.textContent);

        var table = document.getElementById('journal-table-parent');
        if (!table) return;
        var rows = table.querySelectorAll('tbody tr[data-date-index]');
        var panelDate = document.getElementById('parent-journal-date');
        var panelTopic = document.getElementById('parent-journal-topic');
        var panelHw = document.getElementById('parent-journal-homework');
        var panel = document.getElementById('parent-journal-lesson');

        function selectRow(idx) {
            rows.forEach(function (r) {
                var i = parseInt(r.dataset.dateIndex, 10);
                r.style.background = (i === idx) ? 'rgba(139,105,20,0.08)' : '';
            });
            var lesson = lessons[idx];
            if (lesson) {
                panel.classList.remove('journal-lesson--empty');
                panelDate.textContent = 'Дата: ' + (dates[idx] || '—');
                panelTopic.textContent = lesson.topic;
                panelHw.innerHTML = '<strong>Домашнее задание:</strong> ' + lesson.homework;
            }
        }

        rows.forEach(function (r) {
            r.style.cursor = 'pointer';
            r.addEventListener('click', function () { selectRow(parseInt(r.dataset.dateIndex, 10)); });
        });

        if (rows.length > 0) selectRow(0);
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

        var state = { months: 12, chartType: 'line', phaseId: null, uniId: null, aggregate: null };
        var charts = { activity: null, phases: null, unis: null, stacked: null };

        function phaseById(id) { for (var i = 0; i < DASH.phases.length; i++) if (DASH.phases[i].id === id) return DASH.phases[i]; return null; }
        function uniById(id) { for (var i = 0; i < DASH.universities.length; i++) if (DASH.universities[i].id === id) return DASH.universities[i]; return null; }
        function anyUniById(id) { for (var i = 0; i < DASH.allUniversities.length; i++) if (DASH.allUniversities[i].id === id) return DASH.allUniversities[i]; return null; }

        function hexWithAlpha(hex, alpha) {
            if (!hex || hex.charAt(0) !== '#') return hex;
            var h = hex.substring(1);
            if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
            var r = parseInt(h.substring(0,2),16), g = parseInt(h.substring(2,4),16), b = parseInt(h.substring(4,6),16);
            return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
        }

        function aggregateMonths(n) {
            var slice = DASH.monthly.slice(-n);
            var byUniPhase = {}, labels = [], activity = [], newProjects = [];
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
            return { labels: labels, activity: activity, newProjects: newProjects, byUniPhase: byUniPhase };
        }

        function computeByPhase() {
            var res = {};
            DASH.phases.forEach(function (p) { res[p.id] = 0; });
            Object.keys(state.aggregate.byUniPhase).forEach(function (uid) {
                if (state.uniId && parseInt(uid, 10) !== state.uniId) return;
                var u = state.aggregate.byUniPhase[uid];
                Object.keys(u).forEach(function (pid) { var key = parseInt(pid, 10); if (res[key] !== undefined) res[key] += u[pid]; });
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
            var maxCount = 0, maxPid = null;
            Object.keys(u).forEach(function (pidStr) { if (u[pidStr] > maxCount) { maxCount = u[pidStr]; maxPid = parseInt(pidStr, 10); } });
            return maxPid;
        }

        function phaseColorById(pid) { var p = phaseById(pid); return p ? p.color : '#9ca3af'; }
        function rebuildAggregate() { state.aggregate = aggregateMonths(state.months); }

        function renderActivity() {
            var canvas = document.getElementById('chart-activity');
            if (!canvas) return;
            if (charts.activity) { charts.activity.destroy(); charts.activity = null; }
            var labels = state.aggregate.labels;
            var actData = state.aggregate.activity;
            var newData = state.aggregate.newProjects;
            var gradient = canvas.getContext('2d').createLinearGradient(0, 0, 0, 260);
            gradient.addColorStop(0, 'rgba(139, 105, 20, 0.4)');
            gradient.addColorStop(1, 'rgba(139, 105, 20, 0.02)');
            var type = state.chartType === 'bar' ? 'bar' : 'line';
            var fill = state.chartType === 'area';
            var datasets = [
                { label: 'Активность', data: actData, borderColor: accent, backgroundColor: type === 'bar' ? accent : (fill ? gradient : 'rgba(139, 105, 20, 0.08)'), fill: fill || type === 'bar', tension: 0.35, pointBackgroundColor: accent, pointBorderColor: '#fff', pointBorderWidth: 2, pointRadius: type === 'bar' ? 0 : 4, pointHoverRadius: type === 'bar' ? 0 : 7, borderWidth: 2, borderRadius: type === 'bar' ? 3 : 0, barPercentage: 0.6 },
                { label: 'Новые проекты', data: newData, borderColor: accentDark, backgroundColor: 'transparent', borderDash: type === 'bar' ? [] : [4,4], tension: 0.35, pointBackgroundColor: accentDark, pointRadius: type === 'bar' ? 0 : 3, pointHoverRadius: 5, borderWidth: 1.5, type: 'line', hidden: type === 'bar' }
            ];
            charts.activity = new Chart(canvas, {
                type: type, data: { labels: labels, datasets: datasets },
                options: { responsive: true, maintainAspectRatio: false, animation: { duration: 500, easing: 'easeOutQuart' }, interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14, font: { size: 11 } } }, tooltip: { backgroundColor: 'rgba(26, 26, 26, 0.92)', padding: 10, cornerRadius: 2 } },
                    scales: { x: { grid: { color: gridColor, drawBorder: false }, ticks: { color: textColor } }, y: { beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { color: textColor, precision: 0 } } }
                }
            });
        }

        function renderPhases() {
            var canvas = document.getElementById('chart-phases');
            if (!canvas) return;
            if (charts.phases) { charts.phases.destroy(); charts.phases = null; }
            var byPhase = computeByPhase();
            var labels = DASH.phases.map(function (p) { return p.num + '. ' + p.name; });
            var colors = DASH.phases.map(function (p) { return p.color; });
            var data = DASH.phases.map(function (p) { return byPhase[p.id] || 0; });
            var alphas = DASH.phases.map(function (p) { if (state.phaseId && p.id !== state.phaseId) return 0.22; return 1; });
            charts.phases = new Chart(canvas, {
                type: 'doughnut',
                data: { labels: labels, datasets: [{ data: data, backgroundColor: colors.map(function (c, i) { return hexWithAlpha(c, alphas[i]); }), borderColor: '#fff', borderWidth: 2, hoverOffset: 8 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '60%', animation: { duration: 500, easing: 'easeOutQuart' },
                    onClick: function (evt, els) { if (!els || !els.length) return; var pid = DASH.phases[els[0].index].id; state.phaseId = (state.phaseId === pid) ? null : pid; refreshAll(); },
                    plugins: { legend: { position: 'right', labels: { boxWidth: 10, boxHeight: 10, padding: 8, font: { size: 10 } } }, tooltip: { backgroundColor: 'rgba(26, 26, 26, 0.92)', padding: 10, cornerRadius: 2, callbacks: { label: function (ctx) { return ctx.label + ': ' + ctx.parsed + ' проектов'; } } } }
                }
            });
        }

        function renderUnis() {
            var canvas = document.getElementById('chart-universities');
            if (!canvas) return;
            if (charts.unis) { charts.unis.destroy(); charts.unis = null; }
            var byUni = computeByUni();
            var labels = DASH.universities.map(function (u) { return u.short; });
            var data = DASH.universities.map(function (u) { return byUni[u.id] || 0; });
            var bgs = DASH.universities.map(function (u) { if (state.uniId && u.id !== state.uniId) return hexWithAlpha(accent, 0.22); return accent; });
            var hoverBgs = DASH.universities.map(function (u) { if (state.uniId && u.id !== state.uniId) return hexWithAlpha(accentDark, 0.3); return accentDark; });
            var type = state.chartType === 'bar' ? 'bar' : 'line';
            var fill = state.chartType === 'area';
            var dataset, options;
            if (type === 'bar') {
                dataset = { label: 'Проектов', data: data, backgroundColor: bgs, hoverBackgroundColor: hoverBgs, borderRadius: 3, barThickness: 16 };
                options = { indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: { duration: 500, easing: 'easeOutQuart' },
                    onClick: function (evt, els) { if (!els || !els.length) return; var uid = DASH.universities[els[0].index].id; state.uniId = (state.uniId === uid) ? null : uid; refreshAll(); },
                    plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(26, 26, 26, 0.92)', padding: 10, cornerRadius: 2, callbacks: { label: function (ctx) { var v = ctx.parsed && ctx.parsed.x !== undefined ? ctx.parsed.x : ctx.parsed; return 'Проектов: ' + v; } } } },
                    scales: { x: { beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { color: textColor, precision: 0 } }, y: { grid: { display: false }, ticks: { color: textColor, font: { size: 11 } } } }
                };
            } else {
                dataset = { label: 'Проектов', data: data, borderColor: accent, backgroundColor: fill ? hexWithAlpha(accent, 0.35) : hexWithAlpha(accent, 0.1), fill: fill, tension: 0.35, pointBackgroundColor: accent, pointBorderColor: '#fff', pointBorderWidth: 2, pointRadius: 5, pointHoverRadius: 8, borderWidth: 2 };
                options = { responsive: true, maintainAspectRatio: false, animation: { duration: 500, easing: 'easeOutQuart' },
                    onClick: function (evt, els) { if (!els || !els.length) return; var uid = DASH.universities[els[0].index].id; state.uniId = (state.uniId === uid) ? null : uid; refreshAll(); },
                    plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(26, 26, 26, 0.92)', padding: 10, cornerRadius: 2, callbacks: { label: function (ctx) { var v = ctx.parsed && ctx.parsed.y !== undefined ? ctx.parsed.y : ctx.parsed; return 'Проектов: ' + v; } } } },
                    scales: { x: { grid: { display: false }, ticks: { color: textColor, font: { size: 11 }, autoSkip: false, maxRotation: 45, minRotation: 0 } }, y: { beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { color: textColor, precision: 0 } } }
                };
            }
            charts.unis = new Chart(canvas, { type: type, data: { labels: labels, datasets: [dataset] }, options: options });
        }

        function renderStacked() {
            var canvas = document.getElementById('chart-directions');
            if (!canvas) return;
            if (charts.stacked) { charts.stacked.destroy(); charts.stacked = null; }
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
                var ds = { label: phaseLabels[i], data: data, borderColor: phaseColors[i], borderWidth: 2, tension: 0.35, pointBackgroundColor: phaseColors[i], pointBorderColor: '#fff', pointBorderWidth: 1.5, pointRadius: type === 'bar' ? 0 : 4, pointHoverRadius: 6 };
                if (type === 'bar') { ds.backgroundColor = hexWithAlpha(phaseColors[i], alpha * 0.9); ds.borderRadius = 2; ds.stack = 'phases'; }
                else { ds.backgroundColor = fill ? hexWithAlpha(phaseColors[i], alpha * 0.35) : 'transparent'; ds.fill = fill; }
                datasets.push(ds);
            }
            charts.stacked = new Chart(canvas, {
                type: type, data: { labels: uniLabels, datasets: datasets },
                options: { responsive: true, maintainAspectRatio: false, animation: { duration: 500, easing: 'easeOutQuart' },
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, padding: 10, font: { size: 10 } } }, tooltip: { backgroundColor: 'rgba(26, 26, 26, 0.92)', padding: 10, cornerRadius: 2 } },
                    scales: { x: { stacked: type === 'bar', grid: { display: false }, ticks: { color: textColor } }, y: { stacked: type === 'bar', beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { color: textColor, precision: 0 } } }
                }
            });
        }

        function renderMap() {
            var mapWrap = document.querySelector('.russia-map-wrap');
            if (!mapWrap) return;
            mapWrap.querySelectorAll('.uni-point').forEach(function (g) {
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
                if (state.uniId && uid === state.uniId) { g.classList.add('is-selected'); return; }
                if (state.phaseId) {
                    var uData = state.aggregate.byUniPhase[String(uid)];
                    var inPhase = uData && uData[String(state.phaseId)];
                    if (!inPhase) g.classList.add('is-dimmed');
                } else if (state.uniId && uid !== state.uniId) { g.classList.add('is-dimmed'); }
                else if (!hasData) { g.classList.add('is-dimmed'); }
            });
            document.querySelectorAll('.map-legend__item').forEach(function (li) {
                li.classList.toggle('is-active', state.phaseId === parseInt(li.dataset.phaseId, 10));
            });
        }

        function updateChip() {
            var chip = document.getElementById('filter-chip');
            var label = document.getElementById('filter-chip-label');
            if (!chip || !label) return;
            var parts = [];
            if (state.uniId) { var u = uniById(state.uniId) || anyUniById(state.uniId); if (u) parts.push('Вуз: ' + u.short); }
            if (state.phaseId) { var p = phaseById(state.phaseId); if (p) parts.push('Фаза: ' + p.num + '. ' + p.name); }
            if (parts.length === 0) chip.style.display = 'none';
            else { chip.style.display = 'inline-flex'; label.textContent = parts.join('  ·  '); }
        }

        function updateHints() {
            var tl = state.chartType === 'line' ? 'линия' : (state.chartType === 'bar' ? 'столбцы' : 'область');
            var el;
            if ((el = document.getElementById('hint-activity'))) el.textContent = 'последние ' + state.months + ' мес · ' + tl;
            if ((el = document.getElementById('hint-phases'))) el.textContent = 'за ' + state.months + ' мес · клик — фильтр';
            if ((el = document.getElementById('hint-unis'))) el.textContent = 'за ' + state.months + ' мес · ' + tl + ' · клик — фильтр';
            if ((el = document.getElementById('hint-stacked'))) el.textContent = 'за ' + state.months + ' мес · ' + tl;
            if ((el = document.getElementById('hint-map'))) el.textContent = 'за ' + state.months + ' мес · цвет — преобладающая фаза';
        }

        function refreshAll() { rebuildAggregate(); renderPhases(); renderUnis(); renderStacked(); renderMap(); updateChip(); updateHints(); }

        rebuildAggregate();
        renderActivity(); renderPhases(); renderUnis(); renderStacked(); renderMap();
        updateChip(); updateHints();

        var monthBtns = document.querySelectorAll('[data-months]');
        monthBtns.forEach(function (btn) { btn.addEventListener('click', function () { monthBtns.forEach(function (b) { b.classList.remove('is-active'); }); btn.classList.add('is-active'); state.months = parseInt(btn.dataset.months, 10); refreshAll(); renderActivity(); }); });
        var typeBtns = document.querySelectorAll('[data-act-type]');
        typeBtns.forEach(function (btn) { btn.addEventListener('click', function () { typeBtns.forEach(function (b) { b.classList.remove('is-active'); }); btn.classList.add('is-active'); state.chartType = btn.dataset.actType; renderActivity(); renderUnis(); renderStacked(); updateHints(); }); });

        var chip = document.getElementById('filter-chip');
        if (chip) chip.addEventListener('click', function () { state.phaseId = null; state.uniId = null; refreshAll(); });

        document.querySelectorAll('.map-legend__item').forEach(function (li) { li.addEventListener('click', function () { var pid = parseInt(li.dataset.phaseId, 10); state.phaseId = (state.phaseId === pid) ? null : pid; refreshAll(); }); });

        var mapWrap = document.querySelector('.russia-map-wrap');
        var mapInfo = document.getElementById('map-info');
        if (mapWrap && mapInfo) {
            var infoPhase = document.getElementById('map-info-phase');
            var infoName = document.getElementById('map-info-name');
            var infoProjects = document.getElementById('map-info-projects');
            mapWrap.querySelectorAll('.uni-point').forEach(function (g) {
                var uid = parseInt(g.dataset.uniId, 10);
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
                    infoName.textContent = g.dataset.name || '';
                    infoName.title = g.dataset.full || '';
                    infoProjects.textContent = 'Проектов за период: ' + total + ' · клик — фильтр';
                    mapInfo.classList.add('is-visible');
                });
                g.addEventListener('mouseleave', function () { mapInfo.classList.remove('is-visible'); });
                g.addEventListener('click', function () { state.uniId = (state.uniId === uid) ? null : uid; refreshAll(); });
            });
        }
    })();
    </script>
    <?php endif; ?>

    <!-- ===== Пометка об отсутствии рекомендательных технологий ===== -->
    <footer class="no-reco-note" role="note" aria-label="Уведомление об отсутствии рекомендательных технологий">
        <span class="no-reco-note__icon" aria-hidden="true">ⓘ</span>
        <span>
            Сервис <strong>не использует рекомендательные технологии</strong>
            при предоставлении информации и не применяет профилирование пользователей.
        </span>
    </footer>

    <!-- ===== Cookie-плашка ===== -->
    <div class="cookie-banner" id="cookie-banner" role="dialog" aria-live="polite" aria-label="Использование cookie-файлов">
        <div class="cookie-banner__icon" aria-hidden="true">🍪</div>
        <div class="cookie-banner__body">
            <div class="cookie-banner__title">Мы используем cookie-файлы</div>
            <div class="cookie-banner__text">
                Сайт использует <strong>технические cookie-файлы</strong> для корректной работы
                авторизации и интерфейса. Персональные данные не передаются третьим лицам.
                Рекомендательные технологии не применяются.
                Продолжая пользоваться сервисом, вы соглашаетесь с использованием cookie.
            </div>
        </div>
        <div class="cookie-banner__actions">
            <button type="button" class="cookie-banner__btn cookie-banner__btn--ghost" id="cookie-banner-decline">
                Только необходимые
            </button>
            <button type="button" class="cookie-banner__btn" id="cookie-banner-accept">
                Принять
            </button>
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        var KEY = 'rtk_cookie_consent_v1';
        var banner = document.getElementById('cookie-banner');
        if (!banner) return;

        var acceptBtn  = document.getElementById('cookie-banner-accept');
        var declineBtn = document.getElementById('cookie-banner-decline');

        var stored = null;
        try { stored = window.localStorage.getItem(KEY); } catch (e) { stored = null; }

        if (!stored) {
            setTimeout(function () { banner.classList.add('is-visible'); }, 400);
        }

        function close(value) {
            try { window.localStorage.setItem(KEY, value); } catch (e) {}
            banner.style.opacity = '0';
            banner.style.transform = 'translateY(12px)';
            setTimeout(function () { banner.classList.remove('is-visible'); }, 220);
        }

        acceptBtn.addEventListener('click', function () { close('accepted'); });
        declineBtn.addEventListener('click', function () { close('essential_only'); });
    })();
    </script>
<?php endif; ?>
</body>
</html>