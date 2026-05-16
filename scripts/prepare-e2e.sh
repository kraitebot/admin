#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INGESTION_DIR="${KRAITE_INGESTION_DIR:-/Users/falcaob/Herd/ingestion.kraite.test}"

cd "$INGESTION_DIR"
php artisan migrate:fresh --seed --env=testing --force

cd "$ROOT_DIR"
php artisan optimize:clear --env=testing --no-interaction
php artisan tinker --env=testing --execute='require base_path("tests/e2e/fixtures/registration-user.php");'
