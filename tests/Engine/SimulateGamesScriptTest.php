<?php

use PHPUnit\Framework\TestCase;

final class SimulateGamesScriptTest extends TestCase
{
    private function RunScript(string $arguments, ?int &$exitCode = null): ?array
    {
        $report = tempnam(sys_get_temp_dir(), 'sim');
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/scripts/simulate_games.php')
            . " $arguments --json=" . escapeshellarg($report) . ' 2>&1';
        exec($command, $output, $exitCode);
        $json = json_decode((string)@file_get_contents($report), true);
        @unlink($report);
        return $json;
    }

    public function testReportsFinishedGamesAndReplaysEachSeed(): void
    {
        $report = $this->RunScript('--seeds=script-a,script-b --check-determinism --jobs=4', $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, $report['summary']['games']);
        $this->assertSame(2, $report['summary']['finished'] + $report['summary']['stalled'] + $report['summary']['tooLong']);
        $this->assertSame([], $report['summary']['errors']);
        $this->assertSame([], $report['summary']['notReplayed']);
        $this->assertCount(4, $report['games']);
    }

    public function testRejectsUnknownDecks(): void
    {
        $report = $this->RunScript('--p1=NotADeck.txt', $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertNull($report);
    }
}
