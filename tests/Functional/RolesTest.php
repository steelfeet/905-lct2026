<?php
declare(strict_types=1);

final class RolesTest extends FunctionalTestCase
{
    public function testEachRoleRendersOwnWorkspace(): void
    {
        $expected = [
            'manager'    => 'Рабочий процесс',
            'supervisor' => 'Дашборд руководителя',
            'university' => 'Рабочий процесс вуза',
            'student'    => 'Мои проекты',
            'parent'     => 'Журнал',
        ];
        foreach ($expected as $hint => $needle) {
            $this->setUp();           // свежая сессия
            $this->login($hint);
            $res = $this->http->get('/index.php', ['query' => ['role' => $hint]]);
            $this->assertSame(200, $res->getStatusCode());
            $this->assertStringContainsString($needle, (string) $res->getBody(),
                "role=$hint не отрендерил ожидаемый workspace");
        }
    }

    public function testRoleWhitelistFiltersUnknownRole(): void
    {
        $this->login('manager');
        $res = $this->http->get('/index.php', ['query' => ['role' => 'hacker']]);
        $this->assertSame(200, $res->getStatusCode());
        // viewRole fallback → manager, но "hacker" в теле быть не должно
        $this->assertStringNotContainsString('role="hacker"', (string) $res->getBody());
    }
}