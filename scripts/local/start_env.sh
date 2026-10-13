#!/usr/bin/env bash
# Bring up the local Talishar stack (PHP 8.3 + MySQL + Redis) for this worktree.
# Idempotent: safe to re-run. Creates the gitignored local files the app needs
# (Redirector, game counter, API keys stub, DB connection helper) if missing.
set -euo pipefail

cd "$(dirname "$0")/../.."
COMPOSE=(docker compose -f docker-compose.local.yml)
WEB_PORT="${TALISHAR_WEB_PORT:-8080}"
BASE_URL="http://localhost:${WEB_PORT}"

# --- Docker engine ----------------------------------------------------------
if ! docker info >/dev/null 2>&1; then
  if command -v orbctl >/dev/null 2>&1; then
    echo "Docker engine not reachable; starting OrbStack..."
    orbctl start
  fi
  if ! docker info >/dev/null 2>&1; then
    echo "ERROR: Docker engine unavailable. Start Docker/OrbStack and retry." >&2
    exit 1
  fi
fi

# --- Gitignored local files the app expects ---------------------------------
mkdir -p Games
[ -f HostFiles/Redirector.php ] || cp HostFiles/RedirectorTemplate.php HostFiles/Redirector.php
if [ ! -f HostFiles/GameIDCounter.txt ]; then echo 1 > HostFiles/GameIDCounter.txt; fi
chmod 666 HostFiles/GameIDCounter.txt

if [ ! -f APIKeys/APIKeys.php ]; then
  # The repo hydrates this from 1Password (inject_secrets.sh); locally every
  # third-party credential stays empty, which is enough for local play.
  sed -E 's|= "op://[^"]*";|= "";|' APIKeys/APIKeys.php.template > APIKeys/APIKeys.php
  echo "Created APIKeys/APIKeys.php with empty credentials."
fi

# --- PHP dependencies (sendgrid runtime dep + phpunit for tests) -------------
if [ ! -d vendor ]; then
  echo "Installing composer dependencies into vendor/ ..."
  docker run --rm -v "$PWD":/app -w /app composer:2 install --no-interaction --no-progress --prefer-dist
fi

# --- Stack ------------------------------------------------------------------
"${COMPOSE[@]}" up -d --build

echo "Waiting for MySQL..."
for _ in $(seq 1 60); do
  if "${COMPOSE[@]}" exec -T mysql-server mysqladmin ping -h 127.0.0.1 -uroot -psecret --silent >/dev/null 2>&1; then break; fi
  sleep 2
done
"${COMPOSE[@]}" exec -T mysql-server mysqladmin ping -h 127.0.0.1 -uroot -psecret --silent >/dev/null

echo "Waiting for the web server..."
for _ in $(seq 1 60); do
  code=$(curl -s -o /dev/null -w '%{http_code}' "${BASE_URL}/game/GetNextTurn.php?gameName=1&playerID=1" || true)
  if [ "$code" = "200" ]; then break; fi
  sleep 2
done
code=$(curl -s -o /dev/null -w '%{http_code}' "${BASE_URL}/game/GetNextTurn.php?gameName=1&playerID=1" || true)
if [ "$code" != "200" ]; then
  echo "ERROR: web server did not answer on ${BASE_URL} (HTTP ${code:-none})" >&2
  exit 1
fi

# --- Migrations -------------------------------------------------------------
# Database/00_database.sql is loaded by MySQL on first start and already
# contains most schema changes; the migration files cover older databases.
# Re-running them is expected to hit "already exists" errors, which are ignored.
echo "Applying Database/migrations (already-present changes are skipped)..."
for f in Database/migrations/*.sql; do
  errs=$("${COMPOSE[@]}" exec -T mysql-server mysql --force -uroot -psecret fabonline < "$f" 2>&1 >/dev/null || true)
  unexpected=$(printf '%s\n' "$errs" | grep -E "^ERROR" | grep -viE "duplicate column name|already exists|duplicate key name|can't drop|doesn't exist" || true)
  if [ -n "$unexpected" ]; then
    echo "WARNING: $(basename "$f"): $unexpected" >&2
  fi
done

echo
echo "Talishar is up:"
echo "  web    ${BASE_URL}/game/  (direct backend; the frontend is a separate project)"
echo "  mysql  mysql-server:3306, db fabonline, user root / secret"
echo "  redis  localhost:${TALISHAR_REDIS_PORT:-6382}"
echo
echo "Play a scripted two-player match (two synthetic users, engine bots play both seats):"
echo "  docker compose -f docker-compose.local.yml exec -T -w /var/www/html/game web-server php scripts/local/play_local_match.php"
echo
echo "Run the PHP test suite:"
echo "  docker compose -f docker-compose.local.yml exec -T -w /var/www/html/game web-server vendor/bin/phpunit --no-coverage"
