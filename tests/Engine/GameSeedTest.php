<?php

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/Libraries/CoreLibraries.php';

final class GameSeedTest extends TestCase
{
    private static array $games = [];

    // Whole bot-vs-bot games, each in its own PHP process so the engine's globals start clean.
    public static function PlayGame(string $seed): array
    {
        if (isset(self::$games[$seed])) return self::$games[$seed];
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/Fixtures/SeedGameRunner.php') . ' ' . escapeshellarg($seed);
        $output = shell_exec($command . ' 2>/dev/null');
        $game = json_decode((string)$output, true);
        if (!is_array($game)) self::fail("The game runner failed for seed $seed");
        return self::$games[$seed] = $game;
    }

    protected function setUp(): void
    {
        $GLOBALS['gameSeed'] = '';
        $GLOBALS['randomSeeded'] = false;
    }

    private function InitialShuffle(string $seed, int $player): array
    {
        $GLOBALS['gameSeed'] = $seed;
        SeedRandomForInitialShuffle($player);
        $deck = range(1, 40);
        RandomShuffle($deck);
        $GLOBALS['randomSeeded'] = false;
        return $deck;
    }

    public function testNormalizeGameSeedKeepsValidSeedsAndReplacesOthers(): void
    {
        $this->assertSame('my-Seed_42', NormalizeGameSeed(' my-Seed_42 '));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', NormalizeGameSeed(''));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', NormalizeGameSeed("bad seed\r\n"));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', NormalizeGameSeed(str_repeat('a', 65)));
        $this->assertNotSame(GenerateGameSeed(), GenerateGameSeed());
    }

    public function testInitialShuffleFollowsTheSeed(): void
    {
        $this->assertSame($this->InitialShuffle('abc123', 1), $this->InitialShuffle('abc123', 1));
        $this->assertNotSame($this->InitialShuffle('abc123', 1), $this->InitialShuffle('xyz789', 1));
        $this->assertNotSame($this->InitialShuffle('abc123', 1), $this->InitialShuffle('abc123', 2));
    }

    public function testSeedFromGameSeedRepeatsAndStaysUnseededWithoutOne(): void
    {
        SeedRandomFromGameSeed('abc123', 'first-player-roll');
        $first = [mt_rand(1, 6), mt_rand(1, 6), mt_rand(1, 6)];
        SeedRandomFromGameSeed('abc123', 'first-player-roll');
        $this->assertSame($first, [mt_rand(1, 6), mt_rand(1, 6), mt_rand(1, 6)]);
        mt_srand();
    }

    public function testSameSeedPlaysTheSameGame(): void
    {
        $first = self::PlayGame('abc123');
        $second = $this->ReplayGame('abc123');

        $this->assertTrue($first['gameOver']);
        $this->assertSame('abc123', $first['storedSeed']);
        $this->assertSame($first['opening'], $second['opening']);
        $this->assertSame($first['probes'], $second['probes']);
        $this->assertSame($first['stateHashes'], $second['stateHashes']);
        $this->assertSame($first['log'], $second['log']);
        $this->assertSame($first['winner'], $second['winner']);
    }

    public function testDifferentSeedsPlayDifferentGames(): void
    {
        $first = self::PlayGame('abc123');
        $other = self::PlayGame('xyz789');

        $this->assertNotSame($first['opening']['p1DeckHash'], $other['opening']['p1DeckHash']);
        $this->assertNotSame($first['finalStateHash'], $other['finalStateHash']);
    }

    public function testSeedChangesRollsFromTheSameGamestate(): void
    {
        $probe = self::PlayGame('abc123')['seedMixProbe'];
        $this->assertNotSame($probe['thisSeed'], $probe['otherSeed']);
    }

    public function testGamesWithoutSeedStayRandom(): void
    {
        $first = $this->ReplayGame('-');
        $second = $this->ReplayGame('-');
        $this->assertSame('', $first['storedSeed']);
        $this->assertNotSame($first['opening']['p1DeckHash'] . $first['opening']['p2DeckHash'],
            $second['opening']['p1DeckHash'] . $second['opening']['p2DeckHash']);
    }

    private function ReplayGame(string $seed): array
    {
        unset(self::$games[$seed]);
        return self::PlayGame($seed);
    }
}
