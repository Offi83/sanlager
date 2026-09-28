<?php

namespace LagerApp\Tests;

use PHPUnit\Framework\TestCase;

/**
 * script/version.sh in einem eigenen Git-Repository: Release-Tag oder
 * Commit-Datum landen in VERSION und passen zu appVersion().
 */
class VersionScriptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sanlager-version-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/script', 0777, true);
        copy(dirname(__DIR__) . '/script/version.sh', $this->dir . '/script/version.sh');
        chmod($this->dir . '/script/version.sh', 0755);

        $this->git('init -q');
        file_put_contents($this->dir . '/README.md', "Test\n");
        $this->git('add README.md');
        $this->git('commit -q -m Test', 'GIT_COMMITTER_DATE="2026-09-28T14:32:05+02:00"');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function git(string $args, string $env = ''): void
    {
        exec(sprintf('cd %s && %s git -c user.name=Test -c user.email=test@example.org %s 2>&1', escapeshellarg($this->dir), $env, $args), $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    private function runScript(): string
    {
        exec(escapeshellarg($this->dir . '/script/version.sh') . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));

        return $this->dir . '/VERSION';
    }

    public function testIntermediateStateShowsCommitTime(): void
    {
        $this->assertSame('Stand 28.09.2026, 14:32', appVersion($this->runScript()));
    }

    public function testReleaseTagShowsVersion(): void
    {
        $this->git('tag v0.5.0');

        $this->assertSame('Version 0.5.0', appVersion($this->runScript()));
    }

    public function testCommitAfterReleaseIsIntermediateStateAgain(): void
    {
        $this->git('tag v0.5.0');
        $this->git('commit -q --allow-empty -m Weiter', 'GIT_COMMITTER_DATE="2026-10-01T09:15:00+02:00"');

        $this->assertSame('Stand 01.10.2026, 09:15', appVersion($this->runScript()));
    }
}
