<?php
/**
 * Инициализация сущностей «ВУЗы», «ИТ-продукты» и «Взаимодействия» (фазы)
 * для CRM «ИТ Школа РТК» (прототип, SQLite).
 *
 * Создание таблиц:
 *  - universities   — справочник вузов;
 *  - it_products    — справочник ИТ-продуктов (курсы и ИТ-программы);
 *  - interaction_phases — справочник фаз взаимодействия (14 шагов ТЗ);
 *  - interactions   — фаза взаимодействия пары «вуз + продукт».
 *
 * Запуск: php -c php.ini init_universities.php
 * Файл идемпотентен: при повторном запуске структура и данные не дублируются.
 */

require __DIR__ . '/db.php';

$pdo = db();

// --- Структура -------------------------------------------------------------

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS universities (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(200) NOT NULL UNIQUE,
    city VARCHAR(100) DEFAULT NULL
)
SQL);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS it_products (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(200) NOT NULL UNIQUE,
    kind VARCHAR(50) NOT NULL DEFAULT 'курс'
)
SQL);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS interaction_phases (
    id    INTEGER PRIMARY KEY AUTOINCREMENT,
    code  VARCHAR(50) NOT NULL UNIQUE,
    num   INTEGER NOT NULL,
    name  VARCHAR(200) NOT NULL,
    color VARCHAR(20) NOT NULL
)
SQL);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS interactions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    university_id INTEGER NOT NULL REFERENCES universities(id) ON DELETE CASCADE,
    product_id    INTEGER NOT NULL REFERENCES it_products(id) ON DELETE CASCADE,
    phase_id      INTEGER REFERENCES interaction_phases(id) ON DELETE SET NULL,
    UNIQUE (university_id, product_id)
)
SQL);

// --- ВУЗы ------------------------------------------------------------------

$universities = [
    ['name' => 'Казанский (Приволжский) федеральный университет', 'city' => 'Казань'],
    ['name' => 'Московский физико-технический институт', 'city' => 'Долгопрудный'],
    ['name' => 'Национальный исследовательский ядерный университет «МИФИ»', 'city' => 'Москва'],
    ['name' => 'Санкт-Петербургский государственный университет', 'city' => 'Санкт-Петербург'],
    ['name' => 'Новосибирский государственный университет', 'city' => 'Новосибирск'],
    ['name' => 'Уральский федеральный университет', 'city' => 'Екатеринбург'],
    ['name' => 'Южный федеральный университет', 'city' => 'Ростов-на-Дону'],
    ['name' => 'Дальневосточный федеральный университет', 'city' => 'Владивосток'],
    ['name' => 'Национальный исследовательский Томский политехнический университет', 'city' => 'Томск'],
    ['name' => 'Казанский национальный исследовательский технический университет', 'city' => 'Казань'],
];

$insertUniversity = $pdo->prepare('INSERT INTO universities (name, city) VALUES (:name, :city)');
$selectUniversity = $pdo->prepare('SELECT id FROM universities WHERE name = :name');
$universityIds = [];

foreach ($universities as $university) {
    $selectUniversity->execute([':name' => $university['name']]);
    $id = $selectUniversity->fetchColumn();

    if ($id === false) {
        $insertUniversity->execute($university);
        $id = $pdo->lastInsertId();
        echo "Создан вуз: {$university['name']}\n";
    }

    $universityIds[] = (int) $id;
}

// --- ИТ-продукты -----------------------------------------------------------

$products = [
    ['name' => 'Курсы по использованию ИИ в обучении', 'kind' => 'курс'],
    ['name' => 'Основы Data Science для преподавателей', 'kind' => 'курс'],
    ['name' => 'Кибергигиена и информационная безопасность', 'kind' => 'курс'],
    ['name' => 'Облачные технологии: практика применения', 'kind' => 'курс'],
    ['name' => 'Разработка на Python: базовый курс', 'kind' => 'курс'],
    ['name' => 'Машинное обучение в задачах телекома', 'kind' => 'курс'],
    ['name' => 'Цифровая трансформация образования', 'kind' => 'курс'],
    ['name' => 'Программирование для школьников', 'kind' => 'курс'],
    ['name' => 'Аналитика данных: визуализация и отчётность', 'kind' => 'курс'],
    ['name' => 'IoT: умные устройства и сети', 'kind' => 'курс'],
    ['name' => 'ИТ-программа «Большие данные и ИИ»', 'kind' => 'программа'],
    ['name' => 'ИТ-программа «Информационная безопасность»', 'kind' => 'программа'],
    ['name' => 'ИТ-программа «Облачные вычисления и DevOps»', 'kind' => 'программа'],
    ['name' => 'ИТ-программа «Сети связи и телеком-системы»', 'kind' => 'программа'],
    ['name' => 'ИТ-программа «Цифровые платформы и лоу-код разработка»', 'kind' => 'программа'],
];

