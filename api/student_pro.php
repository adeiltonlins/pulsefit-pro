<?php
// PulseFit V12 — Aluno Pro: central pessoal, aderência e conquistas.
function pf_v12_migrate(PDO $pdo):void{
    $pdo->exec('CREATE TABLE IF NOT EXISTS student_achievements(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL,achievement_key TEXT NOT NULL,title TEXT NOT NULL,description TEXT,earned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(student_id,achievement_key),FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievements_student ON student_achievements(student_id,earned_at)');
}
pf_v12_migrate($pdo);
function pf_v12_self(array $u):array{
    $q=db()->prepare('SELECT * FROM students WHERE user_id=:uid AND archived_at IS NULL');$q->execute(['uid'=>$u['id']]);$s=$q->fetch();if(!$s)json_response(['error'=>'Aluno não encontrado.'],404);return $s;
}
function pf_v12_award(PDO $pdo,int $sid,string $key,string $title,string $description):void{$q=$pdo->prepare('INSERT OR IGNORE INTO student_achievements(student_id,achievement_key,title,description) VALUES(:sid,:k,:t,:d)');$q->execute(['sid'=>$sid,'k'=>$key,'t'=>$title,'d'=>$description]);}
function pf_v12_streak(array $dates):int{if(!$dates)return 0;$days=array_values(array_unique(array_map(fn($d)=>date('Y-m-d',strtotime((string)$d)),$dates)));rsort($days);$cursor=new DateTimeImmutable('today');$first=$days[0]??'';if($first!==$cursor->format('Y-m-d')&&$first!==$cursor->modify('-1 day')->format('Y-m-d'))return 0;$count=0;foreach($days as $day){$expected=$cursor->modify('-'.$count.' day')->format('Y-m-d');$alt=$cursor->modify('-'.($count+1).' day')->format('Y-m-d');if($count===0&&$day===$alt){$cursor=$cursor->modify('-1 day');$expected=$cursor->format('Y-m-d');}if($day!==$expected)break;$count++;}return $count;}
function pf_v12_refresh_awards(PDO $pdo,int $sid,int $total,int $week,int $streak):void{
    if($total>=1)pf_v12_award($pdo,$sid,'first_workout','Primeiro treino','Você concluiu seu primeiro treino no PulseFit.');
    if($total>=5)pf_v12_award($pdo,$sid,'five_workouts','5 treinos concluídos','Consistência começando a aparecer.');
    if($total>=20)pf_v12_award($pdo,$sid,'twenty_workouts','20 treinos concluídos','Uma sequência sólida de treino.');
    if($total>=50)pf_v12_award($pdo,$sid,'fifty_workouts','50 treinos concluídos','Cinquenta sessões concluídas com consistência.');
    if($total>=100)pf_v12_award($pdo,$sid,'hundred_workouts','100 treinos concluídos','Marca de 100 treinos alcançada.');
    if($week>=3)pf_v12_award($pdo,$sid,'week_3','Meta semanal 3/3','Três treinos concluídos nesta semana.');
    if($week>=5)pf_v12_award($pdo,$sid,'week_5','Semana de alta consistência','Cinco treinos concluídos em sete dias.');
    if($streak>=3)pf_v12_award($pdo,$sid,'streak_3','Sequência de 3 dias','Três dias seguidos de atividade.');
    if($streak>=7)pf_v12_award($pdo,$sid,'streak_7','Sequência de 7 dias','Sete dias consecutivos de atividade.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM student_goals WHERE student_id=:sid AND status="completed"');$q->execute(['sid'=>$sid]);$goals=(int)$q->fetchColumn();
    if($goals>=1)pf_v12_award($pdo,$sid,'goal_1','Primeira meta concluída','Você concluiu sua primeira meta registrada.');
    if($goals>=5)pf_v12_award($pdo,$sid,'goal_5','5 metas concluídas','Cinco objetivos do acompanhamento foram alcançados.');
}

