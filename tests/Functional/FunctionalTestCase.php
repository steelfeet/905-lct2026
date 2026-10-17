<?php
declare(strict_types=1);
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

abstract class FunctionalTestCase extends TestCase
{
    protected static string $baseUrl;
    protected Client $http;

    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = getenv('BASE_URL') ?: 'http://127.0.0.1:8080';
    }

    protected function setUp(): void
    {
        $this->http = new Client([
            'base_uri'        => self::$baseUrl,
            'http_errors'     => false,
            'cookies'         => true,            // своя сессия на каждый тест
            'allow_redirects' => ['max' => 5, 'strict' => false],
        ]);
    }

    protected function login(string $loginHint): void
    {
        $res = $this->http->get('/index.php', [
            'query' => ['action' => 'oidc_login', 'login_hint' => $loginHint],
        ]);
        $this->assertSame(200, $res->getStatusCode(), 'login failed for ' . $loginHint);
    }
}