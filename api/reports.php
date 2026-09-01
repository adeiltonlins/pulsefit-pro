<?php
// PulseFit V13 — relatórios, aderência e inteligência operacional.
function pf_v13_migrate(PDO $pdo):void{
    $pdo->exec('CREATE TABLE IF NOT EXISTS report_snapshots(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL,coach_id INTEGER NOT NULL,period_days INTEGER NOT NULL DEFAULT 30,payload_json TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_student ON report_snapshots(student_id,created_at)');
}
pf_v13_migrate($pdo);
function pf_v13_report(PDO $pdo,array $u,int $sid,int $days):array{
    $s=pf_v11_resolve_student($u,$sid);$days=max(7,min(365,$days));
    $since='-'.$days.' days';
    $q=$pdo->prepare('SELECT COUNT(*) total,COALESCE(SUM(total_volume),0) volume,COALESCE(SUM(duration_seconds),0) duration FROM workout_sessions WHERE student_id=:sid AND completed_at IS NOT NULL AND started_at>=datetime("now",:since)');$q->execute(['sid'=>$s['id'],'since'=>$since]);$training=$q->fetch();
    $q=$pdo->prepare('SELECT weight,body_fat AS bodyFat,waist,created_at AS createdAt FROM metrics WHERE student_id=:sid AND created_at>=datetime("now",:since) ORDER BY created_at');$q->execute(['sid'=>$s['id'],'since'=>$since]);$metrics=$q->fetchAll();
    $q=$pdo->prepare('SELECT sleep_hours AS sleepHours,pain_level AS painLevel,fatigue_level AS fatigueLevel,energy_level AS energyLevel,created_at AS createdAt FROM checkins WHERE student_id=:sid AND created_at>=datetime("now",:since) ORDER BY created_at');$q->execute(['sid'=>$s['id'],'since'=>$since]);$checkins=$q->fetchAll();
    $q=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE student_id=:sid AND status="pending" AND date(due_date)<date("now")');$q->execute(['sid'=>$s['id']]);$overdue=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT goal_type AS goalType,target_value AS targetValue,target_text AS targetText,unit,status,due_at AS dueAt FROM student_goals WHERE student_id=:sid ORDER BY id DESC LIMIT 20');$q->execute(['sid'=>$s['id']]);$goals=$q->fetchAll();
    $avg=function(string $key)use($checkins){$v=array_values(array_filter(array_map(fn($x)=>isset($x[$key])?(float)$x[$key]:null,$checkins),fn($x)=>$x!==null));return $v?round(array_sum($v)/count($v),1):null;};
    $weeks=max(1,$days/7);$adherence=min(100,round(((int)$training['total'])/($weeks*3)*100));
    return ['student'=>['id'=>$s['id'],'name'=>$s['name'],'programName'=>$s['program_name'],'phase'=>$s['phase']],'periodDays'=>$days,'workouts'=>(int)$training['total'],'volume'=>(float)$training['volume'],'durationSeconds'=>(int)$training['duration'],'adherence'=>$adherence,'metrics'=>$metrics,'checkins'=>$checkins,'averages'=>['sleep'=>$avg('sleepHours'),'pain'=>$avg('painLevel'),'fatigue'=>$avg('fatigueLevel'),'energy'=>$avg('energyLevel')],'goals'=>$goals,'overduePayments'=>$overdue];
}
if($method==='GET'&&$route==='/reports/student'){$u=current_user();$sid=(int)($_GET['studentId']??0);if($u['role']==='student'){$s=pf_v11_resolve_student($u);$sid=(int)$s['id'];}$report=pf_v13_report($pdo,$u,$sid,(int)($_GET['days']??30));json_response(['report'=>$report]);}
if($method==='POST'&&$route==='/reports/student'){$u=require_role('coach');verify_csrf();$in=body();$sid=(int)($in['studentId']??0);$days=(int)($in['days']??30);$report=pf_v13_report($pdo,$u,$sid,$days);$q=$pdo->prepare('INSERT INTO report_snapshots(student_id,coach_id,period_days,payload_json) VALUES(:sid,:coach,:days,:payload)');$q->execute(['sid'=>$sid,'coach'=>$u['id'],'days'=>$days,'payload'=>json_encode($report,JSON_UNESCAPED_UNICODE)]);audit($pdo,(int)$u['id'],'create','report',(int)$pdo->lastInsertId(),['studentId'=>$sid,'days'=>$days]);json_response(['ok'=>true,'report'=>$report],201);}
if($method==='GET'&&$route==='/coach/insights'){
    $u=require_role('coach');$q=$pdo->prepare('SELECT COUNT(*) FROM students WHERE coach_id=:c AND archived_at IS NULL');$q->execute(['c'=>$u['id']]);$total=(int)$q->fetchColumn();$q=$pdo->prepare('SELECT COUNT(DISTINCT student_id) FROM workout_sessions ws JOIN students s ON s.id=ws.student_id WHERE s.coach_id=:c AND ws.completed_at IS NOT NULL AND ws.started_at>=datetime("now","-7 days")');$q->execute(['c'=>$u['id']]);$trained=(int)$q->fetchColumn();$q=$pdo->prepare('SELECT COUNT(*) FROM payments p JOIN students s ON s.id=p.student_id WHERE s.coach_id=:c AND p.status="pending" AND date(p.due_date)<date("now")');$q->execute(['c'=>$u['id']]);$overdue=(int)$q->fetchColumn();json_response(['totalStudents'=>$total,'trainedThisWeek'=>$trained,'weeklyReach'=>$total?round($trained/$total*100):0,'overduePayments'=>$overdue]);
}

require __DIR__.'/growth.php';
