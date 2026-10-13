<?php
// Plays a whole bot-vs-bot game in-process from a given seed and prints a JSON summary.
// Used by GameSeedTest to show that the same seed, decks and plays give the same game.
//
// Usage: php tests/Engine/Fixtures/SeedGameRunner.php <seed> [maxSteps] [p1Deck] [p2Deck]
//   seed      Game seed, or "-" for a game without one (the pre-seed behaviour)
//   p1Deck    Deck file from Assets/ (default IraCC.txt); p2Deck defaults to FaiCC.txt

if (PHP_SAPI !== "cli") exit;

$runnerSeed = $argv[1] ?? "-";
$runnerMaxSteps = intval($argv[2] ?? 400);
$runnerDecks = [1 => basename($argv[3] ?? "IraCC.txt"), 2 => basename($argv[4] ?? "FaiCC.txt")];

// The engine keeps its state in globals and resolves includes from the game root.
chdir(dirname(__DIR__, 3));
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set("display_errors", "stderr");
ob_start();
include_once "WriteLog.php";
include_once "GameLogic.php";
include_once "GameTerms.php";
include_once "HostFiles/Redirector.php";
include_once "Libraries/SHMOPLibraries.php";
include_once "Libraries/StatFunctions.php";
include_once "Libraries/UILibraries.php";
include_once "Libraries/PlayerSettings.php";
include_once "Libraries/NetworkingLibraries.php";
include_once "Libraries/CacheLibraries.php";
include_once "AI/CombatDummy.php";
include_once "Libraries/HTTPLibraries.php";
include_once "Libraries/ReplayLibraries.php";
require_once "Libraries/CoreLibraries.php";
include_once "Libraries/ValidationLibraries.php";
include_once "Libraries/PuzzleVerify.php";
include_once "includes/dbh.inc.php";
include_once "includes/functions.inc.php";
include_once "MenuFiles/StartHelper.php";
include_once "Libraries/SnapshotLibraries.php";
ob_end_clean();

function RunnerFail($message)
{
  fwrite(STDERR, $message . "\n");
  exit(1);
}

do {
  $gameName = (string)random_int(800000000, 899999999);
  $filepath = "./Games/$gameName/";
} while (file_exists($filepath));
if (!@mkdir($filepath, 0700, true)) RunnerFail("Could not create $filepath");
register_shutdown_function(function () use ($gameName) {
  DeleteCache($gameName);
  foreach (glob("./Games/$gameName/*") ?: [] as $file) @unlink($file);
  @rmdir("./Games/$gameName");
});
$filename = $filepath . "gamestate.txt";
foreach ($runnerDecks as $player => $deckFile) {
  if (!copy("./Assets/$deckFile", $filepath . "p{$player}Deck.txt")) RunnerFail("Unknown deck $deckFile");
}
file_put_contents($filepath . "gamelog.txt", "");
file_put_contents($filepath . "GameFile.txt", "1\r\n2\r\n5\r\ncc\r\nprivate\r\n\r\n1\r\n");

// Same initial gamestate as Start.php, with both players run by the bot.
$p1id = $p2id = ""; // No account, so no saved settings are loaded from the database
$p1IsPatron = $p2IsPatron = "";
$p1IsAI = $p2IsAI = "1";
$p1Key = $p2Key = "runner";
$firstPlayer = 1;
$p1StartingHealth = "";
$memHandle = fopen("php://memory", "w+");
fwrite($memHandle, "20 20\r\n");
foreach ([1, 2] as $player) {
  $deckHandler = fopen($filepath . "p{$player}Deck.txt", "r");
  initializePlayerState($memHandle, $deckHandler, $player);
  fclose($deckHandler);
}
fwrite($memHandle, "\r\n0\r\n$firstPlayer\r\n1\r\n0\r\nM 1\r\n1\r\n\r\n"
  . "0 0 0 0 0 0 0 GY NA 0 0 0 0 0 0 0 NA 0 0 -1 -1 NA 0 0 0 -1 0 0 0 0 - 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 -1 0 0 0 0 0 0\r\n"
  . "\r\n\r\n\r\n\r\n0\r\n0 - - -\r\n\r\n\r\n1\r\n\r\n0\r\n\r\n$p1Key\r\n$p2Key\r\n0\r\n0\r\n\r\n0\r\n0\r\n0\r\n0\r\n0\r\n"
  . "0\r\n\r\n\r\n-\r\n" . implode(" ", $p1Inventory) . "\r\n" . implode(" ", $p2Inventory) . "\r\n$p1IsAI\r\n$p2IsAI\r\n");
rewind($memHandle);
$gamestate = stream_get_contents($memHandle);
fclose($memHandle);
file_put_contents($filename, $gamestate);
$now = round(microtime(true) * 1000);
WriteCache($gameName, "1!$now!$now!-1!-1!$now!!!0!0!0!0!cc!5!0!0");
WriteGamestateCache($gameName, $gamestate);