$insertProduct = $pdo->prepare('INSERT INTO it_products (name, kind) VALUES (:name, :kind)');
$selectProduct = $pdo->prepare('SELECT id FROM it_products WHERE name = :name');
$productIds = [];

foreach ($products as $product) {
    $selectProduct->execute([':name' => $product['name']]);
    $id = $selectProduct->fetchColumn();

    if ($id === false) {
        $insertProduct->execute($product);
        $id = $pdo->lastInsertId();
        echo "Создан продукт: {$product['name']}\n";
    }

    $productIds[] = (int) $id;
}

// --- Фазы взаимодействия ---------------------------------------------------

$phases = [
    ['code' => 'not_started',    'num' => 0,  'name' => 'Не начато',                          'color' => '#e5e7eb'],
    ['code' => 'search_contact', 'num' => 1,  'name' => 'Поиск контактов в вузе',              'color' => '#fca5a5'],
    ['code' => 'communication',  'num' => 2,  'name' => 'Коммуникация и уточнение программ',   'color' => '#fdba74'],
    ['code' => 'meeting',        'num' => 3,  'name' => 'Организация встречи',                 'color' => '#fde047'],
    ['code' => 'exchange_docs',  'num' => 4,  'name' => 'Обмен документами',                   'color' => '#d9f99d'],
    ['code' => 'revise_docs',    'num' => 5,  'name' => 'Корректировка документов',            'color' => '#a3e635'],
    ['code' => 'sign_docs',      'num' => 6,  'name' => 'Подписание документов',               'color' => '#86efac'],
    ['code' => 'transfer',       'num' => 7,  'name' => 'Передача материалов и лицензий',      'color' => '#6ee7b7'],
    ['code' => 'implementation', 'num' => 8,  'name' => 'Сопровождение внедрения',             'color' => '#5eead4'],
    ['code' => 'training',       'num' => 9,  'name' => 'Обучение преподавателей',             'color' => '#67e8f9'],
    ['code' => 'curriculum',     'num' => 10, 'name' => 'Актуализация учебной программы',      'color' => '#7dd3fc'],
    ['code' => 'classes',        'num' => 11, 'name' => 'Ведение занятий',                     'color' => '#93c5fd'],
    ['code' => 'docs_update',    'num' => 12, 'name' => 'Актуализация документации',           'color' => '#a5b4fc'],
    ['code' => 'adv_training',   'num' => 13, 'name' => 'Повышение квалификации преподавателей', 'color' => '#c4b5fd'],
    ['code' => 'control',        'num' => 14, 'name' => 'Контроль исполнения',                 'color' => '#16a34a'],
];

$insertPhase = $pdo->prepare('INSERT INTO interaction_phases (code, num, name, color) VALUES (:code, :num, :name, :color)');
$selectPhase = $pdo->prepare('SELECT id FROM interaction_phases WHERE code = :code');
$phaseIds = [];

foreach ($phases as $phase) {
    $selectPhase->execute([':code' => $phase['code']]);
    $id = $selectPhase->fetchColumn();

    if ($id === false) {
        $insertPhase->execute($phase);
        $id = $pdo->lastInsertId();
        echo "Создана фаза: {$phase['name']}\n";
    }

    $phaseIds[$phase['code']] = (int) $id;
}

// --- Взаимодействия (заполнение для демонстрации) --------------------------

// Ключевые фазы для заполнения сетки: первые 10 пар «вуз + продукт» получают
// фазы от 1 до 14 (циклически), остальные остаются «не начато».
$activeCodes = array_values(array_filter(array_column($phases, 'code'), static function ($code) {
    return $code !== 'not_started';
}));

$insertInteraction = $pdo->prepare(
    'INSERT OR IGNORE INTO interactions (university_id, product_id, phase_id) VALUES (:u, :p, :ph)'
);
$updateInteraction = $pdo->prepare(
    'UPDATE interactions SET phase_id = :ph WHERE university_id = :u AND product_id = :p'
);

$counter = 0;
foreach ($universityIds as $uIndex => $uId) {
    foreach ($productIds as $pIndex => $pId) {
        if ($uIndex === $pIndex % count($universityIds)) {
            $code = $activeCodes[$counter % count($activeCodes)];
            $insertInteraction->execute([':u' => $uId, ':p' => $pId, ':ph' => $phaseIds[$code]]);
            $updateInteraction->execute([':ph' => $phaseIds[$code], ':u' => $uId, ':p' => $pId]);
            $counter++;
        }
    }
}

echo "Инициализация завершена. Таблицы: universities, it_products, interaction_phases, interactions.\n";
