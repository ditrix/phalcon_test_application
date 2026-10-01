#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [ ! -f .env ]; then
  cp .env.example .env
fi

docker compose up -d --build
sleep 8

docker compose exec -T web php -m | grep -qi phalcon

docker compose exec -T web php -v | grep -Eq 'PHP 7\.2'

curl -fsS http://localhost:8080 | grep -q 'Phalcon version'

# Verify app reports a successful database connection.
curl -fsS http://localhost:8080 | grep -q 'DB status: DB connection OK'

echo "OK: Phalcon, PHP 7.2, HTTP endpoint, and DB check all passed."
