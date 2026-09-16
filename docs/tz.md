# Техническое задание для агента-разработчика

## 1. Общие положения

Необходимо разработать CRM-систему для ИТ Школы РТК, автоматизирующую взаимодействие с вузами по ИТ-направлениям и ИТ-продуктам. Ключевая особенность — **двунаправленный workflow**: внутренний контур ИТ Школы и внешний контур вуза/преподавателя/обучающегося.

Стек:
- Backend: PHP 8.3 + Laravel 11.
- Frontend: Vanilla JS, HTML5, CSS3.
- БД: PostgreSQL 16.
- Кэш/очереди: Redis 7.
- Auth: Keycloak (OIDC).
- Отчёты: PhpSpreadsheet, Dompdf.
- Документация API: OpenAPI/Swagger.
- Контейнеризация: Docker, Docker Compose.
- ОС: Linux.

## 2. Цель

Сократить трудозатраты сотрудников ИТ Школы РТК на взаимодействие с вузами за счёт автоматизации полного пути взаимодействия и двустороннего контроля статусов.

## 3. Термины

- **Workflow** — последовательность шагов взаимодействия.
- **Двунаправленный workflow** — workflow, в котором шаги могут выполняться и/или подтверждаться как внутренней, так и внешней стороной.
- **Внутренний контур** — сотрудники ИТ Школы РТК.
- **Внешний контур** — представители вуза, преподаватели, обучающиеся.
- **Взаимодействие** — экземпляр процесса для пары «вуз + ИТ-программа + ИТ-продукт».
- **Шаг workflow** — атомарный этап процесса.
- **Обратная связь** — комментарий, оценка, запрос доработки, файл.

## 4. Роли и права

### 4.1 Роли

| Код | Название | Контур |
|---|---|---|
| `manager` | Пользователь / NAM | Внутренний |
| `supervisor` | Руководитель | Внутренний |
| `admin` | Администратор | Внутренний |
| `university_rep` | Представитель вуза | Внешний |
| `teacher` | Преподаватель | Внешний |
| `student` | Обучающийся | Внешний, опционально |

### 4.2 Матрица прав

| Действие | manager | supervisor | admin | university_rep | teacher | student |
|---|---|---|---|---|---|---|
| Просмотр своих взаимодействий | Да | Да | Да | Да | Да | Да |
| Просмотр всех взаимодействий | Нет | Да | Да | Нет | Нет | Нет |
| Создание взаимодействия | Да | Да | Да | Нет | Нет | Нет |
| Изменение статуса шага | Да | Да | Да | Только внешние шаги | Только внешние шаги | Нет |
| Подтверждение внешнего шага | Нет | Нет | Да | Да | Да | Нет |
| Запрос доработки | Да | Да | Да | Да | Да | Да |
| Комментирование | Да | Да | Да | Да | Да | Да |
| Прикладывание файлов | Да | Да | Да | Да | Да | Нет |
| Назначение ответственных | Нет | Да | Да | Нет | Нет | Нет |
| Управление справочниками | Нет | Нет | Да | Нет | Нет | Нет |
| Управление правами | Нет | Нет | Да | Нет | Нет | Нет |
| Формирование отчётов | Да | Да | Да | Ограниченно | Ограниченно | Нет |

## 5. Функциональные требования

### 5.1 Двунаправленный workflow

Каждый шаг workflow должен иметь:
- `side`: `internal`, `external`, `both`;
- `status`: `pending`, `in_progress`, `waiting_internal`, `waiting_external`, `approved`, `rejected`, `needs_revision`, `completed`, `cancelled`;
- `requires_attachment`: boolean;
- `requires_comment`: boolean;
- `sla_hours`: integer, опционально.

Правила переходов:
- Внутренняя сторона может начинать/завершать шаги `internal` и `both`.
- Внешняя сторона может подтверждать/отклонять/запрашивать доработку по шагам `external` и `both`.
- Шаг `both` завершается только после подтверждения обеих сторон.
- При `needs_revision` шаг возвращается стороне, которая должна внести правки.
- Все переходы логируются в `status_history` и `audit_log`.

Базовый workflow из 14 шагов:

| № | Шаг | Внутренняя сторона | Внешняя сторона | Тип |
|---|---|---|---|---|
| 1 | Поиск контактов ответственного в вузе | Менеджер | — | internal |
| 2 | Коммуникация и уточнение актуальности программ | Менеджер | Представитель вуза | both |
| 3 | Организация встречи | Менеджер | Представитель вуза | both |
| 4 | Обмен документами | Менеджер | Представитель вуза | both |
| 5 | Корректировка документов | Менеджер | Представитель вуза | both |
| 6 | Подписание документов | Менеджер | Представитель вуза | both |
| 7 | Передача материалов, лицензий, документации | Менеджер | Представитель вуза подтверждает | internal → external |
| 8 | Сопровождение внедрения | Менеджер | Преподаватель/представитель | both |
| 9 | Обучение преподавателей | Менеджер | Преподаватель | both |
| 10 | Актуализация учебной программы | Менеджер | Преподаватель/представитель | both |
| 11 | Ведение занятий | Мониторинг | Преподаватель | external |
| 12 | Актуализация документации | Менеджер | Преподаватель/представитель | both |
| 13 | Повышение квалификации преподавателей | Менеджер | Преподаватель | both |
| 14 | Контроль исполнения | Менеджер/руководитель | — | internal |

