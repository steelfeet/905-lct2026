<?php
declare(strict_types=1);

/**
 * Полная инициализация БД SQLite для CRM «ИТ Школа РТК».
 *
 * Запуск:
 *   php db/init.php                     # создать/дополнить data/crm.sqlite
 *   php db/init.php --reset             # снести и создать заново
 *   php db/init.php --path=/path/x.sqlite
 *
 * Идемпотентно: повторный запуск не дублирует данные.
 * Зависимостей нет, кроме pdo_sqlite.
 */

$argvList = $_SERVER['argv'] ?? [];
$reset    = in_array('--reset', $argvList, true);
$customPath = null;
foreach ($argvList as $arg) {
    if (str_starts_with($arg, '--path=')) {
        $customPath = substr($arg, 7);
    }
}

// --- Куда кладём БД ---
$dbPath  = $customPath ?? (__DIR__ . '/../data/crm.sqlite');
$dataDir = dirname($dbPath);
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
}

if ($reset && is_file($dbPath)) {
    unlink($dbPath);
    echo "→ Удалён старый файл: {$dbPath}\n";
}

// --- Подключение ---
try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
} catch (PDOException $e) {
    fwrite(STDERR, "Не могу открыть БД: " . $e->getMessage() . "\n");
    exit(1);
}

echo "→ БД: {$dbPath}\n\n";

// =============================================================================
// Схема
// =============================================================================
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS roles (
    id    INTEGER PRIMARY KEY AUTOINCREMENT,
    code  TEXT NOT NULL UNIQUE,
    name  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    login      TEXT NOT NULL UNIQUE,
    full_name  TEXT NOT NULL DEFAULT '',
    is_active  INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, role_id)
);

CREATE INDEX IF NOT EXISTS idx_users_login_active ON users (login, is_active);

CREATE TABLE IF NOT EXISTS universities (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS it_products (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS interaction_phases (
    id    INTEGER PRIMARY KEY AUTOINCREMENT,
    code  TEXT NOT NULL UNIQUE,
    num   INTEGER NOT NULL UNIQUE,
    name  TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT '#8b6914'
);

CREATE TABLE IF NOT EXISTS interactions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    university_id INTEGER NOT NULL REFERENCES universities(id) ON DELETE CASCADE,
    product_id    INTEGER NOT NULL REFERENCES it_products(id)  ON DELETE CASCADE,
    phase_id      INTEGER          REFERENCES interaction_phases(id) ON DELETE SET NULL,
    contract_no   TEXT,
    license_from  TEXT,
    license_to    TEXT,
    manager_fio   TEXT,
    uni_contact   TEXT,
    comment       TEXT,
    created_at    TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (university_id, product_id)
);

CREATE INDEX IF NOT EXISTS idx_interactions_uni     ON interactions (university_id);
CREATE INDEX IF NOT EXISTS idx_interactions_product ON interactions (product_id);
CREATE INDEX IF NOT EXISTS idx_interactions_phase   ON interactions (phase_id);
SQL);

echo "✓ Схема готова\n";

// =============================================================================
// Роли
// =============================================================================
$roles = [
    [1, 'manager',    'Менеджер'],
    [2, 'supervisor', 'Руководитель'],
    [3, 'university', 'Представитель вуза'],
    [4, 'student',    'Студент'],
    [5, 'parent',     'Родитель'],
];
$stmt = $pdo->prepare('INSERT OR IGNORE INTO roles (id, code, name) VALUES (:id, :code, :name)');
foreach ($roles as [$id, $code, $name]) {
    $stmt->execute([':id' => $id, ':code' => $code, ':name' => $name]);
}
echo "✓ Роли: " . count($roles) . "\n";

// =============================================================================
// Пользователи
// login должен совпадать с claim `login` из Keycloak
// (или preferred_username, если адаптер маппит его в login).
// =============================================================================
$users = [
    [1, 'manager',        'Иванов Иван Иванович',       1],
    [2, 'supervisor',     'Петров Пётр Петрович',       1],
    [3, 'university_rep', 'Сидоров Сидор Сидорович',    1],
    [4, 'student',        'Кузнецов Кузьма Кузьмич',    1],
    [5, 'parent',         'Морозов Михаил Михайлович',  1],
];
$stmt = $pdo->prepare(
    'INSERT OR IGNORE INTO users (id, login, full_name, is_active)
     VALUES (:id, :login, :full_name, :is_active)'
);
foreach ($users as [$id, $login, $full, $active]) {
    $stmt->execute([
        ':id'        => $id,
        ':login'     => $login,
        ':full_name' => $full,
        ':is_active' => $active,
    ]);
}
echo "✓ Пользователи: " . count($users) . "\n";

// =============================================================================
// Связки user ↔ role
// =============================================================================
$userRoles = [
    [1, 1],  // manager        → manager
    [2, 2],  // supervisor     → supervisor
    [3, 3],  // university_rep → university
    [4, 4],  // student        → student
    [5, 5],  // parent         → parent
];
$stmt = $pdo->prepare('INSERT OR IGNORE INTO user_roles (user_id, role_id) VALUES (:u, :r)');
foreach ($userRoles as [$u, $r]) {
    $stmt->execute([':u' => $u, ':r' => $r]);
}
echo "✓ user_roles: " . count($userRoles) . "\n";

