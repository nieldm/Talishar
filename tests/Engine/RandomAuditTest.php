<?php

use PHPUnit\Framework\TestCase;

// Game outcomes (shuffles, rolls, damage) must flow through GetRandom()/RandomShuffle() in
// Libraries/CoreLibraries.php so a per-game seed makes them reproducible. A direct mt_rand(),
// rand(), shuffle() or array_rand() call in game code breaks replays silently - git has no way
// to flag it, so this test does. Add a new call site to ALLOWLIST only after confirming it is
// not a seeded game outcome (e.g. a session/auth key), with a one-line reason.
final class RandomAuditTest extends TestCase
{
    // Path relative to repo root => number of raw calls already reviewed there.
    private const ALLOWLIST = [
        'Libraries/CoreLibraries.php' => 2, // GetRandom()'s own mt_rand() calls
        'CreateReplayGame.php' => 5,        // session key hash, not a game outcome
        'APIs/CreateGame.php' => 5,         // session key hash, not a game outcome
        'APIs/CreateReplayGame.php' => 5,   // session key hash, not a game outcome
        'APIs/JoinGame.php' => 7,           // session key hash + first-player roll (already seeded via SeedRandomFromGameSeed)
    ];

    public function testRawRandomCallsAreLimitedToTheKnownAllowlist(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') continue;
            $relative = str_replace($root . '/', '', $file->getPathname());
            if (str_starts_with($relative, 'vendor/') || str_starts_with($relative, 'tests/')) continue;
            $matches = [];
            preg_match_all('/\b(?:mt_rand|rand|shuffle|array_rand)\(/', (string)file_get_contents($file->getPathname()), $matches);
            if ($matches[0]) $found[$relative] = count($matches[0]);
        }

        // Sort both by key: directory traversal order isn't a property worth pinning down,
        // and assertSame on arrays is order-sensitive.
        $allowlist = self::ALLOWLIST;
        ksort($allowlist);
        ksort($found);
        $this->assertSame($allowlist, $found,
            'New raw mt_rand()/rand()/shuffle()/array_rand() call(s) found outside the allowlist. ' .
            'Route game outcomes through GetRandom()/RandomShuffle() (Libraries/CoreLibraries.php) ' .
            'so they stay reproducible under a game seed; non-game call sites belong in self::ALLOWLIST with a reason.'
        );
    }
}