$playerID = 1;
$startingGameSeed = $runnerSeed === "-" ? "" : $runnerSeed;
ob_start();
include "ParseGamestate.php";
$gameSeed = $startingGameSeed;
$format = "cc";
$randomSeeded = false;
include "StartEffects.php";
ob_end_clean();

function RunnerZone($state, $line)
{
  return array_values(array_filter(explode(" ", trim($state[$line] ?? "")), fn($card) => $card !== ""));
}

function RunnerRandomProbe()
{
  // What the next 10 die rolls would be from this exact state, without changing the game.
  $GLOBALS["randomSeeded"] = false;
  $rolls = [];
  for ($i = 0; $i < 10; ++$i) $rolls[] = GetRandom(1, 6);
  $GLOBALS["randomSeeded"] = false;
  return $rolls;
}

$state = explode("\r\n", (string)$lastWrittenGamestate);
$opening = [
  "p1Hand" => RunnerZone($state, 1),
  "p2Hand" => RunnerZone($state, 19),
  "p1DeckTop" => array_slice(RunnerZone($state, 2), 0, 8),
  "p2DeckTop" => array_slice(RunnerZone($state, 20), 0, 8),
  "p1DeckHash" => md5(implode(" ", RunnerZone($state, 2))),
  "p2DeckHash" => md5(implode(" ", RunnerZone($state, 20))),
];

// The same gamestate with another seed must roll differently, or the seed would only matter for the opening shuffle.
$seedMixProbe = ["thisSeed" => RunnerRandomProbe()];
$gameSeed = $startingGameSeed . "-other";
$seedMixProbe["otherSeed"] = RunnerRandomProbe();
$gameSeed = $startingGameSeed;

// A snapshot saved now must roll differently from this game, and the same way every time it is opened.
function RunnerProbeGamestate($content)
{
  WriteGamestateCache($GLOBALS["gameName"], $content);
  ParseGamestate();
  return RunnerRandomProbe();
}
$liveGamestate = (string)$lastWrittenGamestate;
$liveLines = explode("\r\n", $liveGamestate);
$snapshotGamestate = implode("\r\n", ReseedSnapshotGamestate($liveLines, intval($liveLines[56]), GenerateGameSeed()));
$snapshotProbe = [
  "live" => RunnerProbeGamestate($liveGamestate),
  "snapshot" => RunnerProbeGamestate($snapshotGamestate),
  "snapshotReopened" => RunnerProbeGamestate($snapshotGamestate),
];
RunnerProbeGamestate($liveGamestate);

$stateHashes = [PuzzleStateKey($state)];
$probes = [];
$stalls = 0;
$steps = 0;
$stalled = false;
ob_start();
try {
  for (; $steps < $runnerMaxSteps && !IsGameOver(); ++$steps) {
    $player = intval($currentPlayer);
    $before = end($stateHashes);
    $state = PuzzleStep($player);
    if (PuzzleStateKey($state) === $before) {
      // The bot had nothing to do here: pass priority, as a player would.
      $state = PuzzleStep($player, ["99", "", "", 0, []]);
      if (PuzzleStateKey($state) === $before && ++$stalls > 20) {
        $stalled = true;
        break;
      }
    }
    $stateHashes[] = PuzzleStateKey($state);
    if (!IsGameOver()) {
      ParseGamestate();
      $probes[] = ["step" => $steps, "turn" => intval($currentTurn), "rolls" => RunnerRandomProbe()];
    }
  }
} catch (Throwable $e) {
  ob_end_clean();
  RunnerFail("Engine error at step $steps: " . $e->getMessage() . " (" . $e->getFile() . ":" . $e->getLine() . ")");
}
ob_end_clean();

ParseGamestate();
$log = array_values(array_filter(array_map(fn($line) => trim(strip_tags($line)),
  explode("\n", (string)@file_get_contents($filepath . "gamelog.txt"))), fn($line) => $line !== ""));
echo json_encode([
  "seed" => $runnerSeed,
  "decks" => $runnerDecks,
  "steps" => $steps,
  "turns" => intval($currentTurn),
  "gameOver" => IsGameOver(),
  "stopReason" => IsGameOver() ? "game_over" : ($stalled ? "stalled" : "max_steps"),
  "winner" => intval($winner),
  "health" => [intval(GetHealth(1)), intval(GetHealth(2))],
  "opening" => $opening,
  "probes" => $probes,
  "seedMixProbe" => $seedMixProbe,
  "snapshotProbe" => $snapshotProbe,
  "stateHashes" => $stateHashes,
  "finalStateHash" => end($stateHashes),
  "storedSeed" => $gameSeed,
  "log" => $log,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
