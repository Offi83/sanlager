<?php

namespace LagerApp\Tests;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypeError;

/**
 * Schutz vor fremd ausgelösten Anfragen (CSRF) und vor technischen
 * Fehlermeldungen für Nutzer, siehe isSameOriginRequest()/userMessage().
 */
class RequestSecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['APP_DEBUG']);
    }

    public static function origins(): array
    {
        return [
            'Origin passt' => [['HTTP_HOST' => 'lager.example.org', 'HTTP_ORIGIN' => 'https://lager.example.org'], true],
            'Origin mit Port' => [['HTTP_HOST' => 'localhost:8080', 'HTTP_ORIGIN' => 'http://localhost:8080'], true],
            'Groß/klein egal' => [['HTTP_HOST' => 'Lager.Example.org', 'HTTP_ORIGIN' => 'https://lager.example.ORG'], true],
            'nur Referer' => [['HTTP_HOST' => 'lager.example.org', 'HTTP_REFERER' => 'https://lager.example.org/?page=issue'], true],
            'fremde Seite' => [['HTTP_HOST' => 'lager.example.org', 'HTTP_ORIGIN' => 'https://evil.example.com'], false],
            'Subdomain-Trick' => [['HTTP_HOST' => 'lager.example.org', 'HTTP_ORIGIN' => 'https://lager.example.org.evil.com'], false],
            'anderer Port' => [['HTTP_HOST' => 'localhost:8080', 'HTTP_ORIGIN' => 'http://localhost:9999'], false],
            'Origin null' => [['HTTP_HOST' => 'lager.example.org', 'HTTP_ORIGIN' => 'null'], false],
            'ohne Herkunft' => [['HTTP_HOST' => 'lager.example.org'], false],
            'fremder Referer' => [['HTTP_HOST' => 'lager.example.org', 'HTTP_REFERER' => 'https://evil.example.com/lager.example.org'], false],
        ];
    }

    #[DataProvider('origins')]
    public function testIsSameOriginRequest(array $server, bool $expected): void
    {
        $this->assertSame($expected, isSameOriginRequest($server));
    }

    public function testUserErrorsAreShownTechnicalErrorsHidden(): void
    {
        $this->assertSame(
            'Nicht genügend Bestand dieser Charge an diesem Lagerort.',
            userMessage(new RuntimeException('Nicht genügend Bestand dieser Charge an diesem Lagerort.'))
        );

        $logged = ini_set('error_log', '/dev/null');

        try {
            $sql = userMessage(new PDOException('SQLSTATE[HY000]: no such column: foo in SELECT ...'));
            $type = userMessage(new TypeError('Argument #1 must be of type int'));
            $locked = userMessage(new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));

            $_ENV['APP_DEBUG'] = 'true';
            $debug = userMessage(new PDOException('no such column: foo'));
        } finally {
            ini_set('error_log', (string) $logged);
        }

        $this->assertStringContainsString('technischer Fehler', $sql);
        $this->assertStringNotContainsString('SQLSTATE', $sql);
        $this->assertStringContainsString('technischer Fehler', $type);
        $this->assertStringContainsString('gerade beschäftigt', $locked);
        $this->assertStringContainsString('no such column', $debug);
    }
}
