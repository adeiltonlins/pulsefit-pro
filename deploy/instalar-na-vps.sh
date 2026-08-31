#!/usr/bin/env bash
set -euo pipefail
APP=/var/www/pulsefit
HOST=pulsefit.179.199.128.50.nip.io

echo '[1/7] Dependências do sistema'
apt update
apt install -y curl ca-certificates nginx php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-mbstring php8.3-curl php8.3-xml php8.3-zip unzip
if ! command -v node >/dev/null 2>&1; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt install -y nodejs
fi

echo '[2/7] Pastas e permissões'
mkdir -p "$APP/storage/uploads" "$APP/storage/backups"
chown -R root:www-data "$APP"
find "$APP" -type d -exec chmod 755 {} \;
chmod -R 775 "$APP/storage"

echo '[3/7] Banco SQLite'
if [ ! -f "$APP/storage/pulsefit.sqlite" ]; then
  echo 'Primeira instalação. Crie o administrador.'
  read -rp 'Nome do administrador [Administrador PulseFit]: ' ADMIN_NAME
  ADMIN_NAME=${ADMIN_NAME:-Administrador PulseFit}
  read -rp 'E-mail do administrador: ' ADMIN_EMAIL
  read -rsp 'Senha do administrador (mínimo 10 caracteres): ' ADMIN_PASS
  echo
  php "$APP/deploy/init-db.php" "$ADMIN_NAME" "$ADMIN_EMAIL" "$ADMIN_PASS"
  chown www-data:www-data "$APP/storage/pulsefit.sqlite"
  chmod 660 "$APP/storage/pulsefit.sqlite"
else
  echo 'Banco existente preservado.'
fi

echo '[4/7] Build do frontend React'
cd "$APP/frontend"
npm install
npm run build

echo '[5/7] Nginx separado do ASTECH'
cp "$APP/deploy/pulsefit-temp.nginx" /etc/nginx/sites-available/pulsefit-temp
ln -sfn /etc/nginx/sites-available/pulsefit-temp /etc/nginx/sites-enabled/pulsefit-temp
nginx -t
systemctl reload nginx

echo '[6/7] Teste da API'
php -m | grep -Ei 'pdo_sqlite|sqlite3' >/dev/null
curl -fsS "http://127.0.0.1/api/health" -H "Host: $HOST" || true

echo '[7/7] Pronto'
echo "Abra: http://$HOST"
