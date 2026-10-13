<?php
// Plays many bot-vs-bot games with the real engine and reports crashes, stuck games and results.
// Each game runs tests/Engine/Fixtures/SeedGameRunner.php in its own PHP process.
//
// Usage: php scripts/simulate_games.php [options]
//   --games=N            Games to play (default 20)
//   --seed=PREFIX        Seeds are PREFIX-1 .. PREFIX-N (default "sim"); the same prefix replays the same games
//   --seeds=a,b,c        Play exactly these seeds instead (use "-" for a game without a seed)
//   --p1=DECK --p2=DECK  Deck files from Assets/ (default IraCC.txt vs FaiCC.txt)
//   --max-steps=N        Engine steps before a game counts as too long (default 400)
//   --jobs=N             Games played at the same time (default 4)
//   --check-determinism  Play every seed twice and fail if the two games differ
//   --json=FILE          Also write the full report as JSON
//
// Exits with 1 when a game crashes or, with --check-determinism, when a seed does not replay the same game.

if (PHP_SAPI !== "cli") exit;

const SIM_RUNNER = __DIR__ . "/../tests/Engine/Fixtures/SeedGameRunner.php";

function SimOptions($argv)
{
  $options = getopt("", ["games:", "seed:", "seeds:", "p1:", "p2:", "max-steps:", "jobs:", "check-determinism", "json:", "help"]);
  if (isset($options["help"])) {
    $lines = file(__FILE__);
    echo implode("", array_map(fn($line) => preg_replace('#^// ?#', '', $line), array_slice($lines, 1, 15)));
    exit(0);
  }
  $games = max(1, intval($options["games"] ?? 20));
  $prefix = $options["seed"] ?? "sim";
  $seeds = isset($options["seeds"]) ? array_values(array_filter(explode(",", $options["seeds"]), fn($seed) => $seed !== ""))
    : array_map(fn($i) => "$prefix-$i", range(1, $games));
  foreach ($seeds as $seed) {
    if ($seed !== "-" && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $seed)) SimExit("Invalid seed \"$seed\": use letters, digits, - or _ (up to 64)");
  }
  $decks = [basename($options["p1"] ?? "IraCC.txt"), basename($options["p2"] ?? "FaiCC.txt")];
  foreach ($decks as $deck) {
    if (!is_file(__DIR__ . "/../Assets/$deck")) SimExit("Deck Assets/$deck does not exist");
  }
  return [
    "seeds" => $seeds,
    "decks" => $decks,
    "maxSteps" => max(1, intval($options["max-steps"] ?? 400)),
    "jobs" => max(1, intval($options["jobs"] ?? 4)),
    "determinism" => isset($options["check-determinism"]),
    "json" => $options["json"] ?? null,
  ];
}

function SimExit($message)
{
  fwrite(STDERR, $message . "\n");
  exit(2);
}

function SimCommand($seed, $options)
{
  return array_merge([PHP_BINARY, SIM_RUNNER, $seed, (string)$options["maxSteps"]], $options["decks"]);
}

