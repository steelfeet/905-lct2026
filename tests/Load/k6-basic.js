import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate } from 'k6/metrics';

const loginFailures = new Rate('login_failures');

export const options = {
  scenarios: {
    concurrent_users_50: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: 25 },
        { duration: '30s', target: 50 },
        { duration: '2m',  target: 50 },
        { duration: '30s', target: 0  },
      ],
    },
  },
  thresholds: {
    // NFR: отклик < 1 с на ключевых операциях
    'http_req_duration{name:main_page}':     ['p(95)<1000'],
    'http_req_duration{name:dashboard}':     ['p(95)<1000'],
    'http_req_duration{name:workflow_page}': ['p(95)<1000'],
    'http_req_duration{name:move_phase}':    ['p(95)<1000'],
    // NFR: 50 параллельных пользователей
    'vus':                                   ['value>=50'],
    // Надёжность
    'http_req_failed':                       ['rate<0.01'],
    loginFailures:                           ['rate<0.01'],
  },
};

const BASE = __ENV.BASE_URL || 'http://localhost:8080';
const ROLES = ['manager', 'supervisor', 'university', 'student', 'parent'];

export default function () {
  const role = ROLES[__VU % ROLES.length];

  // 1. Логин через OIDC stub
  group('login', () => {
    const res = http.get(
      `${BASE}/index.php?action=oidc_login&login_hint=${role}`,
      { redirects: 5, tags: { name: 'login' } }
    );
    const ok = check(res, {
      'login status 200':   (r) => r.status === 200,
      'session cookie set': (r) => r.cookies.some((c) => c.name === 'PHPSESSID'),
    });
    loginFailures.add(!ok);
    if (!ok) return;
  });
  sleep(1);

  // 2. Главная страница роли
  group('main_page', () => {
    const res = http.get(
      `${BASE}/index.php?role=${role}`,
      { tags: { name: 'main_page' } }
    );
    check(res, {
      'main 200':        (r) => r.status === 200,
      'main under 1s':   (r) => r.timings.duration < 1000,
    });
  });
  sleep(2);

  // 3. Дашборд руководителя (самая тяжёлая страница)
  if (role === 'supervisor') {
    group('dashboard', () => {
      const res = http.get(
        `${BASE}/index.php?role=supervisor`,
        { tags: { name: 'dashboard' } }
      );
      check(res, {
        'dash 200':        (r) => r.status === 200,
        'dash under 1s':   (r) => r.timings.duration < 1000,
      });
    });
    sleep(3);
  }

  // 4. Workflow менеджера + смена статуса (NFR: <1 с, без перезагрузки)
  if (role === 'manager') {
    group('workflow_page', () => {
      const res = http.get(
        `${BASE}/index.php?role=manager`,
        { tags: { name: 'workflow_page' } }
      );
      check(res, { 'wf 200': (r) => r.status === 200 });
    });
    sleep(2);

    group('move_phase', () => {
      const payload = JSON.stringify({
        project_id: 1,
        target_phase_id: 2,
      });
      const res = http.post(
        `${BASE}/index.php?action=move_phase`,
        payload,
        {
          headers: { 'Content-Type': 'application/json' },
          tags: { name: 'move_phase' },
        }
      );
      check(res, {
        'move 200':      (r) => r.status === 200,
        'move under 1s': (r) => r.timings.duration < 1000,
        'move no full reload': (r) => {
          // fetch-ответ не должен содержать HTML-обёртку страницы
          return !r.body.includes('<!DOCTYPE html>');
        },
        'move ok flag': (r) => {
          try { return JSON.parse(r.body).ok === true; }
          catch { return false; }
        },
      });
    });
  }
  sleep(2);
}