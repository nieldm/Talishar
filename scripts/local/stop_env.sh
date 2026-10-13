#!/usr/bin/env bash
# Stop the local Talishar stack. With --wipe, also delete the local MySQL data
# directory so the next start_env.sh recreates the database from
# Database/00_database.sql (destroys every local account and game row).
set -euo pipefail

cd "$(dirname "$0")/../.."

docker compose -f docker-compose.local.yml down

if [ "${1:-}" = "--wipe" ]; then
  rm -rf mysql-data
  echo "Removed mysql-data: the database will be recreated on the next start."
fi
