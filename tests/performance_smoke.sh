#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"; cd "$ROOT"; rm -rf storage
php deploy/init-db.php "Admin Teste" "admin@pulsefit.test" "AdminTeste123!"
php -S 127.0.0.1:8101 tests/router.php >/tmp/pulsefit-performance.log 2>&1 & PID=$!; trap 'kill $PID 2>/dev/null || true' EXIT; sleep 1
BASE=http://127.0.0.1:8101/api
login(){ curl -fsS -c "$3" -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"$2\"}" "$BASE/auth/login"; }
csrf(){ printf '%s' "$1" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["csrf"]??"";'; }
ADM=$(login 'admin@pulsefit.test' 'AdminTeste123!' /tmp/pf-pa.cookie); ACSR=$(csrf "$ADM")
COACH=$(curl -fsS -b /tmp/pf-pa.cookie -H "X-CSRF-Token: $ACSR" -H 'Content-Type: application/json' -d '{"name":"Coach Performance","email":"coachp@pulsefit.test","password":"CoachPerf123!","cref":"CREF-P"}' "$BASE/coaches")
CID=$(printf '%s' "$COACH"|php -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["id"]??0;')
STU=$(curl -fsS -b /tmp/pf-pa.cookie -H "X-CSRF-Token: $ACSR" -H 'Content-Type: application/json' -d "{\"name\":\"Aluno Performance\",\"email\":\"alunop@pulsefit.test\",\"password\":\"AlunoPerf123!\",\"coachId\":$CID}" "$BASE/students")
SID=$(printf '%s' "$STU"|php -r '$d=json_decode(stream_get_contents(STDIN),true);echo $d["id"]??0;')
CO=$(login 'coachp@pulsefit.test' 'CoachPerf123!' /tmp/pf-pc.cookie); CCSR=$(csrf "$CO")
curl -fsS -b /tmp/pf-pc.cookie -H "X-CSRF-Token: $CCSR" -H 'Content-Type: application/json' -d '{"currentPassword":"CoachPerf123!","newPassword":"CoachPerfNova123!"}' "$BASE/auth/change-password" >/dev/null
CALC=$(curl -fsS -b /tmp/pf-pc.cookie -H "X-CSRF-Token: $CCSR" -H 'Content-Type: application/json' -d '{"loadKg":100,"reps":5,"formula":"epley"}' "$BASE/fitness/1rm/calculate")
printf '%s' "$CALC"|php -r '$d=json_decode(stream_get_contents(STDIN),true);if(($d["estimated1rm"]??0)<116)exit(1);'
curl -fsS -b /tmp/pf-pc.cookie -H "X-CSRF-Token: $CCSR" -H 'Content-Type: application/json' -d "{\"studentId\":$SID,\"exerciseName\":\"Supino\",\"loadKg\":100,\"reps\":5,\"formula\":\"epley\"}" "$BASE/fitness/strength-tests" | php -r '$d=json_decode(stream_get_contents(STDIN),true);if(empty($d["ok"]))exit(1);'
curl -fsS -b /tmp/pf-pc.cookie -H "X-CSRF-Token: $CCSR" -H 'Content-Type: application/json' -d "{\"studentId\":$SID,\"viewType\":\"global\",\"findings\":[\"ombros assimétricos\"],\"score\":82,\"notes\":\"reavaliar\"}" "$BASE/postural-assessments" | php -r '$d=json_decode(stream_get_contents(STDIN),true);if(empty($d["ok"]))exit(1);'
curl -fsS -b /tmp/pf-pc.cookie -H "X-CSRF-Token: $CCSR" -H 'Content-Type: application/json' -X PUT -d '{"enabled":true,"metric":"xp"}' "$BASE/ranking/settings" >/dev/null
curl -fsS -b /tmp/pf-pc.cookie "$BASE/fitness/strength-tests?studentId=$SID" | php -r '$d=json_decode(stream_get_contents(STDIN),true);if(($d["tests"][0]["exerciseName"]??"")!=="Supino")exit(1);'
curl -fsS -b /tmp/pf-pc.cookie "$BASE/postural-assessments?studentId=$SID" | php -r '$d=json_decode(stream_get_contents(STDIN),true);if((int)($d["assessments"][0]["score"]??0)!==82)exit(1);'
curl -fsS -b /tmp/pf-pc.cookie "$BASE/ranking" | php -r '$d=json_decode(stream_get_contents(STDIN),true);if(empty($d["enabled"]))exit(1);'
echo "Performance smoke OK: 1RM -> histórico -> postura -> ranking. Student=$SID"
