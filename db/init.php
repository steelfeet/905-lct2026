<?php
declare(strict_types=1);

/**
 * Полная инициализация БД SQLite.
 *
 *   php db/init.php                 — создать/дополнить data/crm.sqlite
 *   php db/init.php --reset         — снести и создать заново
 *   php db/init.php --path=/x.sqlite
 */

$argvList = $_SERVER['argv'] ?? [];
$reset = in_array('--reset', $argvList, true);
$customPath = null;
foreach ($argvList as $arg) {
    if (str_starts_with($arg, '--path=')) $customPath = substr($arg, 7);
}

$dbPath  = $customPath ?? (__DIR__ . '/../data/crm.sqlite');
$dataDir = dirname($dbPath);
if (!is_dir($dataDir)) mkdir($dataDir, 0775, true);

if ($reset && is_file($dbPath)) {
    unlink($dbPath);
    echo "→ Удалён старый файл: {$dbPath}\n";
}

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

-- ===== Документы =====
CREATE TABLE IF NOT EXISTS documents (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    interaction_id     INTEGER NOT NULL REFERENCES interactions(id) ON DELETE CASCADE,
    type               TEXT NOT NULL,
    title              TEXT NOT NULL,
    status             TEXT NOT NULL DEFAULT 'draft',
    owner_side         TEXT NOT NULL DEFAULT 'internal',
    visibility         TEXT NOT NULL DEFAULT 'uni',
    current_version_id INTEGER,
    deadline           TEXT,
    created_by         INTEGER REFERENCES users(id),
    created_at         TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS document_versions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id  INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    version      INTEGER NOT NULL,
    file_path    TEXT NOT NULL,
    file_name    TEXT NOT NULL,
    file_size    INTEGER NOT NULL DEFAULT 0,
    file_hash    TEXT NOT NULL,
    uploaded_by  INTEGER NOT NULL REFERENCES users(id),
    uploaded_at  TEXT NOT NULL DEFAULT (datetime('now')),
    comment      TEXT,
    UNIQUE (document_id, version)
);