// Runs every task through a pool of at most $jobs processes; returns the results in task order.
function SimRunAll($tasks, $options)
{
  $results = [];
  $running = [];
  $next = 0;
  while ($next < count($tasks) || count($running) > 0) {
    while ($next < count($tasks) && count($running) < $options["jobs"]) {
      $pipes = [];
      $process = proc_open(SimCommand($tasks[$next]["seed"], $options), [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
      if ($process === false) SimExit("Could not start a PHP process");
      stream_set_blocking($pipes[1], false);
      stream_set_blocking($pipes[2], false);
      $running[$next] = ["process" => $process, "pipes" => $pipes, "out" => "", "err" => "", "start" => microtime(true)];
      ++$next;
    }
    foreach ($running as $index => &$job) {
      $job["out"] .= stream_get_contents($job["pipes"][1]);
      $job["err"] .= stream_get_contents($job["pipes"][2]);
      $status = proc_get_status($job["process"]);
      if ($status["running"]) continue;
      $job["out"] .= stream_get_contents($job["pipes"][1]);
      $job["err"] .= stream_get_contents($job["pipes"][2]);
      fclose($job["pipes"][1]);
      fclose($job["pipes"][2]);
      proc_close($job["process"]);
      $results[$index] = SimResult($tasks[$index], $status["exitcode"], $job, $options);
      SimPrintGame($results[$index]);
      unset($running[$index]);
    }
    unset($job);
    if (count($running) > 0) usleep(20000);
  }
  ksort($results);
  return array_values($results);
}

function SimResult($task, $exitCode, $job, $options)
{
  $game = json_decode($job["out"], true);
  $result = [
    "seed" => $task["seed"],
    "run" => $task["run"],
    "seconds" => round(microtime(true) - $job["start"], 2),
    // Run from the repository root.
    "reproduce" => "tests/Engine/Fixtures/SeedGameRunner.php " . implode(" ", array_map("escapeshellarg", array_slice(SimCommand($task["seed"], $options), 2))),
  ];
  if ($exitCode !== 0 || !is_array($game)) {
    $error = trim($job["err"]) !== "" ? trim($job["err"]) : "the runner exited with code $exitCode and no output";
    return $result + ["status" => "error", "error" => strtok($error, "\n")];
  }
  return $result + [
    "status" => $game["stopReason"],
    "turns" => $game["turns"],
    "steps" => $game["steps"],
    "winner" => $game["winner"],
    "health" => $game["health"],
    "finalStateHash" => $game["finalStateHash"],
    "stateHashes" => $game["stateHashes"],
  ];
}

function SimPrintGame($result)
{
  $label = str_pad($result["seed"] . ($result["run"] > 1 ? " (replay)" : ""), 22);
  if ($result["status"] === "error") {
    echo "  $label ERROR    " . $result["error"] . "\n";
    return;
  }
  $status = ["game_over" => "finished", "stalled" => "STALLED", "max_steps" => "TOO LONG"][$result["status"]];
  $winner = $result["winner"] ? "player " . $result["winner"] . " wins" : "no winner";
  printf("  %s %-8s %3d turns  %-15s life %s  %.2fs\n", $label, $status, $result["turns"], $winner, implode(" / ", $result["health"]), $result["seconds"]);
}

function SimSummary($results, $options)
{
  $first = array_values(array_filter($results, fn($result) => $result["run"] == 1));
  $count = fn($status) => count(array_filter($first, fn($result) => $result["status"] === $status));
  $finished = array_values(array_filter($first, fn($result) => $result["status"] === "game_over"));
  $turns = array_column($finished, "turns");
  $wins = [1 => 0, 2 => 0];
  foreach ($finished as $result) if (isset($wins[$result["winner"]])) ++$wins[$result["winner"]];

  $mismatches = [];
  if ($options["determinism"]) {
    $bySeed = [];
    foreach ($results as $result) $bySeed[$result["seed"]][$result["run"]] = $result;
    foreach ($bySeed as $seed => $runs) {
      if (!isset($runs[1], $runs[2]) || $runs[1]["status"] === "error" || $runs[2]["status"] === "error") continue;
      if ($seed !== "-" && $runs[1]["stateHashes"] !== $runs[2]["stateHashes"]) $mismatches[] = $seed;
    }
  }

  return [
    "decks" => $options["decks"],
    "games" => count($first),
    "finished" => count($finished),
    "stalled" => $count("stalled"),
    "tooLong" => $count("max_steps"),
    "errors" => array_values(array_map(fn($result) => ["seed" => $result["seed"], "error" => $result["error"], "reproduce" => "php " . $result["reproduce"]],
      array_filter($results, fn($result) => $result["status"] === "error"))),
    "wins" => $wins,
    "turns" => $turns ? ["min" => min($turns), "average" => round(array_sum($turns) / count($turns), 1), "max" => max($turns)] : null,
    "determinismChecked" => $options["determinism"],
    "notReplayed" => $mismatches,
  ];
}

function SimPrintSummary($summary)
{
  echo "\n" . $summary["decks"][0] . " vs " . $summary["decks"][1] . ": " . $summary["games"] . " games\n";
  echo "  Finished:     " . $summary["finished"] . "\n";
  if ($summary["finished"] > 0) {
    $share = fn($player) => round(100 * $summary["wins"][$player] / $summary["finished"]) . "%";
    echo "  Wins:         player 1 " . $summary["wins"][1] . " (" . $share(1) . "), player 2 " . $summary["wins"][2] . " (" . $share(2) . ")\n";
    echo "  Turns:        " . $summary["turns"]["min"] . " min, " . $summary["turns"]["average"] . " average, " . $summary["turns"]["max"] . " max\n";
  }
  echo "  Stalled:      " . $summary["stalled"] . " (the bots stopped acting before the game ended)\n";
  echo "  Too long:     " . $summary["tooLong"] . " (hit --max-steps)\n";
  echo "  Errors:       " . count($summary["errors"]) . "\n";
  foreach ($summary["errors"] as $error) echo "    " . $error["seed"] . ": " . $error["error"] . "\n      Reproduce: " . $error["reproduce"] . "\n";
  if ($summary["determinismChecked"]) {
    echo "  Determinism:  " . (count($summary["notReplayed"]) == 0 ? "every seed replayed the same game"
      : count($summary["notReplayed"]) . " seed(s) played differently the second time: " . implode(", ", $summary["notReplayed"])) . "\n";
  }
}

$options = SimOptions($argv);
$tasks = [];
foreach ($options["seeds"] as $seed) {
  $tasks[] = ["seed" => $seed, "run" => 1];
  if ($options["determinism"]) $tasks[] = ["seed" => $seed, "run" => 2];
}
echo "Playing " . count($options["seeds"]) . " games of " . $options["decks"][0] . " vs " . $options["decks"][1]
  . ($options["determinism"] ? ", each twice" : "") . "...\n";
$results = SimRunAll($tasks, $options);
$summary = SimSummary($results, $options);
SimPrintSummary($summary);

if ($options["json"] !== null) {
  $games = array_map(function ($result) {
    unset($result["stateHashes"]);
    return $result;
  }, $results);
  file_put_contents($options["json"], json_encode(["summary" => $summary, "games" => $games], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  echo "\nReport written to " . $options["json"] . "\n";
}

exit(count($summary["errors"]) > 0 || count($summary["notReplayed"]) > 0 ? 1 : 0);