Администратор и руководитель могут создавать новые и изменять существующие workflow.

### 5.2 Справочники и импорт

Справочники хранятся в БД и могут обновляться через интерфейс загрузкой XLS/XLSX.

Сущности справочников:
- ВУЗы;
- Вендоры;
- ПО;
- ИТ-направления;
- ИТ-программы;
- Ответственные от Школы;
- Ответственные от вуза.

Поля импорта:
- Название ВУЗа
- Вендор
- ПО
- Номер договора
- Подписание лицензии
- Срок действия лицензии (год)
- Статус по передаче
- ФИО Менеджера
- Ответственные от ВУЗа
- Комментарий

Требования:
- Поддержка `.xls`, `.xlsx`.
- Сопоставление колонок через интерфейс.
- Валидация обязательных полей.
- Лог импорта с ошибками.
- Возможность догрузки и обновления существующих записей.

### 5.3 Карточка взаимодействия

Карточка взаимодействия должна содержать:
- вуз;
- ИТ-направление;
- ИТ-программу;
- ИТ-продукт;
- ответственного от Школы;
- ответственного от вуза;
- текущий статус;
- текущий шаг workflow;
- историю шагов;
- комментарии;
- файлы;
- обратную связь;
- отчёты по взаимодействию.

### 5.4 Статусы, переходы, комментарии, файлы, обратная связь

- Переход статуса выполняется через API и UI.
- При переходе можно указать комментарий и приложить файлы.
- Внешняя сторона может оставить оценку (1–5) и текстовую обратную связь.
- Внутренняя сторона видит обратную связь и может ответить.
- Разрешённые форматы файлов: `png`, `jpeg`, `pdf`, `zip`, `gzip`, `rar`, `doc`, `docx`, `xls`, `xlsx`.
- Максимальный размер файла: 50 МБ.
- Файлы хранятся в защищённом хранилище, доступ — по токену.

### 5.5 Отчёты

- Фильтры: период, вуз, ИТ-направление, ИТ-продукт, ответственный, статус.
- Колонки: наименование вуза, ИТ-направление, ИТ-продукт, статус работы с вузом, ответственный.
- Форматы: XLS, XLSX, PDF.
- Асинхронная генерация через очередь.
- Статусы отчёта: `queued`, `processing`, `ready`, `failed`.
- Скачивание по ссылке.
- Диаграммы: PNG, PDF.
- Не менее 10 параллельных отчётов разной сложности.

### 5.6 API-интеграции

- Получение данных из LMS и веб-сайта по API.
- Формат: JSON.
- Методы: `GET`, `POST`.
- Маппинг полей согласуется.
- Полученные данные могут добавляться в существующий или новый workflow.
- Логирование интеграций.
- Повторные попытки при ошибках.

### 5.7 Уведомления

- Внутренние уведомления в UI.
- Email-уведомления опционально.
- Уведомления о:
  - переходе статуса;
  - запросе доработки;
  - новом комментарии;
  - готовности отчёта;
  - ошибке интеграции.

### 5.8 Кэш

- Кэширование справочников.
- Кэширование прав пользователя.
- Кэширование шаблонов workflow.
- Кэширование результатов тяжёлых отчётов.
- Инвалидация при изменении данных.
- Использовать Redis.

### 5.9 Аудит

Фиксировать:
- пользователя;
- действие;
- сущность;
- старое и новое значение;
- IP;
- User-Agent;
- время.

## 6. Нефункциональные требования

- Время отклика интерфейса: ≤ 1 секунда.
- 50 параллельных пользователей.
- 10 параллельных отчётов.
- Отсутствие полной перезагрузки страницы при действиях.
- Понятные коды ошибок.
- Интуитивный интерфейс.
- Документация встроена в платформу.
- Работа на Linux.
- Docker-контейнеры.
- Swagger UI.
- Открытый исходный код без обфускации.

## 7. Архитектура

### 7.1 Компоненты

1. Web UI — Vanilla JS.
2. API — Laravel.
3. Auth — Keycloak.
4. DB — PostgreSQL.
5. Cache/Queue — Redis.
6. File Storage.
7. Report Worker.
8. Integration Worker.
9. Swagger.
10. Docker Compose.

