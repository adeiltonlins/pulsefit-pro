#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
rm -rf storage
php deploy/init-db.php "Admin Teste" "admin@pulsefit.test" "AdminTeste123!"
php -S 127.0.0.1:8099 tests/router.php >/tmp/pulsefit-smoke.log 2>&1 &
PID=$!
trap 'kill $PID 2>/dev/null || true' EXIT
sleep 1

LOGIN=$(curl -fsS -c /tmp/pulsefit.cookies -H 'Content-Type: application/json' -d '{"email":"admin@pulsefit.test","password":"AdminTeste123!"}' http://127.0.0.1:8099/api/auth/login)
CSRF=$(printf '%s' "$LOGIN" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["csrf"])) exit(1); echo $d["csrf"];')

COACH=$(curl -fsS -b /tmp/pulsefit.cookies -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' -d '{"name":"Treinador Teste","email":"coach@pulsefit.test","password":"CoachTeste123!","cref":"CREF-TESTE"}' http://127.0.0.1:8099/api/coaches)
COACH_ID=$(printf '%s' "$COACH" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["id"])) exit(1); echo $d["id"];')

STUDENT_PAYLOAD=$(printf '{"name":"Aluno Teste","email":"aluno@pulsefit.test","password":"AlunoTeste123!","coachId":%s,"programName":"Programa Teste","phase":"Fase 1"}' "$COACH_ID")
curl -fsS -b /tmp/pulsefit.cookies -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' -d "$STUDENT_PAYLOAD" http://127.0.0.1:8099/api/students >/tmp/student-create.json

LIST=$(curl -fsS -b /tmp/pulsefit.cookies http://127.0.0.1:8099/api/directory/students)
printf '%s' "$LIST" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $ok=false; foreach(($d["students"]??[]) as $s){ if(($s["email"]??"")==="aluno@pulsefit.test") $ok=true; } if(!$ok) exit(1);'

curl -fsS -b /tmp/pulsefit.cookies -H "X-CSRF-Token: $CSRF" -X POST http://127.0.0.1:8099/api/auth/logout >/dev/null
STUDENT_LOGIN=$(curl -fsS -c /tmp/pulsefit-student.cookies -H 'Content-Type: application/json' -d '{"email":"aluno@pulsefit.test","password":"AlunoTeste123!"}' http://127.0.0.1:8099/api/auth/login)
printf '%s' "$STUDENT_LOGIN" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["user"]["role"]??"")!=="student") exit(1);'
curl -fsS -b /tmp/pulsefit-student.cookies http://127.0.0.1:8099/api/student/portal | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["student"]["email"]??"")!=="aluno@pulsefit.test") exit(1);'

echo 'Smoke test OK: admin -> treinador -> aluno -> login aluno -> portal.'
