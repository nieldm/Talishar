<?php

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/Libraries/SnapshotLibraries.php';

final class SnapshotSeedTest extends TestCase
{
    // A gamestate as WriteGamestate leaves it: lines 0..(92 + chain links) and the empty entry after the last line break.
    private function Gamestate(int $chainLinks, ?string $seed): array
    {
        $lines = array_map(fn($i) => "line-$i", range(0, 91 + $chainLinks));
        $lines[56] = (string)$chainLinks;
        if ($seed !== null) $lines[] = $seed;
        $lines[] = "";
        return $lines;
    }

    public function testReplacesTheSeedAndNothingElse(): void
    {
        foreach ([0, 3] as $chainLinks) {
            $live = $this->Gamestate($chainLinks, 'live-seed');
            $snapshot = ReseedSnapshotGamestate($live, $chainLinks, 'snapshot-seed');

            $this->assertCount(count($live), $snapshot);
            $this->assertSame('snapshot-seed', $snapshot[92 + $chainLinks]);
            unset($live[92 + $chainLinks], $snapshot[92 + $chainLinks]);
            $this->assertSame($live, $snapshot);
        }
    }

    public function testAddsASeedToGamestatesSavedBeforeSeedsExisted(): void
    {
        $legacy = $this->Gamestate(2, null);
        $snapshot = ReseedSnapshotGamestate($legacy, 2, 'snapshot-seed');

        $this->assertSame(array_slice($legacy, 0, 94), array_slice($snapshot, 0, 94));
        $this->assertSame('snapshot-seed', $snapshot[94]);
        $this->assertSame('', end($snapshot));
        $this->assertStringEndsWith("\r\nsnapshot-seed\r\n", implode("\r\n", $snapshot));
    }
}
