<?php
function DelimStringContains($str, $find, $partial=false)
{
  if($str == null) return false;
  // Fast path: most calls pass a single value string with no delimiter
  // Skip the explode()/array. This function is called on so many card-ability check in the engine
  if (strpos($str, ",") === false) {
    return $partial ? str_contains($str, $find) : $str == $find;
  }
  $arr = explode(",", $str);
  foreach($arr as $item)
  {
    if($partial && str_contains($item, $find)) return true;
    else if($item == $find) return true;
  }
  return false;
}

function StringContainsWholeWords($str, $find)
{
  if ($str === null || $find === null || $find === "") return false;

  static $patternCache = [];
  if (!array_key_exists($find, $patternCache)) {
    $wordCharacter = '[\p{L}\p{M}\p{N}]';
    if (!preg_match_all('/' . $wordCharacter . '+/u', $find, $matches)) $patternCache[$find] = null;
    else {
      $words = array_map(fn($word) => preg_quote($word, '/'), $matches[0]);
      $patternCache[$find] = '/(?<!' . $wordCharacter . ')' . implode('[^\p{L}\p{M}\p{N}]+', $words) . '(?!' . $wordCharacter . ')/iu';
    }
  }

  $pattern = $patternCache[$find];
  if ($pattern === null) return false;
  return preg_match($pattern, $str) === 1;
}

function GetRandom($low=-1, $high=-1, $reroll=false)
{
  global $randomSeeded;
  if(!$randomSeeded) SeedRandom($reroll);
  if($low == -1) return mt_rand();
  return mt_rand($low, $high);
}

function SeedRandom($reroll=false)
{
  global $randomSeeded, $currentTurn, $turn, $currentPlayer, $layers, $combatChain, $gameSeed;
  $seedString = $currentTurn . implode("", $turn) . $currentPlayer;
  if (!empty($gameSeed)) $seedString = $gameSeed . "|" . $seedString;
  if(!empty($layers)) $seedString .= implode("", $layers);
  if(!empty($combatChain)) $seedString .= implode("", $combatChain);

  $characterPieces = CharacterPieces();
  $char = &GetPlayerCharacter(1);
  foreach($char as $i => $value) {
    if ($i % $characterPieces != 9) $seedString .= $value;
  }
  $char = &GetPlayerCharacter(2);
  foreach($char as $i => $value) {
    if ($i % $characterPieces != 9) $seedString .= $value;
  }

  $seedString .= implode("", GetBanish(1));
  $seedString .= implode("", GetBanish(2));
  $seedString .= implode("", GetDiscard(1));
  $seedString .= implode("", GetDiscard(2));
  $seedString .= implode("", GetDeck(1));
  $seedString .= implode("", GetDeck(2));
  if($reroll) $seedString .= "|reroll";

  $seedString = hash("sha256", $seedString);
  mt_srand(crc32($seedString));
  $randomSeeded = true;
}

// The opening shuffles cannot be seeded from the gamestate: anyone with both decklists could work out the deck order.
// They use the game seed instead, which stays secret until the game is over. Games without one shuffle unseeded.
function SeedRandomForInitialShuffle($player)
{
  global $randomSeeded, $gameSeed;
  if (!empty($gameSeed)) mt_srand(crc32(hash("sha256", $gameSeed . "|initial-shuffle|" . $player)));
  $randomSeeded = true;
}

function GenerateGameSeed()
{
  return bin2hex(random_bytes(8));
}

// A seed chosen by a player is kept as given, so the same text recreates the same game.
function NormalizeGameSeed($seed)
{
  $seed = trim((string)$seed);
  return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $seed) ? $seed : GenerateGameSeed();
}

// Rolls made before the gamestate exists (e.g. who chooses the first player) come only from the game seed.
function SeedRandomFromGameSeed($gameSeed, $purpose)
{
  if (!empty($gameSeed)) mt_srand(crc32(hash("sha256", $gameSeed . "|" . $purpose)));
}

function RandomShuffle(&$array)
{
  $array = array_values($array);
  for ($i = count($array) - 1; $i > 0; --$i) {
    $j = GetRandom(0, $i);
    $tmp = $array[$i]; $array[$i] = $array[$j]; $array[$j] = $tmp;
  }
}

function AcquireGameActionLock(string $gameName, ?string $gamesRoot = null)
{
  if (!IsGameNameValid($gameName)) return false;

  $gamesRoot ??= dirname(__DIR__) . DIRECTORY_SEPARATOR . "Games";
  $gameDirectory = $gamesRoot . DIRECTORY_SEPARATOR . $gameName;
  if (!is_dir($gameDirectory)) return false;

  $lockHandler = fopen($gameDirectory . DIRECTORY_SEPARATOR . "action.lock", "c");
  if ($lockHandler === false) return false;
  if (!flock($lockHandler, LOCK_EX)) {
    fclose($lockHandler);
    return false;
  }

  return $lockHandler;
}

function ReleaseGameActionLock($lockHandler): void
{
  if (!is_resource($lockHandler)) return;
  flock($lockHandler, LOCK_UN);
  fclose($lockHandler);
}
