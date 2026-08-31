#!/usr/bin/env bash
set -euo pipefail
APP=/var/www/pulsefit
cd "$APP"
mkdir -p storage/uploads storage/backups
chown -R root:www-data "$APP"
chmod -R 775 storage
cd frontend
npm install
npm run build
cd "$APP"
nginx -t
systemctl reload nginx
php -m | grep -Ei 'pdo_sqlite|sqlite3' >/dev/null
echo 'PulseFit atualizado. Banco e uploads preservados.'