// =============================================================================
// Вузы
// =============================================================================
$universities = [
    [1,  'Казанский (Приволжский) федеральный университет'],
    [2,  'Московский физико-технический институт'],
    [3,  'Национальный исследовательский ядерный университет «МИФИ»'],
    [4,  'Санкт-Петербургский государственный университет'],
    [5,  'Новосибирский государственный университет'],
    [6,  'Уральский федеральный университет'],
    [7,  'Южный федеральный университет'],
    [8,  'Дальневосточный федеральный университет'],
    [9,  'Национальный исследовательский Томский политехнический университет'],
    [10, 'Казанский национальный исследовательский технический университет им. А.Н. Туполева-КАИ'],
];
$stmt = $pdo->prepare('INSERT OR IGNORE INTO universities (id, name) VALUES (:id, :name)');
foreach ($universities as [$id, $name]) {
    $stmt->execute([':id' => $id, ':name' => $name]);
}
echo "✓ Вузы: " . count($universities) . "\n";

// =============================================================================
// ИТ-продукты
// =============================================================================
$products = [
    [1,  'Курсы по использованию ИИ в обучении'],
    [2,  'Основы Data Science для преподавателей'],
    [3,  'Кибергигиена и информационная безопасность'],
    [4,  'Облачные технологии: практика применения'],
    [5,  'Разработка на Python: базовый курс'],
    [6,  'Машинное обучение в задачах телекома'],
    [7,  'Цифровая трансформация образования'],
    [8,  'Программирование для школьников'],
    [9,  'Аналитика данных: визуализация и отчётность'],
    [10, 'IoT: умные устройства и сети'],
    [11, 'ИТ-программа «Большие данные и ИИ»'],
    [12, 'ИТ-программа «Информационная безопасность»'],
    [13, 'ИТ-программа «Облачные вычисления и DevOps»'],
    [14, 'ИТ-программа «Сети связи и телеком-системы»'],
    [15, 'ИТ-программа «Цифровые платформы и лоу-код разработка»'],
];
$stmt = $pdo->prepare('INSERT OR IGNORE INTO it_products (id, name) VALUES (:id, :name)');
foreach ($products as [$id, $name]) {
    $stmt->execute([':id' => $id, ':name' => $name]);
}
echo "✓ Продукты: " . count($products) . "\n";

// =============================================================================
// Фазы взаимодействия (14 шагов из ТЗ)
// =============================================================================
$phases = [
    [1,  'find_contact',       1,  'Поиск контактов ответственного в вузе',       '#8b6914'],
    [2,  'communicate',        2,  'Коммуникация и уточнение актуальности',       '#a67c00'],
    [3,  'meeting',            3,  'Организация встречи',                          '#d97706'],
    [4,  'exchange_docs',      4,  'Обмен документами',                            '#ea580c'],
    [5,  'fix_docs',           5,  'Корректировка документов',                     '#dc2626'],
    [6,  'sign_docs',          6,  'Подписание документов',                        '#be185d'],
    [7,  'transfer_materials', 7,  'Передача материалов, лицензий, документации', '#7c3aed'],
    [8,  'support_rollout',    8,  'Сопровождение внедрения',                      '#4f46e5'],
    [9,  'teach_teachers',     9,  'Обучение преподавателей',                      '#2563eb'],
    [10, 'update_program',     10, 'Актуализация учебной программы',               '#0891b2'],
    [11, 'conduct_classes',    11, 'Ведение занятий',                              '#0d9488'],
    [12, 'update_docs',        12, 'Актуализация документации',                    '#16a34a'],
    [13, 'advanced_training',  13, 'Повышение квалификации преподавателей',        '#65a30d'],
    [14, 'control_execution',  14, 'Контроль исполнения',                          '#475569'],
];
$stmt = $pdo->prepare(
    'INSERT OR IGNORE INTO interaction_phases (id, code, num, name, color)
     VALUES (:id, :code, :num, :name, :color)'
);
foreach ($phases as [$id, $code, $num, $name, $color]) {
    $stmt->execute([
        ':id'    => $id,
        ':code'  => $code,
        ':num'   => $num,
        ':name'  => $name,
        ':color' => $color,
    ]);
}
echo "✓ Фазы: " . count($phases) . "\n";

// =============================================================================
// Взаимодействия: 10 вузов × 15 продуктов, ~75% заполнены
// =============================================================================
$managers = ['Иванов И.И.', 'Петрова А.С.', 'Сидоров П.А.'];
$stmt = $pdo->prepare(
    'INSERT OR IGNORE INTO interactions
     (university_id, product_id, phase_id, contract_no, manager_fio, uni_contact)
     VALUES (:u, :p, :phase, :contract, :manager, :contact)'
);

$inserted = 0;
foreach ($universities as [$uid]) {
    foreach ($products as [$pid]) {
        if ((($uid + $pid) % 4) === 0) {
            continue; // оставляем ~25% пустых клеток
        }
        $phaseId  = (($uid * 3 + $pid * 5) % 14) + 1;
        $contract = ((($uid + $pid) % 3) === 0)
            ? null
            : sprintf('Д-%04d/2026', $uid * 100 + $pid);
        $manager  = $managers[$uid % 3];
        $contact  = 'Контакт ' . $uid . '-' . $pid;

        $stmt->execute([
            ':u'        => $uid,
            ':p'        => $pid,
            ':phase'    => $phaseId,
            ':contract' => $contract,
            ':manager'  => $manager,
            ':contact'  => $contact,
        ]);
        $inserted += $stmt->rowCount();
    }
}
$total = (int) $pdo->query('SELECT COUNT(*) FROM interactions')->fetchColumn();
echo "✓ Взаимодействия: +{$inserted} (всего {$total})\n";

// =============================================================================
// Сводка
// =============================================================================
echo "\nИтог:\n";
foreach (
    ['roles', 'users', 'user_roles', 'universities', 'it_products',
     'interaction_phases', 'interactions'] as $t
) {
    $count = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    printf("  %-20s %d\n", $t, $count);
}

echo "\nГотово. Проверьте вход:\n";
echo "  login: manager / supervisor / university_rep / student / parent\n";
echo "  (в Keycloak/stub должны быть пользователи с теми же username)\n";