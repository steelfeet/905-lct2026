import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    parallel_reports_10: {
      executor: 'constant-vus',
      vus: 10,
      duration: '2m',
    },
  },
  thresholds: {
    // NFR: 10 параллельных отчётов разной сложности
    'http_req_duration{name:report}': ['p(95)<3000'],
    'http_req_failed':                ['rate<0.01'],
  },
};

const BASE = __ENV.BASE_URL || 'http://localhost:8080';

export default function () {
  // Один логин на VU (k6 сам хранит cookies)
  http.get(`${BASE}/index.php?action=oidc_login&login_hint=supervisor`);

  const period = [3, 6, 12][__ITER % 3];
  const res = http.get(
    `${BASE}/index.php?role=supervisor&period=${period}`,
    { tags: { name: 'report' } }
  );

  check(res, {
    'report 200':       (r) => r.status === 200,
    'report under 3s':  (r) => r.timings.duration < 3000,
  });
  sleep(2);
}