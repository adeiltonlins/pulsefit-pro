<?php
// PulseFit V13 — relatórios, aderência e inteligência operacional.
function pf_v13_migrate(PDO $pdo):void{
    $pdo->exec('CREATE TABLE IF NOT EXISTS report_snapshots(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL,coach_id INTEGER NOT NULL,period_days INTEGER NOT NULL DEFAULT 30,payload_json TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_student ON report_snapshots(student_id,created_at)');
}
pf_v13_migrate($pdo);

function pf_v13_delta(?float $first,?float $last):?float{return $first===null||$last===null?null:round($last-$first,2);}
function pf_v13_report(PDO $pdo,array $u,int $sid,int $days):array{
    $s=pf_v11_resolve_student($u,$sid);$days=max(7,min(365,$days));
    $since='-'.$days.' days';$prevSince='-'.($days*2).' days';
    $q=$pdo->prepare('SELECT COUNT(*) total,COALESCE(SUM(total_volume),0) volume,COALESCE(SUM(duration_seconds),0) duration FROM workout_sessions WHERE student_id=:sid AND completed_at IS NOT NULL AND started_at>=datetime("now",:since)');$q->execute(['sid'=>$s['id'],'since'=>$since]);$training=$q->fetch();
    $q=$pdo->prepare('SELECT COUNT(*) total,COALESCE(SUM(total_volume),0) volume FROM workout_sessions WHERE student_id=:sid AND completed_at IS NOT NULL AND started_at>=datetime("now",:prev) AND started_at<datetime("now",:since)');$q->execute(['sid'=>$s['id'],'prev'=>$prevSince,'since'=>$since]);$previous=$q->fetch();
    $q=$pdo->prepare('SELECT weight,body_fat AS bodyFat,waist,created_at AS createdAt FROM metrics WHERE student_id=:sid AND created_at>=datetime("now",:since) ORDER BY created_at');$q->execute(['sid'=>$s['id'],'since'=>$since]);$metrics=$q->fetchAll();
    $q=$pdo->prepare('SELECT sleep_hours AS sleepHours,pain_level AS painLevel,fatigue_level AS fatigueLevel,energy_level AS energyLevel,created_at AS createdAt FROM checkins WHERE student_id=:sid AND created_at>=datetime("now",:since) ORDER BY created_at');$q->execute(['sid'=>$s['id'],'since'=>$since]);$checkins=$q->fetchAll();
    $q=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE student_id=:sid AND status="pending" AND date(due_date)<date("now")');$q->execute(['sid'=>$s['id']]);$overdue=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT id,amount_cents AS amountCents,due_date AS dueDate,description FROM payments WHERE student_id=:sid AND status="pending" AND date(due_date)>=date("now") AND date(due_date)<=date("now","+7 days") ORDER BY due_date LIMIT 1');$q->execute(['sid'=>$s['id']]);$upcomingPayment=$q->fetch()?:null;
    $q=$pdo->prepare('SELECT goal_type AS goalType,target_value AS targetValue,target_text AS targetText,unit,status,due_at AS dueAt FROM student_goals WHERE student_id=:sid ORDER BY id DESC LIMIT 20');$q->execute(['sid'=>$s['id']]);$goals=$q->fetchAll();
    $avg=function(string $key)use($checkins){$v=array_values(array_filter(array_map(fn($x)=>isset($x[$key])?(float)$x[$key]:null,$checkins),fn($x)=>$x!==null));return $v?round(array_sum($v)/count($v),1):null;};
    $weeks=max(1,$days/7);$adherence=min(100,round(((int)$training['total'])/($weeks*3)*100));
    $firstMetric=$metrics[0]??[];$lastMetric=$metrics[count($metrics)-1]??[];
    $q=$pdo->prepare('SELECT exercise_name AS exerciseName,MAX(load) AS maxLoad,MAX(reps) AS maxReps,MAX(load*reps) AS bestSetVolume FROM workout_set_logs l JOIN workout_sessions ws ON ws.id=l.session_id WHERE ws.student_id=:sid AND ws.completed_at IS NOT NULL AND l.created_at>=datetime("now",:since) GROUP BY exercise_name ORDER BY maxLoad DESC,bestSetVolume DESC LIMIT 8');$q->execute(['sid'=>$s['id'],'since'=>$since]);$records=$q->fetchAll();
    $alerts=[];
    if($adherence<50)$alerts[]=['type'=>'adherence','severity'=>'high','message'=>'Aderência abaixo de 50% no período.'];
    if(($avg('pain')??0)>=6)$alerts[]=['type'=>'pain','severity'=>'high','message'=>'Média de dor elevada nos check-ins.'];
    if(($avg('fatigue')??0)>=7)$alerts[]=['type'=>'fatigue','severity'=>'high','message'=>'Fadiga média elevada nos check-ins.'];
    if($overdue>0)$alerts[]=['type'=>'payment','severity'=>'medium','message'=>'Há pagamento vencido.'];
    if($upcomingPayment)$alerts[]=['type'=>'payment_due','severity'=>'low','message'=>'Há cobrança vencendo nos próximos 7 dias.'];
    $currentWorkouts=(int)$training['total'];$previousWorkouts=(int)$previous['total'];
    return [
      'student'=>['id'=>$s['id'],'name'=>$s['name'],'programName'=>$s['program_name'],'phase'=>$s['phase']],
      'periodDays'=>$days,'workouts'=>$currentWorkouts,'volume'=>(float)$training['volume'],'durationSeconds'=>(int)$training['duration'],'adherence'=>$adherence,
      'comparison'=>['previousWorkouts'=>$previousWorkouts,'workoutsDelta'=>$currentWorkouts-$previousWorkouts,'previousVolume'=>(float)$previous['volume'],'volumeDelta'=>round((float)$training['volume']-(float)$previous['volume'],2)],
      'metrics'=>$metrics,
      'trends'=>['weightDelta'=>pf_v13_delta(isset($firstMetric['weight'])?(float)$firstMetric['weight']:null,isset($lastMetric['weight'])?(float)$lastMetric['weight']:null),'bodyFatDelta'=>pf_v13_delta(isset($firstMetric['bodyFat'])?(float)$firstMetric['bodyFat']:null,isset($lastMetric['bodyFat'])?(float)$lastMetric['bodyFat']:null),'waistDelta'=>pf_v13_delta(isset($firstMetric['waist'])?(float)$firstMetric['waist']:null,isset($lastMetric['waist'])?(float)$lastMetric['waist']:null)],
      'checkins'=>$checkins,'averages'=>['sleep'=>$avg('sleepHours'),'pain'=>$avg('painLevel'),'fatigue'=>$avg('fatigueLevel'),'energy'=>$avg('energyLevel')],
      'records'=>$records,'goals'=>$goals,'overduePayments'=>$overdue,'upcomingPayment'=>$upcomingPayment,'alerts'=>$alerts
    ];
}
if($method==='GET'&&$route==='/reports/student'){$u=current_user();$sid=(int)($_GET['studentId']??0);if($u['role']==='student'){$s=pf_v11_resolve_student($u);$sid=(int)$s['id'];}$report=pf_v13_report($pdo,$u,$sid,(int)($_GET['days']??30));json_response(['report'=>$report]);}
if($method==='POST'&&$route==='/reports/student'){$u=require_role('coach');verify_csrf();$in=body();$sid=(int)($in['studentId']??0);$days=max(7,min(365,(int)($in['days']??30)));$report=pf_v13_report($pdo,$u,$sid,$days);$q=$pdo->prepare('INSERT INTO report_snapshots(student_id,coach_id,period_days,payload_json) VALUES(:sid,:coach,:days,:payload)');$q->execute(['sid'=>$sid,'coach'=>$u['id'],'days'=>$days,'payload'=>json_encode($report,JSON_UNESCAPED_UNICODE)]);audit($pdo,(int)$u['id'],'create','report',(int)$pdo->lastInsertId(),['studentId'=>$sid,'days'=>$days]);json_response(['ok'=>true,'report'=>$report],201);}
if($method==='GET'&&$route==='/coach/insights'){
    $u=require_role('coach');$q=$pdo->prepare('SELECT COUNT(*) FROM students WHERE coach_id=:c AND archived_at IS NULL');$q->execute(['c'=>$u['id']]);$total=(int)$q->fetchColumn();$q=$pdo->prepare('SELECT COUNT(DISTINCT student_id) FROM workout_sessions ws JOIN students s ON s.id=ws.student_id WHERE s.coach_id=:c AND ws.completed_at IS NOT NULL AND ws.started_at>=datetime("now","-7 days")');$q->execute(['c'=>$u['id']]);$trained=(int)$q->fetchColumn();$q=$pdo->prepare('SELECT COUNT(*) FROM payments p JOIN students s ON s.id=p.student_id WHERE s.coach_id=:c AND p.status="pending" AND date(p.due_date)<date("now")');$q->execute(['c'=>$u['id']]);$overdue=(int)$q->fetchColumn();$q=$pdo->prepare('SELECT COUNT(*) FROM students s WHERE s.coach_id=:c AND s.archived_at IS NULL AND NOT EXISTS(SELECT 1 FROM workout_sessions ws WHERE ws.student_id=s.id AND ws.completed_at IS NOT NULL AND ws.started_at>=datetime("now","-7 days"))');$q->execute(['c'=>$u['id']]);$inactive7d=(int)$q->fetchColumn();json_response(['totalStudents'=>$total,'trainedThisWeek'=>$trained,'weeklyReach'=>$total?round($trained/$total*100):0,'overduePayments'=>$overdue,'inactive7d'=>$inactive7d]);
}

require __DIR__.'/growth.php';
