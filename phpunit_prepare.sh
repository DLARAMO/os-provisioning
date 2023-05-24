#!/bin/bash

# Helper to clean environment if there are strange problems
# like ExtendedValidator not found or wrongly built URLs like https://localhost/admin/Contract/create
# if that doesn't help try deleting bootstrap/cache/*

export COMPOSER_MEMORY_LIMIT=-1
export COMPOSER_ALLOW_SUPERUSER=1

declare -a CMDS=(
    "mkdir -p /var/www/nmsprime/storage/framework/cache"
    "mkdir -p /var/www/nmsprime/storage/framework/sessions"
    "mkdir -p /var/www/nmsprime/storage/framework/views"
    "composer update"
    "php artisan migrate"
    "php artisan module:migrate"
    "php artisan module:publish"
    "php artisan optimize:clear"
    "php artisan nms:coremon-publish"
    "npm install"
    "npm run dev"
    "chown -R apache /var/www/nmsprime/storage/framework"
    "systemctl restart supervisord httpd"
    "systemctl restart telegraf"
    )

clear

for CMD in "${CMDS[@]}"; do
    echo
    echo "Running $CMD…"
    $CMD
done
echo
echo