if($method==='GET'&&$route==='/student/central'){
    $u=require_role('student');$s=pf_v12_self($u);$sid=(int)$s['id'];
    $q=$pdo->prepare('SELECT ws.started_at FROM workout_sessions ws WHERE ws.student_id=:sid AND ws.completed_at IS NOT NULL ORDER BY ws.started_at DESC LIMIT 200');$q->execute(['sid'=>$sid]);$sessionDates=array_column($q->fetchAll(),'started_at');$streak=pf_v12_streak($sessionDates);
    $q=$pdo->prepare('SELECT COUNT(*) FROM workout_sessions WHERE student_id=:sid AND completed_at IS NOT NULL AND started_at>=datetime("now","-7 days")');$q->execute(['sid'=>$sid]);$week=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT COUNT(*) FROM workout_sessions WHERE student_id=:sid AND completed_at IS NOT NULL');$q->execute(['sid'=>$sid]);$total=(int)$q->fetchColumn();
    pf_v12_refresh_awards($pdo,$sid,$total,$week,$streak);
    $q=$pdo->prepare('SELECT id,goal_type AS goalType,target_value AS targetValue,target_text AS targetText,unit,due_at AS dueAt FROM student_goals WHERE student_id=:sid AND status="active" ORDER BY due_at IS NULL,due_at LIMIT 5');$q->execute(['sid'=>$sid]);$goals=$q->fetchAll();
    $q=$pdo->prepare('SELECT id,title,starts_at AS startsAt,status FROM appointments WHERE student_id=:sid AND starts_at>=CURRENT_TIMESTAMP ORDER BY starts_at LIMIT 1');$q->execute(['sid'=>$sid]);$next=$q->fetch()?:null;
    $q=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE student_id=:sid AND status="pending"');$q->execute(['sid'=>$sid]);$pending=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT m.body AS text,m.created_at AS createdAt,u.name AS senderName FROM messages m JOIN users u ON u.id=m.sender_user_id WHERE m.student_id=:sid AND m.sender_user_id!=:uid ORDER BY m.id DESC LIMIT 1');$q->execute(['sid'=>$sid,'uid'=>$u['id']]);$lastMessage=$q->fetch()?:null;
    $q=$pdo->prepare('SELECT id,title,description,earned_at AS earnedAt FROM student_achievements WHERE student_id=:sid ORDER BY id DESC LIMIT 12');$q->execute(['sid'=>$sid]);$achievements=$q->fetchAll();
    $q=$pdo->prepare('SELECT id,title,published_at AS publishedAt FROM workouts WHERE student_id=:sid AND status="published" AND archived_at IS NULL ORDER BY id DESC LIMIT 1');$q->execute(['sid'=>$sid]);$todayWorkout=$q->fetch()?:null;
    $weeklyTarget=3;$weeklyProgress=min(100,round($week/$weeklyTarget*100));
    json_response(['student'=>['id'=>$sid,'name'=>$s['name'],'programName'=>$s['program_name'],'phase'=>$s['phase'],'weight'=>$s['weight'],'bodyFat'=>$s['body_fat']],'weekWorkouts'=>$week,'weeklyTarget'=>$weeklyTarget,'weeklyProgress'=>$weeklyProgress,'totalWorkouts'=>$total,'streak'=>$streak,'goals'=>$goals,'nextAppointment'=>$next,'pendingPayments'=>$pending,'lastCoachMessage'=>$lastMessage,'achievements'=>$achievements,'todayWorkout'=>$todayWorkout]);
}
if($method==='GET'&&$route==='/student/achievements'){$u=require_role('student');$s=pf_v12_self($u);$q=$pdo->prepare('SELECT id,title,description,earned_at AS earnedAt FROM student_achievements WHERE student_id=:sid ORDER BY id DESC');$q->execute(['sid'=>$s['id']]);json_response(['achievements'=>$q->fetchAll()]);}

require __DIR__.'/reports.php';