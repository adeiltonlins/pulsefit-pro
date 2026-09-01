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
BASE=http://127.0.0.1:8099/api
login(){ curl -fsS -c "$3" -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"$2\"}" "$BASE/auth/login"; }
csrf(){ printf '%s' "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["csrf"])) exit(1); echo $d["csrf"];'; }
change_password(){ curl -fsS -b "$1" -H "X-CSRF-Token: $2" -H 'Content-Type: application/json' -d "{\"currentPassword\":\"$3\",\"newPassword\":\"$4\"}" "$BASE/auth/change-password"; }

for i in 1 2 3 4; do code=$(curl -sS -o /tmp/pf-bad.json -w '%{http_code}' -H 'Content-Type: application/json' -d '{"email":"brute@pulsefit.test","password":"errada"}' "$BASE/auth/login"); [ "$code" = "401" ]; done
code=$(curl -sS -o /tmp/pf-locked.json -w '%{http_code}' -H 'Content-Type: application/json' -d '{"email":"brute@pulsefit.test","password":"errada"}' "$BASE/auth/login"); [ "$code" = "429" ]

LOGIN=$(login 'admin@pulsefit.test' 'AdminTeste123!' /tmp/pulsefit-admin.cookies); CSRF=$(csrf "$LOGIN")
COACH=$(curl -fsS -b /tmp/pulsefit-admin.cookies -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' -d '{"name":"Treinador Teste","email":"coach@pulsefit.test","password":"CoachTeste123!","cref":"CREF-TESTE"}' "$BASE/coaches")
COACH_ID=$(printf '%s' "$COACH" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["id"])) exit(1); echo $d["id"];')
COACH2=$(curl -fsS -b /tmp/pulsefit-admin.cookies -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' -d '{"name":"Treinador Dois","email":"coach2@pulsefit.test","password":"CoachDois123!","cref":"CREF-DOIS"}' "$BASE/coaches")
COACH2_ID=$(printf '%s' "$COACH2" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["id"])) exit(1); echo $d["id"];')
OTHER=$(curl -fsS -b /tmp/pulsefit-admin.cookies -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' -d "{\"name\":\"Aluno Outro Coach\",\"email\":\"outro@pulsefit.test\",\"password\":\"OutroAluno123!\",\"coachId\":$COACH2_ID}" "$BASE/students")
OTHER_ID=$(printf '%s' "$OTHER" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["id"])) exit(1); echo $d["id"];')
curl -fsS -b /tmp/pulsefit-admin.cookies -H "X-CSRF-Token: $CSRF" -X POST "$BASE/auth/logout" >/dev/null

COACH_LOGIN=$(login 'coach@pulsefit.test' 'CoachTeste123!' /tmp/pulsefit-coach.cookies); COACH_CSRF=$(csrf "$COACH_LOGIN")
printf '%s' "$COACH_LOGIN" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["user"]["role"]??"")!=="coach" || empty($d["user"]["mustChangePassword"])) exit(1);'
[ "$(curl -sS -o /tmp/pf-before-change.json -w '%{http_code}' -b /tmp/pulsefit-coach.cookies "$BASE/commercial/status")" = "428" ]
change_password /tmp/pulsefit-coach.cookies "$COACH_CSRF" 'CoachTeste123!' 'CoachNovaSenha123!' >/dev/null
STUDENT=$(curl -fsS -b /tmp/pulsefit-coach.cookies -H "X-CSRF-Token: $COACH_CSRF" -H 'Content-Type: application/json' -d '{"name":"Aluno Criado Pelo Professor","email":"aluno@pulsefit.test","password":"AlunoTeste123!","programName":"Programa Teste","phase":"Fase 1"}' "$BASE/students")
STUDENT_ID=$(printf '%s' "$STUDENT" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["id"])||empty($d["ok"])) exit(1); echo $d["id"];')
[ "$(curl -sS -o /tmp/pf-ownership.json -w '%{http_code}' -b /tmp/pulsefit-coach.cookies "$BASE/students/$OTHER_ID/metrics")" = "404" ]

curl -fsS -b /tmp/pulsefit-coach.cookies -H "X-CSRF-Token: $COACH_CSRF" -H 'Content-Type: application/json' -d "{\"studentId\":$STUDENT_ID,\"goalType\":\"weight\",\"targetValue\":75,\"unit\":\"kg\"}" "$BASE/goals" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["ok"])) exit(1);'
curl -fsS -b /tmp/pulsefit-coach.cookies -H "X-CSRF-Token: $COACH_CSRF" -H 'Content-Type: application/json' -d "{\"studentId\":$STUDENT_ID,\"weight\":80,\"bodyFat\":18,\"waist\":84,\"chest\":100}" "$BASE/assessments" >/dev/null
curl -fsS -b /tmp/pulsefit-coach.cookies "$BASE/coach/retention" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(!isset($d["students"])) exit(1);'
curl -fsS -b /tmp/pulsefit-coach.cookies -H "X-CSRF-Token: $COACH_CSRF" -H 'Content-Type: application/json' -d "{\"studentId\":$STUDENT_ID,\"days\":30}" "$BASE/reports/student" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["ok"])||!isset($d["report"]["adherence"])) exit(1);'
curl -fsS -b /tmp/pulsefit-coach.cookies -H "X-CSRF-Token: $COACH_CSRF" -H 'Content-Type: application/json' -d "{\"studentId\":$STUDENT_ID,\"days\":30}" "$BASE/ai/student-summary" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["summary"])) exit(1);'

