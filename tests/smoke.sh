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

login(){ curl -fsS -c "$3" -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"$2\"}" http://127.0.0.1:8099/api/auth/login; }
csrf(){ printf '%s' "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["csrf"])) exit(1); echo $d["csrf"];'; }

# Admin cria somente o professor.
LOGIN=$(login 'admin@pulsefit.test' 'AdminTeste123!' /tmp/pulsefit-admin.cookies)
CSRF=$(csrf "$LOGIN")
COACH=$(curl -fsS -b /tmp/pulsefit-admin.cookies -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' -d '{"name":"Treinador Teste","email":"coach@pulsefit.test","password":"CoachTeste123!","cref":"CREF-TESTE"}' http://127.0.0.1:8099/api/coaches)
COACH_ID=$(printf '%s' "$COACH" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["id"])) exit(1); echo $d["id"];')
curl -fsS -b /tmp/pulsefit-admin.cookies -H "X-CSRF-Token: $CSRF" -X POST http://127.0.0.1:8099/api/auth/logout >/dev/null

# PROFESSOR autenticado cria o próprio aluno — fluxo real que precisa funcionar.
COACH_LOGIN=$(login 'coach@pulsefit.test' 'CoachTeste123!' /tmp/pulsefit-coach.cookies)
COACH_CSRF=$(csrf "$COACH_LOGIN")
printf '%s' "$COACH_LOGIN" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["user"]["role"]??"")!=="coach") exit(1);'
curl -fsS -b /tmp/pulsefit-coach.cookies http://127.0.0.1:8099/api/commercial/status | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["status"]["subscriptionStatus"]??"")!=="trialing") exit(1); if((int)($d["status"]["studentLimit"]??0)<1) exit(1);'
STUDENT=$(curl -fsS -b /tmp/pulsefit-coach.cookies -H "X-CSRF-Token: $COACH_CSRF" -H 'Content-Type: application/json' -d '{"name":"Aluno Criado Pelo Professor","email":"aluno@pulsefit.test","password":"AlunoTeste123!","programName":"Programa Teste","phase":"Fase 1"}' http://127.0.0.1:8099/api/students)
STUDENT_ID=$(printf '%s' "$STUDENT" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["id"])||empty($d["ok"])) {fwrite(STDERR,json_encode($d)); exit(1);} echo $d["id"];')
LIST=$(curl -fsS -b /tmp/pulsefit-coach.cookies http://127.0.0.1:8099/api/directory/students)
printf '%s' "$LIST" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $ok=false; foreach(($d["students"]??[]) as $s){ if(($s["email"]??"")==="aluno@pulsefit.test" && ($s["assignedCoachName"]??"")==="Treinador Teste") $ok=true; } if(!$ok) exit(1);'
curl -fsS -b /tmp/pulsefit-coach.cookies -H "X-CSRF-Token: $COACH_CSRF" -H 'Content-Type: application/json' -X PATCH -d '{"step":2,"completed":false}' http://127.0.0.1:8099/api/coach/onboarding >/dev/null
curl -fsS -b /tmp/pulsefit-coach.cookies -H "X-CSRF-Token: $COACH_CSRF" -X POST http://127.0.0.1:8099/api/auth/logout >/dev/null

# Aluno criado pelo professor consegue autenticar e abrir o próprio portal.
STUDENT_LOGIN=$(login 'aluno@pulsefit.test' 'AlunoTeste123!' /tmp/pulsefit-student.cookies)
STUDENT_CSRF=$(csrf "$STUDENT_LOGIN")
printf '%s' "$STUDENT_LOGIN" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["user"]["role"]??"")!=="student") exit(1);'
curl -fsS -b /tmp/pulsefit-student.cookies http://127.0.0.1:8099/api/student/portal | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["student"]["email"]??"")!=="aluno@pulsefit.test") exit(1);'
curl -fsS -b /tmp/pulsefit-student.cookies -H "X-CSRF-Token: $STUDENT_CSRF" -H 'Content-Type: application/json' -d '{"documentType":"privacy","documentVersion":"2026-09"}' http://127.0.0.1:8099/api/privacy/consents >/dev/null
curl -fsS -b /tmp/pulsefit-student.cookies http://127.0.0.1:8099/api/privacy/export | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["export"]["students"][0]["email"]??"")!=="aluno@pulsefit.test") exit(1);'
curl -fsS -b /tmp/pulsefit-student.cookies -H "X-CSRF-Token: $STUDENT_CSRF" -X POST http://127.0.0.1:8099/api/auth/logout >/dev/null

# Super Admin continua conseguindo visualizar como professor e retornar.
LOGIN=$(login 'admin@pulsefit.test' 'AdminTeste123!' /tmp/pulsefit-admin2.cookies)
CSRF=$(csrf "$LOGIN")
IMP=$(curl -fsS -b /tmp/pulsefit-admin2.cookies -c /tmp/pulsefit-admin2.cookies -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' -d "{\"userId\":$COACH_ID}" http://127.0.0.1:8099/api/admin/impersonate)
IMP_CSRF=$(csrf "$IMP")
printf '%s' "$IMP" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["user"]["role"]??"")!=="coach") exit(1);'
STOP=$(curl -fsS -b /tmp/pulsefit-admin2.cookies -c /tmp/pulsefit-admin2.cookies -H "X-CSRF-Token: $IMP_CSRF" -X POST http://127.0.0.1:8099/api/admin/impersonate/stop)
printf '%s' "$STOP" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["user"]["role"]??"")!=="admin") exit(1);'

echo "Smoke test OK: PROFESSOR criou aluno -> listagem -> login aluno -> portal. Student=$STUDENT_ID"
