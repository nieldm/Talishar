<?php

declare(strict_types=1);

/**
 * End-to-end local match harness: two synthetic users play a full game on the
 * local stack, driven only through the HTTP API.
 *
 * Run inside the web container so it uses the same PHP build as the server:
 *
 *   docker compose -f docker-compose.local.yml exec -T -w /var/www/html/game \
 *     web-server php scripts/local/play_local_match.php --json > match-report.json
 *
 * Flow (what the frontend does, minus the browser):
 *   1. AccountFiles/SignupAPI.php creates the two synthetic users (re-runs
 *      reuse existing users).
 *   2. Games/<n>/ is written the way CreateGame.php + JoinGame.php write it for
 *      a private game, but with decks taken from Assets/*.txt instead of a
 *      deckbuilder link, so no third-party service is involved.
 *   3. APIs/ChooseFirstPlayer.php and Start.php start the game over HTTP.
 *   4. Every step after that goes through ProcessInputAPI.php; both seats are
 *      flagged as AI (p1IsAI/p2IsAI), so the engine's own bot (EncounterAI)
 *      plays them, the same way the practice-dummy path plays player 2.
 *   5. The loop stops on game over, on a stall, or at --max-ticks.
 *
 * Options (all optional):
 *   --p1-deck=Assets/IraCC.txt   deck file for player 1
 *   --p2-deck=Assets/FaiCC.txt   deck file for player 2
 *   --base-url=http://localhost/game
 *   --users=localp1,localp2      synthetic account names
 *   --format=cc
 *   --max-ticks=1500
 *   --stall-ticks=40             stop when the board does not change for N ticks
 *   --json                       print the report as JSON (default: text + JSON)
 */

$opts = getopt("", [
  "p1-deck:", "p2-deck:", "base-url:", "users:", "format:",
  "max-ticks:", "stall-ticks:", "json",
]);

$p1Deck = $opts["p1-deck"] ?? "Assets/IraCC.txt";
$p2Deck = $opts["p2-deck"] ?? "Assets/FaiCC.txt";
$baseUrl = rtrim($opts["base-url"] ?? "http://localhost/game", "/");
$users = explode(",", $opts["users"] ?? "localp1,localp2");
$format = $opts["format"] ?? "cc";
$maxTicks = (int)($opts["max-ticks"] ?? 1500);
$stallTicks = (int)($opts["stall-ticks"] ?? 40);
$jsonOnly = isset($opts["json"]);

$root = dirname(__DIR__, 2);
chdir($root);

// CLI has no request context; IsDevEnvironment()/includes expect these.
$_SERVER["SERVER_NAME"] = "localhost";
$_SERVER["HTTP_HOST"] = "localhost";

function fail(string $message): never
{
  fwrite(STDERR, "ERROR: $message\n");
  exit(1);
}

function httpJson(string $method, string $url, ?array $body = null): array
{
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_CUSTOMREQUEST => $method,
  ]);
  if ($body !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
  }
  $raw = curl_exec($ch);
  if ($raw === false) {
    $err = curl_error($ch);
    curl_close($ch);
    return [0, ["error" => "curl: $err"]];
  }
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  $decoded = json_decode((string)$raw, true);
  return [$code, is_array($decoded) ? $decoded : ["raw" => $raw]];
}

// ---------------------------------------------------------------- sanity check

[$code] = httpJson("GET", "$baseUrl/GetNextTurn.php?gameName=1&playerID=1");
if ($code !== 200) {
  fail("web server not answering on $baseUrl (HTTP $code). Run scripts/local/start_env.sh first.");
}

// ------------------------------------------------------------- 1. users

include_once "includes/dbh.inc.php";
include_once "includes/functions.inc.php";

$conn = GetDBConnection();
if (!$conn) {
  fail("cannot reach the database (MYSQL_SERVER_NAME=" . getenv("MYSQL_SERVER_NAME") . ")");
}

function ensureSyntheticUser($conn, string $baseUrl, string $user): void
{
  if (uidExists($conn, $user) !== false) return;

  [$code, $response] = httpJson("POST", "$baseUrl/AccountFiles/SignupAPI.php", [
    "userId" => $user,
    "email" => "$user@example.com",
    "password" => "local-match-password",
    "passwordRepeat" => "local-match-password",
  ]);
  if ($code !== 200 || ($response["message"] ?? "") !== "Success!") {
    fail("signup for $user failed (HTTP $code): " . json_encode($response));
  }
}

foreach ($users as $user) {
  if (!ctype_alnum($user)) fail("usernames must be alphanumeric: $user");
  ensureSyntheticUser($conn, $baseUrl, $user);
}
[$user1, $user2] = $users;

