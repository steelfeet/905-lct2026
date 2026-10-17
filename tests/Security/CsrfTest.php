<?php
declare(strict_types=1);

final class CsrfTest extends FunctionalTestCase
{
    public function testCallbackWithWrongStateIsRejected(): void
    {
        $res = $this->http->get('/index.php', [
            'query' => [
                'action' => 'oidc_callback',
                'code'   => 'fake',
                'state'  => 'wrong-state',
            ],
        ]);
        $body = (string) $res->getBody();
        $this->assertStringContainsString('state mismatch', $body);
    }

    public function testCallbackWithoutStateIsRejected(): void
    {
        $res = $this->http->get('/index.php', [
            'query' => [
                'action' => 'oidc_callback',
                'code'   => 'fake',
            ],
        ]);
        $body = (string) $res->getBody();
        $this->assertStringContainsString('state mismatch', $body);
    }

    public function testMovePhaseRequiresSession(): void
    {
        // Без логина — сервер должен вернуть страницу входа, а не JSON
        $res = $this->http->post('/index.php', [
            'query' => ['action' => 'move_phase'],
            'json'  => ['project_id' => 1, 'target_phase_id' => 2],
        ]);
        $body = (string) $res->getBody();
        $this->assertStringContainsString('вход в систему', $body);
    }
}