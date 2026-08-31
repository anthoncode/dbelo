#!/usr/bin/env bash
# Deployment script for dbelo. Run from the project root on the server.
set -euo pipefail

echo "→ Maintenance mode"
php artisan down --render="errors::503" --retry=60 || true

echo "→ Pulling code"
git pull origin main

echo "→ PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction

echo "→ Front-end assets"
npm ci
npm run build

echo "→ Database"
php artisan migrate --force

echo "→ Search index settings"
php artisan scout:sync-index-settings

echo "→ Caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "→ Storage link"
php artisan storage:link || true

# Workers hold the old code in memory. Without this they keep running the
# previous release until they happen to restart.
echo "→ Restarting queue workers"
php artisan queue:restart

echo "→ Live"
php artisan up

echo "Done."