$userId = function (string $user) use ($conn): int {
  $row = uidExists($conn, $user);
  if ($row === false) fail("user $user not found after signup");
  return (int)$row["usersId"];
};
$p1Id = $userId($user1);
$p2Id = $userId($user2);

// ------------------------------------------------------------- 2. game files

include_once "Libraries/SHMOPLibraries.php";
include_once "Libraries/FormatCodes.php";
include_once "MenuFiles/WriteGamefile.php";

function heroFromDeck(string $deckFile): string
{
  $line = (string)fgets(fopen($deckFile, "r"));
  return explode(" ", trim($line))[0];
}

$p1DeckPath = $root . "/" . $p1Deck;
$p2DeckPath = $root . "/" . $p2Deck;
foreach ([$p1DeckPath, $p2DeckPath] as $deckFile) {
  if (!is_file($deckFile)) fail("deck file not found: $deckFile");
}

// Same allocation as Libraries/HTTPLibraries.php GetGameCounter().
$counterFile = $root . "/HostFiles/GameIDCounter.txt";
$counterHandle = fopen($counterFile, "r+");
flock($counterHandle, LOCK_EX);
$gameName = (string)intval(fgets($counterHandle));
ftruncate($counterHandle, 0);
rewind($counterHandle);
fwrite($counterHandle, (string)($gameName + 1));
flock($counterHandle, LOCK_UN);
fclose($counterHandle);

$gameDir = $root . "/Games/" . $gameName;
if (!mkdir($gameDir, 0700, true)) fail("cannot create $gameDir");
copy($p1DeckPath, "$gameDir/p1Deck.txt");
copy($p2DeckPath, "$gameDir/p2Deck.txt");
copy($p1DeckPath, "$gameDir/p1DeckOrig.txt");
copy($p2DeckPath, "$gameDir/p2DeckOrig.txt");
file_put_contents("$gameDir/gamelog.txt", "");

// Same fields CreateGame.php writes for a private game; the MGS_* values are
// globals from APIs/APIParseGamefile.php (2 = choose first player).
$gameStatus = 2;
$p1Data = [1];
$p2Data = [2];
$visibility = "private";
$firstPlayerChooser = 1;
$firstPlayer = 1;
$p1Key = bin2hex(random_bytes(32));
$p2Key = bin2hex(random_bytes(32));
$p1uid = $user1;
$p2uid = $user2;
$p1id = (string)$p1Id;
$p2id = (string)$p2Id;
$gameDescription = "Local match (scripts/local/play_local_match.php)";
$hostIP = "127.0.0.1";
$joinerIP = "127.0.0.1";
$p1IsPatron = "";
$p2IsPatron = "";
$p1DeckLink = "local:" . $p1Deck;
$p2DeckLink = "local:" . $p2Deck;
$p1IsChallengeActive = "0";
$p2IsChallengeActive = "0";
$p1Matchups = null;
$p2Matchups = null;
$p1deckbuilderID = "-";
$p2deckbuilderID = "-";
$p1StartingHealth = "";
$p1ContentCreatorID = "";
$p2ContentCreatorID = "";
$p1SideboardSubmitted = "1";
$p2SideboardSubmitted = "1";
$p1EquipmentSubmitted = "1";
$p2EquipmentSubmitted = "1";
$p1StartingEquipment = null;
$p2StartingEquipment = null;
$p1IsAI = "1";
$p2IsAI = "1";
$gameGUID = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
$p1MetafyTiers = [];
$p2MetafyTiers = [];
$p1MetafyCommunities = [];
$p2MetafyCommunities = [];
$p1DisplayName = $user1;
$p2DisplayName = $user2;

$gameFileHandler = fopen("$gameDir/GameFile.txt", "w");
WriteGameFile();

// Cache layout described in Libraries/SHMOPLibraries.php (1-based pieces).
$now = round(microtime(true) * 1000);
WriteCache($gameName, implode("!", [1, $now, $now, "0", "-1", $now, "", "", 0, 0, 0, 0, FormatCode($format), $gameStatus, 0, 0]));
SetCachePieces($gameName, [
  7 => heroFromDeck($p1DeckPath),
  8 => heroFromDeck($p2DeckPath),
  14 => $gameStatus,
]);

// ------------------------------------------------- 3. start through the API