### 7.2 Схема БД

Основные таблицы:

- `users` — id, keycloak_id, email, full_name, role, organization_id, is_active, timestamps.
- `roles` — id, code, name.
- `user_roles` — user_id, role_id.
- `organizations` — id, name, type, address, contacts, timestamps.
- `vendors` — id, name.
- `software_products` — id, vendor_id, name, description.
- `it_directions` — id, name, description.
- `it_programs` — id, direction_id, name, description.
- `program_products` — program_id, product_id.
- `responsible_persons` — id, user_id, organization_id, role_in_university, contact.
- `interactions` — id, organization_id, program_id, product_id, manager_id, supervisor_id, status, current_step_id, period_start, period_end, timestamps.
- `workflow_templates` — id, name, is_default, version, created_by, timestamps.
- `workflow_steps` — id, template_id, order, code, name, side, description, requires_attachment, requires_comment, sla_hours.
- `workflow_step_instances` — id, interaction_id, step_id, status, internal_status, external_status, started_at, completed_at, due_at, assigned_to.
- `comments` — id, interaction_id, step_instance_id, user_id, body, visibility, timestamps.
- `attachments` — id, comment_id, step_instance_id, interaction_id, file_name, file_path, mime_type, size, uploaded_by, timestamps.
- `feedbacks` — id, interaction_id, step_instance_id, user_id, rating, text, type, timestamps.
- `status_history` — id, interaction_id, step_instance_id, old_status, new_status, changed_by, changed_at, comment.
- `reports` — id, user_id, params_json, file_path, format, status, timestamps.
- `import_logs` — id, user_id, file_name, status, errors_json, timestamps.
- `api_integrations` — id, name, type, base_url, auth_type, settings_json, is_active.
- `external_data` — id, source, external_id, payload_json, synced_at.
- `audit_log` — id, user_id, action, entity_type, entity_id, old_values, new_values, ip, user_agent, timestamps.

Индексы:
- `interactions(organization_id, program_id, product_id, manager_id, status)`.
- `workflow_step_instances(interaction_id, status)`.
- `comments(interaction_id, step_instance_id)`.
- `attachments(interaction_id, step_instance_id)`.
- `reports(user_id, status)`.

### 7.3 API endpoints

#### Auth
- `GET /api/auth/login`
- `GET /api/auth/callback`
- `POST /api/auth/logout`
- `GET /api/me`

#### Справочники
- `GET /api/organizations`
- `POST /api/organizations`
- `GET /api/vendors`
- `GET /api/products`
- `GET /api/directions`
- `GET /api/programs`
- `GET /api/users`
- `GET /api/responsibles`

#### Импорт
- `POST /api/imports/dictionaries`

#### Взаимодействия
- `GET /api/interactions`
- `POST /api/interactions`
- `GET /api/interactions/{id}`
- `PATCH /api/interactions/{id}`
- `DELETE /api/interactions/{id}`

#### Workflow
- `GET /api/workflow/templates`
- `POST /api/workflow/templates`
- `GET /api/interactions/{id}/workflow`
- `POST /api/interactions/{id}/workflow/steps/{stepId}/transition`
- `POST /api/interactions/{id}/workflow/steps/{stepId}/feedback`
- `POST /api/interactions/{id}/workflow/steps/{stepId}/comments`
- `POST /api/interactions/{id}/workflow/steps/{stepId}/attachments`

#### Отчёты
- `POST /api/reports`
- `GET /api/reports/{id}`
- `GET /api/reports/{id}/download`

#### Интеграции
- `POST /api/integrations/lms/sync`
- `POST /api/integrations/site/sync`
- `GET /api/integrations/status`

#### Администрирование
- `GET /api/admin/users`
- `PATCH /api/admin/users/{id}`
- `GET /api/admin/roles`
- `PATCH /api/admin/settings`

Все методы должны быть описаны в Swagger UI.

## 8. Детали реализации

### 8.1 Backend

- Laravel 11, PHP 8.3.
- Слои: Controller → Service → Repository → Model.
- Валидация через FormRequest.
- RBAC через middleware + policies.
- Очереди: Redis.
- Кэш: Redis.
- Логирование: Monolog.
- Тесты: PHPUnit.

### 8.2 Frontend

- Vanilla JS SPA.
- Роутинг: History API.
- Состояние: модули + события.
- HTTP: Fetch API.
- Графики: Chart.js.
- Стили: CSS3, CSS Grid/Flexbox.
- Адаптивность: mobile-first.
- Компоненты: таблица, фильтры, карточка, таймлайн, канбан, модальные окна, формы.
- Без перезагрузки страницы.

### 8.3 Keycloak

