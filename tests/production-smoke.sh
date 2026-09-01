#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
rm -rf storage
php deploy/init-db.php "Admin Produção" "admin-prod@pulsefit.test" "AdminProd123!"
php -S 127.0.0.1:8100 tests/router.php >/tmp/pulsefit-production-smoke.log 2>&1 &
PID=$!
trap 'kill $PID 2>/dev/null || true' EXIT
sleep 1
BASE=http://127.0.0.1:8100/api
LOGIN=$(curl -fsS -c /tmp/pf-prod.cookies -H 'Content-Type: application/json' -d '{"email":"admin-prod@pulsefit.test","password":"AdminProd123!"}' "$BASE/auth/login")
printf '%s' "$LOGIN" | php -r '$d=json_decode(stream_get_contents(STDIN),true);if(($d["user"]["role"]??"")!=="admin")exit(1);'
curl -fsS -b /tmp/pf-prod.cookies "$BASE/admin/readiness" | php -r '$d=json_decode(stream_get_contents(STDIN),true);if(!isset($d["checks"]["databaseWritable"])||!isset($d["checks"]["storageWritable"])||!isset($d["integrations"]["https"]))exit(1);'
curl -fsS -b /tmp/pf-prod.cookies "$BASE/admin/risk-dashboard" | php -r '$d=json_decode(stream_get_contents(STDIN),true);if(!isset($d["risks"]["inactive7d"])||!isset($d["risks"]["trialsExpiring7d"]))exit(1);'
curl -fsS -b /tmp/pf-prod.cookies "$BASE/admin/integrations-status" | php -r '$d=json_decode(stream_get_contents(STDIN),true);if(!array_key_exists("mercadoPago",$d["integrations"]??[])||!array_key_exists("webPush",$d["integrations"]??[]))exit(1);'
echo "Production readiness smoke OK"