[$code, $response] = httpJson("POST", "$baseUrl/APIs/ChooseFirstPlayer.php", [
  "gameName" => $gameName,
  "playerID" => 1,
  "authKey" => $p1Key,
  "action" => "Go First",
]);
if ($code !== 200 || ($response["success"] ?? false) !== true) {
  fail("ChooseFirstPlayer failed (HTTP $code): " . json_encode($response));
}

[$code, $response] = httpJson("GET", "$baseUrl/Start.php?gameName=$gameName&playerID=1");
if ($code !== 200 || ($response["success"] ?? false) !== true) {
  fail("Start.php failed (HTTP $code): " . json_encode($response));
}

// ------------------------------------------------------------- 4. match loop

$tick = 0;
$errors = 0;
$stopReason = "max_ticks";
$lastProgress = "";
$sameProgress = 0;
$lastState = [];
$matchStart = microtime(true);

while ($tick < $maxTicks) {
  [$code, $state] = httpJson("GET", "$baseUrl/GetNextTurn.php?gameName=$gameName&playerID=1&authKey=$p1Key");
  if ($code !== 200 || isset($state["errorMessage"])) {
    fail("GetNextTurn failed (HTTP $code): " . json_encode($state));
  }
  $lastState = $state;

  $phase = $state["turnPhase"]["turnPhase"] ?? "";
  $p1Health = (int)($state["playerHealth"] ?? 0);
  $p2Health = (int)($state["opponentHealth"] ?? 0);
  if ($phase === "OVER" || $p1Health <= 0 || $p2Health <= 0) {
    $stopReason = "game_over";
    break;
  }

  $progress = implode("|", [$phase, $p1Health, $p2Health, $state["turnNo"] ?? "?"]);
  if ($progress === $lastProgress) {
    if (++$sameProgress >= $stallTicks) {
      $stopReason = "stalled";
      break;
    }
  } else {
    $lastProgress = $progress;
    $sameProgress = 0;
  }

  // The seat with priority submits a no-op settings input; the engine then
  // runs the bot for every AI seat until the next decision point.
  $currentPlayer = ($state["havePriority"] ?? false) ? 1 : 2;
  $authKey = $currentPlayer === 1 ? $p1Key : $p2Key;
  [$code, $response] = httpJson("POST", "$baseUrl/ProcessInputAPI.php", [
    "gameName" => $gameName,
    "playerID" => $currentPlayer,
    "authKey" => $authKey,
    "mode" => 26,
    "submission" => ["settings" => []],
  ]);
  if ($code !== 200 || isset($response["error"])) {
    ++$errors;
    fwrite(STDERR, "tick $tick: ProcessInputAPI (player $currentPlayer) HTTP $code: " . json_encode($response) . "\n");
  }
  ++$tick;
}

// ------------------------------------------------------------- 5. report

$p1Health = (int)($lastState["playerHealth"] ?? 0);
$p2Health = (int)($lastState["opponentHealth"] ?? 0);
$winner = $p1Health <= 0 ? 2 : ($p2Health <= 0 ? 1 : 0);

$logPath = "$gameDir/gamelog.txt";
$logLines = is_file($logPath) ? file($logPath, FILE_IGNORE_NEW_LINES) : [];
$logTail = array_slice($logLines, -30);
$winnerLine = "";
foreach ($logLines as $line) {
  if (str_contains($line, "won!")) $winnerLine = strip_tags($line);
}

$report = [
  "gameName" => $gameName,
  "gameDir" => "Games/$gameName",
  "users" => [$user1, $user2],
  "decks" => [$p1Deck, $p2Deck],
  "format" => $format,
  "stopReason" => $stopReason,
  "winner" => $winner,
  "turns" => (int)($lastState["turnNo"] ?? 0),
  "ticks" => $tick,
  "inputErrors" => $errors,
  "finalHealth" => ["p1" => $p1Health, "p2" => $p2Health],
  "finalPhase" => $lastState["turnPhase"]["turnPhase"] ?? "",
  "durationSec" => round(microtime(true) - $matchStart, 1),
  "winnerLogLine" => $winnerLine,
  "logLines" => count($logLines),
  "logTail" => $logTail,
];

if (!$jsonOnly) {
  echo "Game $gameName: $stopReason";
  echo $winner ? ", player $winner wins" : "";
  echo " (turn " . $report["turns"] . ", $tick ticks, {$report["durationSec"]}s)\n";
  echo "Users: $user1 vs $user2 | Decks: $p1Deck vs $p2Deck\n";
  echo "Files: Games/$gameName (GameFile.txt, gamelog.txt, commandfile.txt)\n\n";
  echo implode("\n", $logTail) . "\n\n";
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

exit($stopReason === "game_over" ? 0 : 1);
