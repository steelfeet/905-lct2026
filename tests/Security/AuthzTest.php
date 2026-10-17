<?php
declare(strict_types=1);

final class AuthzTest extends FunctionalTestCase
{
    public function testAllHandlersRejectUnauthenticatedMutation(): void
    {
        foreach (['move_phase', 'confirm_phase', 'reject_phase'] as $action) {
            $this->setUp();
            $res = $this->http->post('/index.php', [
                'query' => ['action' => $action],
                'json'  => ['project_id' => 1, 'target_phase_id' => 2],
            ]);
            $body = (string) $res->getBody();
            $this->assertStringContainsString('вход в систему', $body,
                "action=$action должен требовать авторизации");
        }
    }

    public function testSqlInjectionInUniIdIsCastToInt(): void
    {
        $this->login('university');
        $res = $this->http->get('/index.php', [
            'query' => ['role' => 'university', 'uni_id' => "1 OR 1=1; DROP TABLE users;--"],
        ]);
        $this->assertSame(200, $res->getStatusCode());
        // Приложение живо
        $this->assertStringContainsString('Рабочий процесс вуза', (string) $res->getBody());
    }

    public function testSessionIdRegeneratedOnLogin(): void
    {
        // Заходим как гость
        $res = $this->http->get('/index.php');
        $cookies = $this->http->getConfig('cookies');
        $before = (string) $cookies;
        preg_match('/PHPSESSID=([a-z0-9]+)/i', $before, $m1);

        // Логинимся
        $this->login('manager');

        // Забираем cookie jar после логина
        $after = (string) $this->http->getConfig('cookies');
        preg_match('/PHPSESSID=([a-z0-9]+)/i', $after, $m2);

        // session_regenerate_id(true) → id меняется
        $this->assertNotSame($m1[1] ?? '', $m2[1] ?? '',
            'Session ID должен меняться после логина (session fixation)');
    }
}