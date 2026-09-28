<?php

namespace LagerApp\Tests;

use PHPUnit\Framework\TestCase;

/**
 * appVersion() in einem eigenen Git-Repository: letzter Release-Tag,
 * bei weiteren Commits ergänzt um Datum und Uhrzeit des letzten Commits.
 */
class AppVersionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sanlager-version-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);

        $this->git('init -q');
        $this->commit('2026-09-28T14:32:05+02:00');
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

    private function commit(string $date): void
    {
        $this->git('commit -q --allow-empty -m Test', 'GIT_COMMITTER_DATE=' . escapeshellarg($date));
    }

    public function testWithoutReleaseShowsCommitTime(): void
    {
        $this->assertSame('Stand 28.09.2026, 14:32', appVersion($this->dir));
    }

    public function testReleaseShowsVersionOnly(): void
    {
        $this->git('tag v0.5.0');

        $this->assertSame('Version 0.5.0', appVersion($this->dir));
    }

    public function testPushAfterReleaseAddsCommitTime(): void
    {
        $this->git('tag v0.5.0');
        $this->commit('2026-10-01T09:15:00+02:00');

        $this->assertSame('Version 0.5.0 + Stand 01.10.2026, 09:15', appVersion($this->dir));
    }

    public function testHighestVersionWinsAndOtherTagsAreIgnored(): void
    {
        // Wie bei SanLager: v0.3.0 und v0.4.0 auf demselben Commit.
        $this->git('tag v0.3.0');
        $this->git('tag v0.4.0');
        $this->git('tag v0.10.0-rc1');
        $this->git('tag test');

        $this->assertSame('Version 0.4.0', appVersion($this->dir));
    }
}