curl -fsS -b /tmp/pulsefit-coach.cookies -H "X-CSRF-Token: $COACH_CSRF" -H 'Content-Type: application/json' -X PUT -d '{"slug":"treinador-teste","headline":"Personal Trainer","bio":"Acompanhamento individual","city":"Recife","state":"PE","services":["Hipertrofia"],"publicEnabled":true}' "$BASE/coach/public-profile" >/dev/null
curl -fsS "$BASE/public/coach/treinador-teste" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["profile"]["name"]??"")!=="Treinador Teste") exit(1);'
curl -fsS -H 'Content-Type: application/json' -d '{"name":"Lead Teste","email":"lead@pulsefit.test","phone":"81999999999","goal":"Hipertrofia"}' "$BASE/public/coach/treinador-teste/lead" >/dev/null
LEADS=$(curl -fsS -b /tmp/pulsefit-coach.cookies "$BASE/coach/leads")
LEAD_ID=$(printf '%s' "$LEADS" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["leads"][0]["id"])) exit(1); echo $d["leads"][0]["id"];')
CONVERT=$(curl -fsS -b /tmp/pulsefit-coach.cookies -H "X-CSRF-Token: $COACH_CSRF" -H 'Content-Type: application/json' -d '{"programName":"Hipertrofia Pro","phase":"Fase Inicial"}' "$BASE/coach/leads/$LEAD_ID/convert")
printf '%s' "$CONVERT" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(empty($d["ok"])||empty($d["studentId"])||empty($d["temporaryPassword"])) exit(1);'
curl -fsS -b /tmp/pulsefit-coach.cookies "$BASE/coach/leads" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["leads"][0]["status"]??"")!=="won"||empty($d["leads"][0]["convertedStudentId"])) exit(1);'
curl -fsS -b /tmp/pulsefit-coach.cookies -H "X-CSRF-Token: $COACH_CSRF" -X POST "$BASE/auth/logout" >/dev/null

STUDENT_LOGIN=$(login 'aluno@pulsefit.test' 'AlunoTeste123!' /tmp/pulsefit-student.cookies); STUDENT_CSRF=$(csrf "$STUDENT_LOGIN")
[ "$(curl -sS -o /tmp/pf-student-before.json -w '%{http_code}' -b /tmp/pulsefit-student.cookies "$BASE/student/portal")" = "428" ]
change_password /tmp/pulsefit-student.cookies "$STUDENT_CSRF" 'AlunoTeste123!' 'AlunoNovaSenha123!' >/dev/null
curl -fsS -b /tmp/pulsefit-student.cookies "$BASE/student/central" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["student"]["name"]??"")!=="Aluno Criado Pelo Professor"||(int)($d["weeklyTarget"]??0)!==3||!isset($d["weeklyProgress"])) exit(1);'
curl -fsS -b /tmp/pulsefit-student.cookies "$BASE/goals" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["goals"][0]["goalType"]??"")!=="weight") exit(1);'
curl -fsS -b /tmp/pulsefit-student.cookies -H "X-CSRF-Token: $STUDENT_CSRF" -H 'Content-Type: application/json' -d '{"documentType":"privacy","documentVersion":"2026-09"}' "$BASE/privacy/consents" >/dev/null
curl -fsS -b /tmp/pulsefit-student.cookies "$BASE/privacy/export" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["export"]["students"][0]["email"]??"")!=="aluno@pulsefit.test") exit(1);'
curl -fsS -b /tmp/pulsefit-student.cookies -H "X-CSRF-Token: $STUDENT_CSRF" -X POST "$BASE/auth/logout" >/dev/null

LOGIN=$(login 'admin@pulsefit.test' 'AdminTeste123!' /tmp/pulsefit-admin2.cookies); CSRF=$(csrf "$LOGIN")
curl -fsS -b /tmp/pulsefit-admin2.cookies "$BASE/admin/master-metrics" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if((int)($d["metrics"]["coachesTotal"]??0)<2||(int)($d["metrics"]["leads30d"]??0)<1) exit(1);'
IMP=$(curl -fsS -b /tmp/pulsefit-admin2.cookies -c /tmp/pulsefit-admin2.cookies -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' -d "{\"userId\":$COACH_ID}" "$BASE/admin/impersonate"); IMP_CSRF=$(csrf "$IMP")
STOP=$(curl -fsS -b /tmp/pulsefit-admin2.cookies -c /tmp/pulsefit-admin2.cookies -H "X-CSRF-Token: $IMP_CSRF" -X POST "$BASE/admin/impersonate/stop")
printf '%s' "$STOP" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if(($d["user"]["role"]??"")!=="admin") exit(1);'

echo "Smoke V10-V15 OK: segurança -> professor -> aluno -> gamificação -> relatório/IA -> CRM conversão -> Admin Master. Student=$STUDENT_ID"