- Realm: `rtk-it-school`.
- Client: `crm-web`, public, PKCE.
- Роли: `manager`, `supervisor`, `admin`, `university_rep`, `teacher`, `student`.
- Backend валидирует JWT по JWKS.
- Роли из токена маппятся в права.
- Внешние пользователи привязаны к организации.

### 8.4 БД

- PostgreSQL 16.
- Миграции Laravel.
- Сидеры для ролей, статусов, базового workflow.
- Транзакции для критичных операций.
- Мягкое удаление для справочников.

### 8.5 Redis

- Кэш справочников.
- Кэш прав.
- Очереди отчётов и интеграций.
- Rate limiting.

### 8.6 Файлы

- Хранилище: `storage/app/private`.
- Доступ через подписанные URL.
- Проверка MIME.
- Ограничение размера.
- Опционально: ClamAV.

### 8.7 Отчёты

- Генерация в очереди.
- PhpSpreadsheet для XLS/XLSX.
- Dompdf для PDF.
- Графики: Chart.js на фронте, экспорт PNG через canvas, PDF — серверно.
- Статусы и уведомления.

### 8.8 Импорт

- PhpSpreadsheet для чтения.
- Сопоставление колонок.
- Валидация.
- Лог ошибок.
- Обновление или создание записей.

### 8.9 Swagger

- OpenAPI 3.0.
- Аннотации в контроллерах.
- Swagger UI по `/api/documentation`.
- Примеры запросов и ответов.

### 8.10 Docker

- `docker-compose.yml`.
- Сервисы: `nginx`, `app`, `worker`, `postgres`, `redis`, `keycloak`.
- Volume для БД и файлов.
- Переменные окружения в `.env`.

## 9. UI/UX

- Десктоп и мобильные.
- Интуитивный интерфейс.
- Логически связанные блоки рядом.
- Достаточный контраст.
- Читабельный текст.
- Быстрые фильтры.
- Понятные статусы.
- Цветовая индикация: зелёный — завершено, жёлтый — в работе, красный — отклонено/просрочено.
- Таймлайн и канбан.
- Модальные окна для комментариев и файлов.

## 10. Безопасность

- 152-ФЗ.
- Приказ ФСТЭК № 117.
- JWT, OIDC, Keycloak.
- RBAC.
- Аудит.
- TLS.
- Валидация.
- Ограничение доступа по организациям.
- Шифрование чувствительных полей.
- Регулярное резервное копирование.

## 11. Тестирование

- Unit: PHPUnit.
- Feature: API.
- E2E: Playwright.
- Нагрузочное: k6 или Apache JMeter.
- Проверка 50 параллельных пользователей.
- Проверка 10 параллельных отчётов.

## 12. Документация

- README.md.
- tz.md.
- Руководство пользователя.
- Руководство администратора.
- Swagger UI.
- Archi-схема.
- Инструкция по сборке и установке.

## 13. Поставка

- Репозиторий с исходным кодом.
- Презентация PPTX/PDF.
- Ссылка на работающий прототип.
- Сопроводительная документация DOC/PDF.
- Docker Compose.
- Swagger UI.

## 14. Критерии приёмки

- Работает двунаправленный workflow.
- Внешняя сторона может подтверждать, отклонять, запрашивать доработку.
- Внутренняя сторона видит обратную связь.
- Импорт XLS/XLSX.
- Отчёты XLS, XLSX, PDF.
- Фильтрация и визуализация.
- Интеграция с LMS и сайтом по API.
- Keycloak-авторизация.
- Роли и права.
- Кэш.
- Аудит.
- Docker.
- Swagger.
- Документация.
- Время отклика ≤ 1 сек.
- 50 параллельных пользователей.
- 10 параллельных отчётов.

## 15. Приложения

### 15.1 Коды ошибок

| Код | Описание |
|---|---|
| `AUTH_001` | Не авторизован |
| `AUTH_002` | Недостаточно прав |
| `AUTH_003` | Токен истёк |
| `VALIDATION_001` | Ошибка валидации |
| `WF_001` | Недопустимый переход статуса |
| `WF_002` | Шаг не найден |
| `WF_003` | Требуется комментарий |
| `WF_004` | Требуется вложение |
| `FILE_001` | Недопустимый формат файла |
| `FILE_002` | Превышен размер файла |
| `REPORT_001` | Ошибка генерации отчёта |
| `IMPORT_001` | Ошибка импорта |
| `INTEGRATION_001` | Ошибка интеграции |
| `DB_001` | Ошибка базы данных |
| `SERVER_001` | Внутренняя ошибка сервера |

### 15.2 Пример структуры ответа API

```json
{
  "success": true,
  "data": {},
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 100
  },
  "errors": []
}

### 15.3 Пример ошибки

{
  "success": false,
  "data": null,
  "errors": [
    {
      "code": "WF_001",
      "message": "Недопустимый переход статуса"
    }
  ]
}

