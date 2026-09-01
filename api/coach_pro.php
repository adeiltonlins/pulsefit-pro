<?php
// PulseFit V11 — Professor Pro: metas, avaliações físicas, documentos e retenção.

function pf_v11_migrate(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS student_goals(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL,goal_type TEXT NOT NULL,target_value REAL,target_text TEXT,unit TEXT,status TEXT NOT NULL DEFAULT "active",starts_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,due_at TEXT,completed_at TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_student_goals_student ON student_goals(student_id,status)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS physical_assessments(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL,coach_id INTEGER NOT NULL,weight REAL,body_fat REAL,chest REAL,waist REAL,abdomen REAL,hip REAL,biceps_left REAL,biceps_right REAL,thigh_left REAL,thigh_right REAL,calf_left REAL,calf_right REAL,notes TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_assessments_student ON physical_assessments(student_id,created_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS student_documents(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL,coach_id INTEGER NOT NULL,title TEXT NOT NULL,file_url TEXT,document_type TEXT NOT NULL DEFAULT "other",notes TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_documents_student ON student_documents(student_id,created_at)');
}
pf_v11_migrate($pdo);

function pf_v11_resolve_student(array $u,?int $sid=null): array {
    if(($u['role']??'')==='student'){
        $q=db()->prepare('SELECT * FROM students WHERE user_id=:uid AND archived_at IS NULL');$q->execute(['uid'=>$u['id']]);$s=$q->fetch();if(!$s)json_response(['error'=>'Aluno não encontrado.'],404);return $s;
    }
    if(!$sid)json_response(['error'=>'Selecione um aluno.'],422);
    return pf_security_student(db(),$sid,$u);
}

if($method==='GET'&&$route==='/coach/retention'){
    $u=require_role('coach');
    $q=$pdo->prepare('SELECT s.id,s.name,s.email,s.status,s.last_check_in AS lastCheckIn,(SELECT MAX(ws.started_at) FROM workout_sessions ws WHERE ws.student_id=s.id) AS lastWorkoutAt,(SELECT MAX(ci.created_at) FROM checkins ci WHERE ci.student_id=s.id) AS lastCheckinAt,(SELECT COUNT(*) FROM payments p WHERE p.student_id=s.id AND p.status="pending" AND date(p.due_date)<date("now")) AS overduePayments,(SELECT AVG(ci.fatigue_level) FROM checkins ci WHERE ci.student_id=s.id AND ci.created_at>=datetime("now","-21 days")) AS avgFatigue,(SELECT AVG(ci.pain_level) FROM checkins ci WHERE ci.student_id=s.id AND ci.created_at>=datetime("now","-21 days")) AS avgPain FROM students s WHERE s.coach_id=:coach AND s.archived_at IS NULL ORDER BY s.name');
    $q->execute(['coach'=>$u['id']]);$rows=[];
    foreach($q->fetchAll() as $r){$score=0;$reasons=[];$lastWorkout=$r['lastWorkoutAt']?strtotime((string)$r['lastWorkoutAt']):0;$lastCheckin=$r['lastCheckinAt']?strtotime((string)$r['lastCheckinAt']):0;if(!$lastWorkout||$lastWorkout<time()-10*86400){$score+=35;$reasons[]='10+ dias sem treino';}elseif($lastWorkout<time()-7*86400){$score+=20;$reasons[]='7+ dias sem treino';}if(!$lastCheckin||$lastCheckin<time()-10*86400){$score+=20;$reasons[]='check-in atrasado';}if((int)$r['overduePayments']>0){$score+=25;$reasons[]='pagamento vencido';}if((float)$r['avgFatigue']>=7){$score+=10;$reasons[]='fadiga alta';}if((float)$r['avgPain']>=6){$score+=10;$reasons[]='dor alta';}$r['riskScore']=min(100,$score);$r['riskLevel']=$score>=60?'high':($score>=30?'medium':'low');$r['reasons']=$reasons;$rows[]=$r;}
    usort($rows,fn($a,$b)=>$b['riskScore']<=>$a['riskScore']);json_response(['students'=>$rows,'highRisk'=>count(array_filter($rows,fn($r)=>$r['riskLevel']==='high')),'mediumRisk'=>count(array_filter($rows,fn($r)=>$r['riskLevel']==='medium'))]);
}

if($method==='GET'&&$route==='/goals'){
    $u=current_user();$s=pf_v11_resolve_student($u,isset($_GET['studentId'])?(int)$_GET['studentId']:null);$q=$pdo->prepare('SELECT id,goal_type AS goalType,target_value AS targetValue,target_text AS targetText,unit,status,starts_at AS startsAt,due_at AS dueAt,completed_at AS completedAt,created_at AS createdAt FROM student_goals WHERE student_id=:sid ORDER BY status="active" DESC,id DESC');$q->execute(['sid'=>$s['id']]);json_response(['goals'=>$q->fetchAll()]);
}
if($method==='POST'&&$route==='/goals'){
    verify_csrf();$u=require_role('coach');$in=body();$s=pf_v11_resolve_student($u,(int)($in['studentId']??0));$type=trim((string)($in['goalType']??''));if($type==='')json_response(['error'=>'Informe o tipo da meta.'],422);$q=$pdo->prepare('INSERT INTO student_goals(student_id,goal_type,target_value,target_text,unit,due_at) VALUES(:sid,:type,:value,:text,:unit,:due)');$q->execute(['sid'=>$s['id'],'type'=>$type,'value'=>isset($in['targetValue'])?(float)$in['targetValue']:null,'text'=>trim((string)($in['targetText']??'')),'unit'=>trim((string)($in['unit']??'')),'due'=>trim((string)($in['dueAt']??''))?:null]);$id=(int)$pdo->lastInsertId();audit($pdo,(int)$u['id'],'create','goal',$id,['studentId'=>$s['id']]);json_response(['ok'=>true,'id'=>$id],201);
}
if($method==='PATCH'&&preg_match('#^/goals/(\d+)$#',$route,$m)){
    verify_csrf();$u=require_role('coach');$id=(int)$m[1];$q=$pdo->prepare('SELECT g.student_id FROM student_goals g JOIN students s ON s.id=g.student_id WHERE g.id=:id AND s.coach_id=:coach');$q->execute(['id'=>$id,'coach'=>$u['id']]);if(!$q->fetch())json_response(['error'=>'Meta não encontrada.'],404);$in=body();$status=in_array(($in['status']??''),['active','completed','cancelled'],true)?$in['status']:'active';$pdo->prepare('UPDATE student_goals SET status=:s,completed_at='.($status==='completed'?'CURRENT_TIMESTAMP':'NULL').',updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['s'=>$status,'id'=>$id]);json_response(['ok'=>true]);
}

if($method==='GET'&&$route==='/assessments'){
    $u=current_user();$s=pf_v11_resolve_student($u,isset($_GET['studentId'])?(int)$_GET['studentId']:null);$q=$pdo->prepare('SELECT id,weight,body_fat AS bodyFat,chest,waist,abdomen,hip,biceps_left AS bicepsLeft,biceps_right AS bicepsRight,thigh_left AS thighLeft,thigh_right AS thighRight,calf_left AS calfLeft,calf_right AS calfRight,notes,created_at AS createdAt FROM physical_assessments WHERE student_id=:sid ORDER BY id DESC');$q->execute(['sid'=>$s['id']]);json_response(['assessments'=>$q->fetchAll()]);
}
if($method==='POST'&&$route==='/assessments'){
    verify_csrf();$u=require_role('coach');$in=body();$s=pf_v11_resolve_student($u,(int)($in['studentId']??0));$q=$pdo->prepare('INSERT INTO physical_assessments(student_id,coach_id,weight,body_fat,chest,waist,abdomen,hip,biceps_left,biceps_right,thigh_left,thigh_right,calf_left,calf_right,notes) VALUES(:sid,:coach,:weight,:bf,:chest,:waist,:abdomen,:hip,:bl,:br,:tl,:tr,:cl,:cr,:notes)');$q->execute(['sid'=>$s['id'],'coach'=>$u['id'],'weight'=>(float)($in['weight']??0),'bf'=>(float)($in['bodyFat']??0),'chest'=>(float)($in['chest']??0),'waist'=>(float)($in['waist']??0),'abdomen'=>(float)($in['abdomen']??0),'hip'=>(float)($in['hip']??0),'bl'=>(float)($in['bicepsLeft']??0),'br'=>(float)($in['bicepsRight']??0),'tl'=>(float)($in['thighLeft']??0),'tr'=>(float)($in['thighRight']??0),'cl'=>(float)($in['calfLeft']??0),'cr'=>(float)($in['calfRight']??0),'notes'=>trim((string)($in['notes']??''))]);$id=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE students SET weight=:w,body_fat=:bf WHERE id=:id')->execute(['w'=>(float)($in['weight']??0),'bf'=>(float)($in['bodyFat']??0),'id'=>$s['id']]);audit($pdo,(int)$u['id'],'create','physical_assessment',$id,['studentId'=>$s['id']]);json_response(['ok'=>true,'id'=>$id],201);
}

if($method==='GET'&&$route==='/documents'){
    $u=current_user();$s=pf_v11_resolve_student($u,isset($_GET['studentId'])?(int)$_GET['studentId']:null);$q=$pdo->prepare('SELECT id,title,file_url AS fileUrl,document_type AS documentType,notes,created_at AS createdAt FROM student_documents WHERE student_id=:sid ORDER BY id DESC');$q->execute(['sid'=>$s['id']]);json_response(['documents'=>$q->fetchAll()]);
}
if($method==='POST'&&$route==='/documents'){
    verify_csrf();$u=require_role('coach');$in=body();$s=pf_v11_resolve_student($u,(int)($in['studentId']??0));$title=trim((string)($in['title']??''));if($title==='')json_response(['error'=>'Informe o título do documento.'],422);$q=$pdo->prepare('INSERT INTO student_documents(student_id,coach_id,title,file_url,document_type,notes) VALUES(:sid,:coach,:title,:url,:type,:notes)');$q->execute(['sid'=>$s['id'],'coach'=>$u['id'],'title'=>$title,'url'=>trim((string)($in['fileUrl']??'')),'type'=>trim((string)($in['documentType']??'other')),'notes'=>trim((string)($in['notes']??''))]);json_response(['ok'=>true,'id'=>(int)$pdo->lastInsertId()],201);
}
