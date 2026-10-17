import http from 'k6/http';
import { check } from 'k6';

export const options = { vus: 1, iterations: 1 };

const BASE = __ENV.BASE_URL || 'http://localhost:8080';

export default function () {
  const res = http.get(`${BASE}/index.php`);
  check(res, {
    'app responds':      (r) => r.status === 200,
    'login form present': (r) => r.body.includes('Keycloak'),
  });
}