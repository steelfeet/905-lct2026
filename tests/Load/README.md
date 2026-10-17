# Нагрузочные тесты

## Что проверяем
| NFR из ТЗ | Файл | Порог |
|---|---|---|
| 50 параллельных пользователей | k6-basic.js | stages → 50 VU |
| Отклик < 1 с | k6-basic.js | p95 < 1000 мс на всех ключевых |
| Смена статуса < 1 с, без перезагрузки | k6-basic.js | p95 < 1000 + no `<!DOCTYPE` |
| 10 параллельных отчётов | k6-reports.js | 10 VU, p95 < 3000 мс |

## Подготовка окружения
php -S не годится — один воркер. Поднимаем php-fpm + nginx:

    docker compose -f docker-compose.test.yml up -d --build
    # ждём healthcheck
    curl -sS http://localhost:8080/index.php | grep Keycloak

## Прогон

    # smoke (10 секунд)
    docker run --rm -i --network host grafana/k6 run - < tests/Load/k6-smoke.js

    # основной профиль: 50 VU
    docker run --rm -i --network host \
      -e BASE_URL=http://localhost:8080 \
      grafana/k6 run - < tests/Load/k6-basic.js

    # параллельные отчёты: 10 VU
    docker run --rm -i --network host \
      -e BASE_URL=http://localhost:8080 \
      grafana/k6 run - < tests/Load/k6-reports.js

## Что делать с результатами
- Выгрузка в JSON: `--out json=results.json`
- HTML-отчёт через k6-reporter:
  `-o json=out.json && k6-reporter out.json > report.html`
- Если p95 > порога — смотреть, какая именно страница (тег `name` в выводе k6)
  чаще всех тормозит. В прототипе это обычно дашборд руководителя из-за
  inline-генерации 12 месяцев × 8 вузов × 14 фаз.

## Ограничения прототипа
- `/reports` как отдельный endpoint не реализован — нагрузка на отчёты
  измеряется через дашборд с фильтрами (тот же pipeline агрегации).
- Keycloak-stub обходится дешевле реального Keycloak — при переключении
  адаптера на реальный провайдер добавить +30–50 мс к p95 на login.
- Файловые вложения и экспорт XLS/PDF в прототипе не реализованы —
  нагрузочный профиль для них не снят.