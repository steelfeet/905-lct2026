<?php
declare(strict_types=1);

final class AuthFlowTest extends FunctionalTestCase
{
    public function testLoginRedirectsToProvider(): void
    {
        $res = $this->http->get('/index.php', [
            'query' => ['action' => 'oidc_login'],
            'allow_redirects' => false,
        ]);
        // stub вернёт 302 на callback, реальный Keycloak — 302 на свой URL
        $this->assertContains($res->getStatusCode(), [302, 303]);
        $loc = $res->getHeaderLine('Location');
        $this->assertNotSame('', $loc);
    }

    public function testLoginSuccessfullySetsSession(): void
    {
        $this->login('manager');
        $res = $this->http->get('/index.php', ['query' => ['role' => 'manager']]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('ИТ Школа РТК', (string) $res->getBody());
        $this->assertStringContainsString('Рабочий процесс', (string) $res->getBody());
    }

    public function testLoginUnknownUserFails(): void
    {
        $res = $this->http->get('/index.php', [
            'query' => ['action' => 'oidc_login', 'login_hint' => 'nonexistent_user'],
        ]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('не найден', (string) $res->getBody());
    }

    public function testLogoutClearsSession(): void
    {
        $this->login('manager');
        $this->http->get('/index.php', ['query' => ['logout' => '1']]);
        $res = $this->http->get('/index.php');
        // Разлогинен — снова экран входа
        $this->assertStringContainsString('вход в систему', (string) $res->getBody());
    }

    public function testUnauthenticatedShowsLogin(): void
    {
        $res = $this->http->get('/index.php');
        $body = (string) $res->getBody();
        $this->assertStringContainsString('Keycloak', $body);
        $this->assertStringNotContainsString('Рабочий процесс', $body);
    }
}