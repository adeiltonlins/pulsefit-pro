#!/usr/bin/env bash
set -euo pipefail
APP=/var/www/pulsefit
cd "$APP"
mkdir -p storage/uploads storage/backups
chown -R root:www-data "$APP"
chmod -R 775 storage

echo '[1/4] Aplicando migrações do banco'
php deploy/migrate.php

echo '[2/4] Build do frontend'
cd frontend
npm install
npm run build

cd "$APP"
echo '[3/4] Validando Nginx'
nginx -t
systemctl reload nginx

echo '[4/4] Validando SQLite'
php -m | grep -Ei 'pdo_sqlite|sqlite3' >/dev/null

echo 'PulseFit atualizado. Banco e uploads preservados.'
