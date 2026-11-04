#!/usr/bin/env bash
set -euo pipefail

app_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
web_root="$HOME/domains/sviluppodev.eu/public_html/book-lovers"

if [[ "$app_root" == *"/public_html"* || ! -f "$app_root/artisan" || ! -f "$app_root/.env" ]]; then
    printf 'Deploy refused: Laravel must be outside public_html and have its own .env.\n' >&2
    exit 1
fi

if command -v composer2 >/dev/null 2>&1; then
    composer_bin=composer2
else
    composer_bin=composer
fi

cd -- "$app_root"
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
chmod 600 .env
chmod -R u+rwX storage bootstrap/cache
"$composer_bin" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan optimize:clear
php artisan migrate --force --no-interaction
php artisan db:seed --class=GenreSeeder --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache

mkdir -p -- "$web_root"
cp -R -- "$app_root/public/." "$web_root/"
php -r 'file_put_contents($argv[1], "<?php\n\nrequire ".var_export($argv[2]."/public/index.php", true).";\n");' "$web_root/index.php" "$app_root"
printf 'Book Lovers deployed to https://sviluppodev.eu/book-lovers/\n'
