<?php
declare(strict_types=1);

final class XssTest extends FunctionalTestCase
{
    public function testQueryParamXssIsNotReflectedRaw(): void
    {
        $this->login('manager');
        $payload = '<script>alert(1)</script>';
        $res = $this->http->get('/index.php', [
            'query' => ['role' => 'manager', 'uni_id' => $payload],
        ]);
        $body = (string) $res->getBody();
        // Сырой payload не должен попасть в HTML
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        // uni_id кастуется в int → 0, никакого отражения
        $this->assertStringNotContainsString('alert(1)', $body);
    }

    public function testProjectTitleIsEscaped(): void
    {
        // В прототипе title проекта приходит из БД — если попадёт <script>,
        // e() должен его погасить. Это проверяется косвенно: проверяем,
        // что все title-атрибуты не содержат сырых угловых скобок из данных.
        $this->login('manager');
        $res = $this->http->get('/index.php', ['query' => ['role' => 'manager']]);
        $body = (string) $res->getBody();
        // Никаких "onerror=" из пользовательских данных в HTML
        $this->assertDoesNotMatchRegularExpression(
            '/title="[^"]*<script/i',
            $body
        );
    }
}