CREATE TABLE IF NOT EXISTS document_events (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id   INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    version_id    INTEGER REFERENCES document_versions(id),
    actor_user_id INTEGER NOT NULL REFERENCES users(id),
    actor_side    TEXT NOT NULL,
    action        TEXT NOT NULL,
    from_status   TEXT,
    to_status     TEXT,
    comment       TEXT,
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS document_signatures (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    version_id  INTEGER NOT NULL REFERENCES document_versions(id),
    user_id     INTEGER NOT NULL REFERENCES users(id),
    side        TEXT NOT NULL,
    signed_at   TEXT NOT NULL DEFAULT (datetime('now')),
    file_hash   TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_doc_events_doc  ON document_events (document_id, created_at);
CREATE INDEX IF NOT EXISTS idx_documents_owner ON documents (owner_side, status);
CREATE INDEX IF NOT EXISTS idx_documents_deadline ON documents (deadline) WHERE deadline IS NOT NULL;

-- ===== Встроенная справка =====
CREATE TABLE IF NOT EXISTS help_articles (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    slug            TEXT NOT NULL UNIQUE,
    title           TEXT NOT NULL,
    body_md         TEXT NOT NULL,
    role_visibility TEXT NOT NULL DEFAULT 'all',
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS help_feedback (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id  INTEGER NOT NULL REFERENCES help_articles(id) ON DELETE CASCADE,
    user_id     INTEGER REFERENCES users(id),
    body        TEXT NOT NULL,
    is_resolved INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
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
foreach ($roles as [$id, $code, $name]) $stmt->execute([':id' => $id, ':code' => $code, ':name' => $name]);
echo "✓ Роли: " . count($roles) . "\n";

// =============================================================================
// Пользователи
// =============================================================================
$users = [
    [1, 'manager',        'Иванов Иван Иванович',       1],
    [2, 'supervisor',     'Петров Пётр Петрович',       1],
    [3, 'university_rep', 'Сидоров Сидор Сидорович',    1],
    [4, 'student',        'Кузнецов Кузьма Кузьмич',    1],
    [5, 'parent',         'Морозов Михаил Михайлович',  1],
];
$stmt = $pdo->prepare('INSERT OR IGNORE INTO users (id, login, full_name, is_active) VALUES (:id, :login, :full_name, :is_active)');
foreach ($users as [$id, $login, $full, $active]) {
    $stmt->execute([':id' => $id, ':login' => $login, ':full_name' => $full, ':is_active' => $active]);
}
echo "✓ Пользователи: " . count($users) . "\n";

$userRoles = [[1, 1], [2, 2], [3, 3], [4, 4], [5, 5]];
$stmt = $pdo->prepare('INSERT OR IGNORE INTO user_roles (user_id, role_id) VALUES (:u, :r)');
foreach ($userRoles as [$u, $r]) $stmt->execute([':u' => $u, ':r' => $r]);
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
foreach ($universities as [$id, $name]) $stmt->execute([':id' => $id, ':name' => $name]);
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
foreach ($products as [$id, $name]) $stmt->execute([':id' => $id, ':name' => $name]);
echo "✓ Продукты: " . count($products) . "\n";

// =============================================================================
// Фазы
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
$stmt = $pdo->prepare('INSERT OR IGNORE INTO interaction_phases (id, code, num, name, color) VALUES (:id, :code, :num, :name, :color)');
foreach ($phases as [$id, $code, $num, $name, $color]) $stmt->execute([':id' => $id, ':code' => $code, ':num' => $num, ':name' => $name, ':color' => $color]);
echo "✓ Фазы: " . count($phases) . "\n";

// =============================================================================
// Взаимодействия
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
        if ((($uid + $pid) % 4) === 0) continue;
        $phaseId  = (($uid * 3 + $pid * 5) % 14) + 1;
        $contract = ((($uid + $pid) % 3) === 0) ? null : sprintf('Д-%04d/2026', $uid * 100 + $pid);
        $manager  = $managers[$uid % 3];
        $contact  = 'Контакт ' . $uid . '-' . $pid;
        $stmt->execute([
            ':u' => $uid, ':p' => $pid, ':phase' => $phaseId,
            ':contract' => $contract, ':manager' => $manager, ':contact' => $contact,
        ]);
        $inserted += $stmt->rowCount();
    }
}
$totalInteractions = (int) $pdo->query('SELECT COUNT(*) FROM interactions')->fetchColumn();
echo "✓ Взаимодействия: +{$inserted} (всего {$totalInteractions})\n";

// =============================================================================
// Документы (сид)
// =============================================================================
echo "→ Документы...\n";

$interactionIds = $pdo->query('SELECT id FROM interactions ORDER BY id LIMIT 8')->fetchAll(PDO::FETCH_COLUMN);
if (count($interactionIds) < 8) {
    echo "! Не хватает interactions для сида документов\n";
} else {
    $sampleDocs = [
        // [$iid, $type, $title, $status, $owner, $deadline, $versionsCount, $sigCount, $withRevision]
        [$interactionIds[0], 'contract', 'Договор о сотрудничестве Д-1001/2026', 'active',         'internal', '+365 days', 2, 2, true],
        [$interactionIds[1], 'contract', 'Договор о сотрудничестве Д-1002/2026', 'in_review',      'external', null,        1, 0, false],
        [$interactionIds[2], 'program',  'Учебная программа «Python для преподавателей»', 'approved', 'external', null,       2, 1, true],
        [$interactionIds[3], 'license',  'Лицензия на «Кибергигиена»',          'active',         'internal', '+180 days', 1, 2, false],
        [$interactionIds[4], 'materials','Учебные материалы (архив, 450 МБ)',    'approved',       'external', null,        1, 0, false],
        [$interactionIds[5], 'addendum', 'Доп. соглашение №1 к Д-1005/2026',    'needs_revision', 'internal', null,        2, 0, true],
        [$interactionIds[6], 'program',  'Учебная программа «ML в телекоме»',    'draft',          'internal', null,        1, 0, false],
        [$interactionIds[7], 'contract', 'Договор о сотрудничестве Д-1008/2025', 'active',         'internal', '-10 days',  2, 2, false],
    ];

    $stubDir = $dataDir . '/documents';
    $stmtDoc = $pdo->prepare(
        'INSERT OR IGNORE INTO documents (interaction_id, type, title, status, owner_side, visibility, deadline, created_by, created_at)
         VALUES (:iid, :type, :title, :status, :owner, :vis, :deadline, 1, :created)'
    );
    $stmtVer = $pdo->prepare(
        'INSERT INTO document_versions (document_id, version, file_path, file_name, file_size, file_hash, uploaded_by, comment, uploaded_at)
         VALUES (:did, :v, :path, :name, :size, :hash, :u, :c, :at)'
    );
    $stmtEvt = $pdo->prepare(
        'INSERT INTO document_events (document_id, version_id, actor_user_id, actor_side, action, comment, created_at)
         VALUES (:d, :v, :u, :s, :a, :c, :at)'
    );
    $stmtSig = $pdo->prepare(
        'INSERT INTO document_signatures (document_id, version_id, user_id, side, file_hash, signed_at)
         VALUES (:d, :v, :u, :s, :h, :at)'
    );

    $daysAgo = function (int $n): string { return date('Y-m-d H:i:s', strtotime("-{$n} days")); };
    $resolveDeadline = function (?string $spec): ?string {
        if ($spec === null) return null;
        if (preg_match('/^([+-])(\d+) days$/', $spec, $m)) {
            $sign = $m[1] === '-' ? -1 : 1;
            return date('Y-m-d', strtotime(($sign === -1 ? '-' : '+') . $m[2] . ' days'));
        }
        return $spec;
    };

    $docIndex = 0;
    foreach ($sampleDocs as $s) {
        [$iid, $type, $title, $status, $owner, $deadlineSpec, $vCount, $sigCount, $withRev] = $s;
        $docIndex++;
        $deadline = $resolveDeadline($deadlineSpec);

        $stmtDoc->execute([
            ':iid' => $iid, ':type' => $type, ':title' => $title, ':status' => $status,
            ':owner' => $owner, ':vis' => 'uni', ':deadline' => $deadline,
            ':created' => $daysAgo(30 - $docIndex),
        ]);
        $docId = (int) $pdo->lastInsertId();
        if ($docId === 0) { $stmt = $pdo->prepare('SELECT id FROM documents WHERE interaction_id = :i AND title = :t'); $stmt->execute([':i' => $iid, ':t' => $title]); $docId = (int) $stmt->fetchColumn(); }
        if ($docId === 0) continue;

        if (!is_dir($stubDir . '/' . $docId)) mkdir($stubDir . '/' . $docId, 0775, true);

        $lastVerId = null;
        for ($v = 1; $v <= $vCount; $v++) {
            $sha = hash('sha256', "stub-doc{$docId}-v{$v}-" . microtime());
            $fname = "v{$v}_" . substr($sha, 0, 8) . '.pdf';
            $abs = $stubDir . '/' . $docId . '/' . $fname;
            file_put_contents($abs, "%PDF-1.4 stub for doc {$docId} v{$v}\n");
            $rel = 'data/documents/' . $docId . '/' . $fname;
            $size = filesize($abs) ?: 0;
            $uploadedAt = $daysAgo(20 - $v);
            $comment = $v === 1 ? 'Первоначальная версия' : ($withRev && $v === 2 ? 'Учтены правки вуза (п. 3.2, 5.1)' : 'Обновление');
            $stmtVer->execute([
                ':did' => $docId, ':v' => $v, ':path' => $rel, ':name' => "Документ_v{$v}.pdf",
                ':size' => $size, ':hash' => $sha, ':u' => 1, ':c' => $comment, ':at' => $uploadedAt,
            ]);
            $lastVerId = (int) $pdo->lastInsertId();

            $stmtEvt->execute([
                ':d' => $docId, ':v' => $lastVerId, ':u' => 1, ':s' => 'internal',
                ':a' => 'uploaded', ':c' => $comment, ':at' => $uploadedAt,
            ]);
        }

        // Обновить current_version_id
        $pdo->prepare('UPDATE documents SET current_version_id = :v WHERE id = :id')
            ->execute([':v' => $lastVerId, ':id' => $docId]);

        // События по статусу
        $steps = [];
        if (in_array($status, ['sent', 'in_review', 'approved', 'active', 'needs_revision'], true)) {
            $steps[] = ['u' => 1, 's' => 'internal', 'a' => 'sent', 'c' => null, 'd' => 18];
        }
        if (in_array($status, ['in_review', 'approved', 'active'], true)) {
            $steps[] = ['u' => 3, 's' => 'external', 'a' => 'accept_review', 'c' => null, 'd' => 16];
        }
        if ($withRev && in_array($status, ['approved', 'active'], true)) {
            $steps[] = ['u' => 3, 's' => 'external', 'a' => 'request_revision', 'c' => 'Просим уточнить п.3.2 и п.5.1', 'd' => 14];
            $steps[] = ['u' => 1, 's' => 'internal', 'a' => 'sent', 'c' => 'Версия с правками', 'd' => 12];
            $steps[] = ['u' => 3, 's' => 'external', 'a' => 'accept_review', 'c' => null, 'd' => 11];
        }
        if ($status === 'needs_revision' && !$withRev) {
            $steps[] = ['u' => 3, 's' => 'external', 'a' => 'request_revision', 'c' => 'Требуется доработка', 'd' => 10];
        }
        if (in_array($status, ['approved', 'active'], true)) {
            $steps[] = ['u' => 3, 's' => 'external', 'a' => 'approve', 'c' => null, 'd' => 8];
        }
        foreach ($steps as $st) {
            $stmtEvt->execute([
                ':d' => $docId, ':v' => null, ':u' => $st['u'], ':s' => $st['s'],
                ':a' => $st['a'], ':c' => $st['c'], ':at' => $daysAgo($st['d']),
            ]);
        }

        // Подписи
        if ($sigCount >= 1 && $lastVerId !== null) {
            $stmtSig->execute([':d' => $docId, ':v' => $lastVerId, ':u' => 1, ':s' => 'internal', ':h' => hash('sha256', 'sig-int-' . $docId), ':at' => $daysAgo(6)]);
            $stmtEvt->execute([':d' => $docId, ':v' => $lastVerId, ':u' => 1, ':s' => 'internal', ':a' => 'sign', ':c' => null, ':at' => $daysAgo(6)]);
        }
        if ($sigCount >= 2 && $lastVerId !== null) {
            $stmtSig->execute([':d' => $docId, ':v' => $lastVerId, ':u' => 3, ':s' => 'external', ':h' => hash('sha256', 'sig-ext-' . $docId), ':at' => $daysAgo(4)]);
            $stmtEvt->execute([':d' => $docId, ':v' => $lastVerId, ':u' => 3, ':s' => 'external', ':a' => 'sign', ':c' => null, ':at' => $daysAgo(4)]);
        }
    }
    echo "✓ Документы: " . count($sampleDocs) . "\n";
}

// =============================================================================
// Help-статьи
// =============================================================================
echo "→ Справка...\n";
$help = [
    ['docs-intro', 'Как работать с документами',
     "Документ — это не вложение в шаг workflow, а самостоятельный объект.\nУ него есть версии, лента событий и две подписи.\n\nВсе документы доступны в разделе «Документы».\nВкладки сверху помогают фильтровать:\n• На мне — документы, где ждут именно вас\n• От меня — что вы отправили и ждёте реакции\n• Просрочены — с истёкшим сроком",
     'manager'],
    ['docs-approve', 'Как согласовать документ',
     "Когда ИТ Школа отправляет документ, он попадает к вам во вкладку «На мне».\n\n1. Откройте карточку.\n2. Скачайте версию, проверьте.\n3. Если всё верно — нажмите «Согласовать».\n4. Если нужны правки — «Вернуть на доработку» с комментарием.\n\nПосле согласования документ ждёт двух подписей. Одна ваша, одна со стороны ИТ Школы.",
     'university'],
    ['workflow-intro', 'Как устроен двунаправленный workflow',
     "Обычно workflow контролирует одна сторона — менеджер.\n\nВ нашей CRM каждая фаза имеет две стороны:\n• internal — действие внутри ИТ Школы\n• external — действие вуза\n• both — совместный шаг\n\nЕсли шаг чётный (2, 4, 6 …) — он требует подтверждения второй стороной. Статус в шаге ждёт, пока обе стороны не сойдутся.",
     'all'],
    ['reports', 'Как сформировать отчёт',
     "Отчёты доступны в разделе «Отчёты».\nФильтры: период, вуз, ИТ-направление, ИТ-продукт, ответственный.\n\nФорматы: XLS, XLSX, PDF.\nКолонки: наименование вуза, ИТ-направление, ИТ-продукт, статус работы, ответственный.",
     'supervisor'],
    ['journal', 'Как работать с журналом',
     "Журнал показывает оценки студентов по датам.\nКлик по дате — тема урока и домашнее задание.\nКлик по ячейке с оценкой — заметка преподавателя (можно привязать к оценке).\n\nАктивность родителей в CRM видна как индикатор рядом с именем: зелёный — активен, оранжевый — реже, красный — не заходит.",
     'university'],
];
$stmt = $pdo->prepare('INSERT OR IGNORE INTO help_articles (slug, title, body_md, role_visibility) VALUES (:s, :t, :b, :r)');
foreach ($help as [$slug, $title, $body, $role]) {
    $stmt->execute([':s' => $slug, ':t' => $title, ':b' => $body, ':r' => $role]);
}
echo "✓ Help-статей: " . count($help) . "\n";

// =============================================================================
// Сводка
// =============================================================================
echo "\nИтог:\n";
foreach (['roles','users','user_roles','universities','it_products','interaction_phases','interactions','documents','document_versions','document_events','document_signatures','help_articles'] as $t) {
    try {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        printf("  %-22s %d\n", $t, $count);
    } catch (Throwable $e) {
        printf("  %-22s ?\n", $t);
    }
}

echo "\nГотово.\n";
echo "  Логин: manager / supervisor / university_rep / student / parent\n";
echo "  Документы: /index.php?page=documents&role=manager\n";