<?php
// PulseFit V8 — maturidade SaaS: níveis de admin, diretórios filtráveis, ficha 360,
// check-ins, alertas, modelos de treino, histórico detalhado, arquivamento e backups.

function pf_v8_column(PDO $pdo,string $table,string $column,string $definition): void {
    $cols=array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(),'name');
    if(!in_array($column,$cols,true))$pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}
function pf_v8_migrate(PDO $pdo): void {
    pf_v8_column($pdo,'users','archived_at','TEXT');
    pf_v8_column($pdo,'students','archived_at','TEXT');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_profiles(user_id INTEGER PRIMARY KEY,admin_level TEXT NOT NULL DEFAULT "read_only" CHECK(admin_level IN("super_admin","support","finance","read_only")),created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS checkins(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL,weight REAL,sleep_hours REAL,pain_level INTEGER NOT NULL DEFAULT 0,fatigue_level INTEGER NOT NULL DEFAULT 0,energy_level INTEGER NOT NULL DEFAULT 0,notes TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_checkins_student ON checkins(student_id,created_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS workout_templates(id INTEGER PRIMARY KEY AUTOINCREMENT,coach_id INTEGER NOT NULL,name TEXT NOT NULL,description TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS workout_template_exercises(id INTEGER PRIMARY KEY AUTOINCREMENT,template_id INTEGER NOT NULL,position INTEGER NOT NULL DEFAULT 0,library_exercise_id INTEGER,name TEXT NOT NULL,sets INTEGER NOT NULL DEFAULT 3,reps TEXT NOT NULL DEFAULT "10-12",load TEXT,rest_seconds INTEGER NOT NULL DEFAULT 60,notes TEXT,thumbnail TEXT,category TEXT,exercise_type TEXT,equipment TEXT,rpe INTEGER,tempo TEXT,instructions TEXT,FOREIGN KEY(template_id) REFERENCES workout_templates(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_templates_coach ON workout_templates(coach_id,name)');
    $admins=$pdo->query('SELECT id FROM users WHERE role="admin"')->fetchAll();
    $has=(int)$pdo->query('SELECT COUNT(*) FROM admin_profiles')->fetchColumn();
    foreach($admins as $i=>$a){$level=$has===0&&$i===0?'super_admin':'read_only';$q=$pdo->prepare('INSERT OR IGNORE INTO admin_profiles(user_id,admin_level) VALUES(:id,:level)');$q->execute(['id'=>$a['id'],'level'=>$level]);}
}
pf_v8_migrate($pdo);

function pf_admin_level(array $user): string {
    if(($user['role']??'')!=='admin')return '';
    $q=db()->prepare('SELECT admin_level FROM admin_profiles WHERE user_id=:id');$q->execute(['id'=>$user['id']]);
    return (string)($q->fetchColumn()?:'read_only');
}
function pf_require_admin_level(string ...$levels): array {
    $u=require_role('admin');$level=pf_admin_level($u);if(!in_array($level,$levels,true))json_response(['error'=>'Seu nível administrativo não permite esta ação.'],403);$u['adminLevel']=$level;return $u;
}
function pf_admin_route_guard(string $route,string $method): void {
    start_secure_session();$u=$_SESSION['user']??null;if(!is_array($u)||($u['role']??'')!=='admin')return;
    $level=pf_admin_level($u);if($level==='super_admin')return;
    if($method==='GET')return;
    if($level==='finance'&&preg_match('#^/payments/\d+/status$#',$route))return;
    if($level==='support'&&(preg_match('#^/(students|coaches)(/|$)#',$route)||preg_match('#^/admins(/|$)#',$route)))return;
    json_response(['error'=>'Ação bloqueada para o nível administrativo '.$level.'.'],403);
}
pf_admin_route_guard($route,$method);

function pf_actor_student(array $u,?int $requested=null): array {
    if($u['role']==='student'){$q=db()->prepare('SELECT * FROM students WHERE user_id=:uid AND archived_at IS NULL');$q->execute(['uid'=>$u['id']]);}
    elseif($u['role']==='coach'){$q=db()->prepare('SELECT * FROM students WHERE id=:id AND coach_id=:coach AND archived_at IS NULL');$q->execute(['id'=>$requested,'coach'=>$u['id']]);}
    else{$q=db()->prepare('SELECT * FROM students WHERE id=:id');$q->execute(['id'=>$requested]);}
    $s=$q->fetch();if(!$s)json_response(['error'=>'Aluno não encontrado.'],404);return $s;
}
function pf_audit_change(PDO $pdo,array $actor,string $action,string $type,int $id,array $before,array $after): void {audit($pdo,(int)$actor['id'],$action,$type,$id,['before'=>$before,'after'=>$after]);}

// 1 e 2 — gestão e níveis de administradores
if($method==='GET'&&$route==='/admins'){
    pf_require_admin_level('super_admin','support','finance','read_only');
    $rows=$pdo->query('SELECT u.id,u.name,u.email,u.status,u.created_at AS createdAt,u.last_login_at AS lastLoginAt,COALESCE(ap.admin_level,"read_only") AS adminLevel,u.archived_at AS archivedAt FROM users u LEFT JOIN admin_profiles ap ON ap.user_id=u.id WHERE u.role="admin" ORDER BY u.archived_at IS NOT NULL,u.name')->fetchAll();json_response(['admins'=>$rows]);
}
if($method==='POST'&&$route==='/admins'){
    verify_csrf();$actor=pf_require_admin_level('super_admin');$in=body();$name=trim((string)($in['name']??''));$email=clean_email($in['email']??'');$level=(string)($in['adminLevel']??'read_only');if(!in_array($level,['super_admin','support','finance','read_only'],true))$level='read_only';if(mb_strlen($name)<3)json_response(['error'=>'Nome inválido.'],422);$pass=(string)($in['password']??'');if($pass==='')$pass=temporary_password();if(strlen($pass)<12)json_response(['error'=>'Senha inicial precisa ter ao menos 12 caracteres.'],422);
    $pdo->beginTransaction();try{$q=$pdo->prepare('INSERT INTO users(name,email,password_hash,role,status,must_change_password) VALUES(:name,:email,:hash,"admin","active",1)');$q->execute(['name'=>$name,'email'=>$email,'hash'=>password_hash($pass,PASSWORD_DEFAULT)]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO admin_profiles(user_id,admin_level) VALUES(:id,:level)')->execute(['id'=>$id,'level'=>$level]);$pdo->commit();audit($pdo,(int)$actor['id'],'create','admin',$id,['name'=>$name,'level'=>$level]);$sent=send_access_email($email,$name,$pass,'admin');json_response(['ok'=>true,'id'=>$id,'temporaryPassword'=>$pass,'emailSent'=>$sent],201);}catch(Throwable $e){$pdo->rollBack();json_response(['error'=>str_contains($e->getMessage(),'UNIQUE')?'E-mail já utilizado.':'Falha ao criar administrador.'],409);}
}
if($method==='PATCH'&&preg_match('#^/admins/(\d+)$#',$route,$m)){
    verify_csrf();$actor=pf_require_admin_level('super_admin');$id=(int)$m[1];if($id===(int)$actor['id'])json_response(['error'=>'Edite o próprio acesso com cautela; alteração de nível da conta atual foi bloqueada.'],409);$in=body();$q=$pdo->prepare('SELECT u.name,u.email,u.status,ap.admin_level adminLevel FROM users u JOIN admin_profiles ap ON ap.user_id=u.id WHERE u.id=:id AND u.role="admin"');$q->execute(['id'=>$id]);$before=$q->fetch();if(!$before)json_response(['error'=>'Administrador não encontrado.'],404);$name=trim((string)($in['name']??$before['name']));$email=clean_email($in['email']??$before['email']);$level=(string)($in['adminLevel']??$before['adminLevel']);if(!in_array($level,['super_admin','support','finance','read_only'],true))json_response(['error'=>'Nível inválido.'],422);$pdo->beginTransaction();try{$pdo->prepare('UPDATE users SET name=:name,email=:email WHERE id=:id')->execute(['name'=>$name,'email'=>$email,'id'=>$id]);$pdo->prepare('UPDATE admin_profiles SET admin_level=:level,updated_at=CURRENT_TIMESTAMP WHERE user_id=:id')->execute(['level'=>$level,'id'=>$id]);$pdo->commit();pf_audit_change($pdo,$actor,'update','admin',$id,$before,['name'=>$name,'email'=>$email,'adminLevel'=>$level]);json_response(['ok'=>true]);}catch(Throwable $e){$pdo->rollBack();json_response(['error'=>'Falha ao atualizar administrador.'],500);}
}
if($method==='POST'&&preg_match('#^/admins/(\d+)/reset-access$#',$route,$m)){
    verify_csrf();$actor=pf_require_admin_level('super_admin');$id=(int)$m[1];$q=$pdo->prepare('SELECT name,email FROM users WHERE id=:id AND role="admin"');$q->execute(['id'=>$id]);$a=$q->fetch();if(!$a)json_response(['error'=>'Administrador não encontrado.'],404);$pass=pf_new_access_password($id);$sent=send_access_email($a['email'],$a['name'],$pass,'admin');audit($pdo,(int)$actor['id'],'reset_access','admin',$id);json_response(['ok'=>true,'temporaryPassword'=>$pass,'emailSent'=>$sent]);
}

// 3 — diretórios com busca e filtros
if($method==='GET'&&$route==='/directory/students'){
    $u=require_role('admin','coach');$sql='SELECT s.id,s.name,s.email,s.status,s.program_name AS programName,s.phase,s.last_check_in AS lastCheckIn,s.age,s.height,s.weight,s.body_fat AS bodyFat,s.coach_id AS assignedCoachId,c.name AS assignedCoachName,s.plan_name AS planName,s.joined_at AS joinedDate,COALESCE(s.avatar,"") AS avatar,s.archived_at AS archivedAt,(SELECT MAX(ws.started_at) FROM workout_sessions ws WHERE ws.student_id=s.id) AS lastWorkoutAt,(SELECT MAX(ci.created_at) FROM checkins ci WHERE ci.student_id=s.id) AS lastWeeklyCheckin,(SELECT COUNT(*) FROM payments p WHERE p.student_id=s.id AND p.status="pending" AND date(p.due_date)<date("now")) AS overduePayments FROM students s JOIN users c ON c.id=s.coach_id WHERE 1=1';$args=[];if($u['role']==='coach'){$sql.=' AND s.coach_id=:coach';$args['coach']=$u['id'];}
    $arch=$_GET['archived']??'0';$sql.=$arch==='1'?' AND s.archived_at IS NOT NULL':' AND s.archived_at IS NULL';$q=trim((string)($_GET['q']??''));if($q!==''){$sql.=' AND (s.name LIKE :q OR s.email LIKE :q OR s.program_name LIKE :q)';$args['q']='%'.$q.'%';}$status=trim((string)($_GET['status']??''));if($status!==''){$sql.=' AND s.status=:status';$args['status']=$status;}$coachId=(int)($_GET['coachId']??0);if($u['role']==='admin'&&$coachId){$sql.=' AND s.coach_id=:coachFilter';$args['coachFilter']=$coachId;}$plan=trim((string)($_GET['plan']??''));if($plan!==''){$sql.=' AND s.plan_name=:plan';$args['plan']=$plan;}$sql.=' ORDER BY s.name';$st=$pdo->prepare($sql);$st->execute($args);$rows=$st->fetchAll();foreach($rows as &$r){$r['hasOverduePayment']=(int)$r['overduePayments']>0;$r['workoutLate']=empty($r['lastWorkoutAt'])||strtotime((string)$r['lastWorkoutAt'])<time()-7*86400;$r['checkinPending']=empty($r['lastWeeklyCheckin'])||strtotime((string)$r['lastWeeklyCheckin'])<time()-7*86400;}unset($r);$financial=$_GET['financial']??'';$workout=$_GET['workout']??'';if($financial==='overdue')$rows=array_values(array_filter($rows,fn($r)=>$r['hasOverduePayment']));if($workout==='late')$rows=array_values(array_filter($rows,fn($r)=>$r['workoutLate']));json_response(['students'=>$rows]);
}
if($method==='GET'&&$route==='/directory/coaches'){
    pf_require_admin_level('super_admin','support','finance','read_only');$arch=$_GET['archived']??'0';$sql='SELECT u.id,u.code,u.name,u.email,COALESCE(u.cref,"") AS cref,COALESCE(cp.specialty,"") AS specialty,COUNT(s.id) AS studentsCount,u.status,COALESCE(cp.unit,"") AS unit,u.archived_at AS archivedAt FROM users u LEFT JOIN coach_profiles cp ON cp.user_id=u.id LEFT JOIN students s ON s.coach_id=u.id AND s.archived_at IS NULL WHERE u.role="coach"'.($arch==='1'?' AND u.archived_at IS NOT NULL':' AND u.archived_at IS NULL').' GROUP BY u.id ORDER BY u.name';json_response(['coaches'=>$pdo->query($sql)->fetchAll()]);
}

// 4 — ficha completa 360º
if($method==='GET'&&preg_match('#^/students/(\d+)/full$#',$route,$m)){
    $u=require_role('admin','coach');$s=pf_actor_student($u,(int)$m[1]);$sid=(int)$s['id'];
    $queries=[
      'workouts'=>'SELECT id,title,status,created_at AS createdAt,published_at AS publishedAt,archived_at AS archivedAt FROM workouts WHERE student_id=:id ORDER BY id DESC',
      'metrics'=>'SELECT id,weight,body_fat AS bodyFat,chest,waist,biceps,thighs,created_at AS createdAt FROM metrics WHERE student_id=:id ORDER BY id DESC',
      'photos'=>'SELECT id,file_path AS url,caption,created_at AS createdAt FROM progress_photos WHERE student_id=:id ORDER BY id DESC',
      'appointments'=>'SELECT id,title,starts_at AS startsAt,ends_at AS endsAt,status,notes FROM appointments WHERE student_id=:id ORDER BY starts_at DESC',
      'payments'=>'SELECT id,amount_cents AS amountCents,due_date AS dueDate,paid_at AS paidAt,status,description FROM payments WHERE student_id=:id ORDER BY due_date DESC',
      'sessions'=>'SELECT ws.id,w.title,ws.started_at AS startedAt,ws.completed_at AS completedAt,ws.total_volume AS totalVolume,ws.duration_seconds AS durationSeconds FROM workout_sessions ws JOIN workouts w ON w.id=ws.workout_id WHERE ws.student_id=:id ORDER BY ws.id DESC LIMIT 100',
      'checkins'=>'SELECT id,weight,sleep_hours AS sleepHours,pain_level AS painLevel,fatigue_level AS fatigueLevel,energy_level AS energyLevel,notes,created_at AS createdAt FROM checkins WHERE student_id=:id ORDER BY id DESC LIMIT 50'
    ];$out=['student'=>$s];foreach($queries as $key=>$sql){$q=$pdo->prepare($sql);$q->execute(['id'=>$sid]);$out[$key]=$q->fetchAll();}$q=$pdo->prepare('SELECT objective,injuries,experience,availability,sleep_hours AS sleepHours,stress_level AS stressLevel,notes,updated_at AS updatedAt FROM anamneses WHERE student_id=:id');$q->execute(['id'=>$sid]);$out['anamnese']=$q->fetch()?:null;$q=$pdo->prepare('SELECT a.id,a.action,a.entity_type AS entityType,a.details,a.created_at AS createdAt,COALESCE(u.name,"Sistema") actorName FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id WHERE (a.entity_type="student" AND a.entity_id=:id) ORDER BY a.id DESC LIMIT 100');$q->execute(['id'=>$sid]);$out['history']=$q->fetchAll();json_response($out);
}

// 5 — check-in semanal
if($method==='GET'&&$route==='/checkins'){
    $u=current_user();$sid=isset($_GET['studentId'])?(int)$_GET['studentId']:null;$s=pf_actor_student($u,$sid);$q=$pdo->prepare('SELECT id,weight,sleep_hours AS sleepHours,pain_level AS painLevel,fatigue_level AS fatigueLevel,energy_level AS energyLevel,notes,created_at AS createdAt FROM checkins WHERE student_id=:id ORDER BY id DESC LIMIT 50');$q->execute(['id'=>$s['id']]);json_response(['student'=>['id'=>$s['id'],'name'=>$s['name']],'checkins'=>$q->fetchAll()]);
}
if($method==='POST'&&$route==='/checkins'){
    verify_csrf();$u=require_role('student');$s=pf_actor_student($u);$in=body();$q=$pdo->prepare('INSERT INTO checkins(student_id,weight,sleep_hours,pain_level,fatigue_level,energy_level,notes) VALUES(:student,:weight,:sleep,:pain,:fatigue,:energy,:notes)');$q->execute(['student'=>$s['id'],'weight'=>(float)($in['weight']??0),'sleep'=>(float)($in['sleepHours']??0),'pain'=>max(0,min(10,(int)($in['painLevel']??0))),'fatigue'=>max(0,min(10,(int)($in['fatigueLevel']??0))),'energy'=>max(0,min(10,(int)($in['energyLevel']??0))),'notes'=>trim((string)($in['notes']??''))]);$pdo->prepare('UPDATE students SET last_check_in=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id'=>$s['id']]);$pdo->prepare('INSERT INTO notifications(user_id,title,body) VALUES(:user,"Novo check-in semanal",:body)')->execute(['user'=>$s['coach_id'],'body'=>$s['name'].' enviou o check-in semanal.']);audit($pdo,(int)$u['id'],'create','checkin',(int)$pdo->lastInsertId(),['studentId'=>$s['id']]);json_response(['ok'=>true],201);
}

// 6 — alertas inteligentes
if($method==='GET'&&$route==='/alerts'){
    $u=require_role('admin','coach');$sql='SELECT s.id,s.name,s.coach_id,c.name coachName,(SELECT MAX(ws.started_at) FROM workout_sessions ws WHERE ws.student_id=s.id) lastWorkoutAt,(SELECT MAX(ci.created_at) FROM checkins ci WHERE ci.student_id=s.id) lastCheckinAt,(SELECT COUNT(*) FROM payments p WHERE p.student_id=s.id AND p.status="pending" AND date(p.due_date)<date("now")) overdue FROM students s JOIN users c ON c.id=s.coach_id WHERE s.archived_at IS NULL AND s.status="active"';$args=[];if($u['role']==='coach'){$sql.=' AND s.coach_id=:coach';$args['coach']=$u['id'];}$q=$pdo->prepare($sql);$q->execute($args);$alerts=[];foreach($q->fetchAll() as $r){if(empty($r['lastWorkoutAt'])||strtotime((string)$r['lastWorkoutAt'])<time()-7*86400)$alerts[]=['type'=>'workout','severity'=>'warning','studentId'=>$r['id'],'studentName'=>$r['name'],'message'=>$r['name'].' está há 7 dias ou mais sem registrar treino.'];if(empty($r['lastCheckinAt'])||strtotime((string)$r['lastCheckinAt'])<time()-7*86400)$alerts[]=['type'=>'checkin','severity'=>'warning','studentId'=>$r['id'],'studentName'=>$r['name'],'message'=>$r['name'].' ainda não enviou o check-in semanal.'];if((int)$r['overdue']>0)$alerts[]=['type'=>'payment','severity'=>'critical','studentId'=>$r['id'],'studentName'=>$r['name'],'message'=>$r['name'].' possui pagamento vencido.'];}json_response(['alerts'=>$alerts]);
}

// 7 — modelos de treino
if($method==='GET'&&$route==='/workout-templates'){
    $u=require_role('coach');$q=$pdo->prepare('SELECT id,name,description,created_at AS createdAt,updated_at AS updatedAt FROM workout_templates WHERE coach_id=:coach ORDER BY name');$q->execute(['coach'=>$u['id']]);$rows=$q->fetchAll();$ex=$pdo->prepare('SELECT id,library_exercise_id AS libraryExerciseId,name,sets,reps,load,rest_seconds AS rest,notes,thumbnail,category,exercise_type AS type,equipment,rpe,tempo,instructions FROM workout_template_exercises WHERE template_id=:id ORDER BY position');foreach($rows as &$r){$ex->execute(['id'=>$r['id']]);$r['exercises']=$ex->fetchAll();}json_response(['templates'=>$rows]);
}
if($method==='POST'&&$route==='/workout-templates'){
    verify_csrf();$u=require_role('coach');$in=body();$name=trim((string)($in['name']??''));$xs=$in['exercises']??[];if(mb_strlen($name)<3||!is_array($xs)||!count($xs))json_response(['error'=>'Informe nome e exercícios do modelo.'],422);$pdo->beginTransaction();try{$q=$pdo->prepare('INSERT INTO workout_templates(coach_id,name,description) VALUES(:coach,:name,:description)');$q->execute(['coach'=>$u['id'],'name'=>$name,'description'=>trim((string)($in['description']??''))]);$id=(int)$pdo->lastInsertId();$add=$pdo->prepare('INSERT INTO workout_template_exercises(template_id,position,library_exercise_id,name,sets,reps,load,rest_seconds,notes,thumbnail,category,exercise_type,equipment,rpe,tempo,instructions) VALUES(:template,:position,:lib,:name,:sets,:reps,:load,:rest,:notes,:thumb,:cat,:type,:eq,:rpe,:tempo,:instructions)');foreach(array_slice($xs,0,60) as $i=>$x)$add->execute(['template'=>$id,'position'=>$i,'lib'=>isset($x['libraryExerciseId'])?(int)$x['libraryExerciseId']:null,'name'=>trim((string)($x['name']??'')),'sets'=>max(1,(int)($x['sets']??3)),'reps'=>trim((string)($x['reps']??'10-12')),'load'=>trim((string)($x['load']??'')),'rest'=>max(0,(int)($x['rest']??60)),'notes'=>trim((string)($x['notes']??'')),'thumb'=>trim((string)($x['thumbnail']??'')),'cat'=>trim((string)($x['category']??'')),'type'=>trim((string)($x['type']??'')),'eq'=>trim((string)($x['equipment']??'')),'rpe'=>max(1,min(10,(int)($x['rpe']??8))),'tempo'=>trim((string)($x['tempo']??'2-0-2-0')),'instructions'=>trim((string)($x['instructions']??''))]);$pdo->commit();audit($pdo,(int)$u['id'],'create','workout_template',$id,['name'=>$name]);json_response(['ok'=>true,'id'=>$id],201);}catch(Throwable $e){$pdo->rollBack();json_response(['error'=>'Falha ao criar modelo.'],500);}
}
if($method==='POST'&&preg_match('#^/workout-templates/(\d+)/apply$#',$route,$m)){
    verify_csrf();$u=require_role('coach');$template=(int)$m[1];$in=body();$sid=(int)($in['studentId']??0);$q=$pdo->prepare('SELECT * FROM workout_templates WHERE id=:id AND coach_id=:coach');$q->execute(['id'=>$template,'coach'=>$u['id']]);$t=$q->fetch();if(!$t)json_response(['error'=>'Modelo não encontrado.'],404);$q=$pdo->prepare('SELECT id FROM students WHERE id=:id AND coach_id=:coach AND archived_at IS NULL');$q->execute(['id'=>$sid,'coach'=>$u['id']]);if(!$q->fetch())json_response(['error'=>'Aluno inválido.'],404);$pdo->beginTransaction();try{$pdo->prepare('INSERT INTO workouts(student_id,coach_id,title,status) VALUES(:student,:coach,:title,"draft")')->execute(['student'=>$sid,'coach'=>$u['id'],'title'=>$t['name']]);$wid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO exercises(workout_id,position,library_exercise_id,name,sets,reps,load,rest_seconds,notes,thumbnail,category,exercise_type,equipment,rpe,tempo,instructions) SELECT :workout,position,library_exercise_id,name,sets,reps,load,rest_seconds,notes,thumbnail,category,exercise_type,equipment,rpe,tempo,instructions FROM workout_template_exercises WHERE template_id=:template')->execute(['workout'=>$wid,'template'=>$template]);$pdo->commit();audit($pdo,(int)$u['id'],'apply_template','workout',$wid,['templateId'=>$template,'studentId'=>$sid]);json_response(['ok'=>true,'workoutId'=>$wid],201);}catch(Throwable $e){$pdo->rollBack();json_response(['error'=>'Falha ao aplicar modelo.'],500);}
}
if($method==='DELETE'&&preg_match('#^/workout-templates/(\d+)$#',$route,$m)){
    verify_csrf();$u=require_role('coach');$q=$pdo->prepare('DELETE FROM workout_templates WHERE id=:id AND coach_id=:coach');$q->execute(['id'=>(int)$m[1],'coach'=>$u['id']]);json_response(['ok'=>true]);
}

// 8 — histórico detalhado para transferência
if($method==='PATCH'&&preg_match('#^/students/(\d+)/transfer$#',$route,$m)){
    verify_csrf();$actor=pf_require_admin_level('super_admin','support');$id=(int)$m[1];$in=body();$newCoach=(int)($in['coachId']??0);$q=$pdo->prepare('SELECT s.coach_id,c.name coachName,s.name studentName FROM students s JOIN users c ON c.id=s.coach_id WHERE s.id=:id');$q->execute(['id'=>$id]);$before=$q->fetch();if(!$before)json_response(['error'=>'Aluno não encontrado.'],404);$q=$pdo->prepare('SELECT id,name FROM users WHERE id=:id AND role="coach" AND status="active" AND archived_at IS NULL');$q->execute(['id'=>$newCoach]);$dest=$q->fetch();if(!$dest)json_response(['error'=>'Treinador de destino inválido.'],422);$pdo->beginTransaction();try{foreach(['students'=>'coach_id','workouts'=>'coach_id','appointments'=>'coach_id','payments'=>'coach_id','messages'=>'coach_id'] as $table=>$col){$pdo->prepare("UPDATE {$table} SET {$col}=:coach WHERE ".($table==='students'?'id':'student_id').'=:student')->execute(['coach'=>$newCoach,'student'=>$id]);}$pdo->commit();audit($pdo,(int)$actor['id'],'transfer','student',$id,['student'=>$before['studentName'],'from'=>['id'=>$before['coach_id'],'name'=>$before['coachName']],'to'=>['id'=>$dest['id'],'name'=>$dest['name']]]);json_response(['ok'=>true]);}catch(Throwable $e){$pdo->rollBack();json_response(['error'=>'Falha ao transferir aluno.'],500);}
}

// 9 — soft delete / arquivados; exclusão definitiva só Super Admin
if($method==='POST'&&preg_match('#^/students/(\d+)/(archive|restore)$#',$route,$m)){
    verify_csrf();$u=require_role('admin','coach');$s=pf_actor_student($u,(int)$m[1]);$archive=$m[2]==='archive';$pdo->beginTransaction();try{$pdo->prepare('UPDATE students SET archived_at='.($archive?'CURRENT_TIMESTAMP':'NULL').',status=:status WHERE id=:id')->execute(['status'=>$archive?'inactive':'active','id'=>$s['id']]);$pdo->prepare('UPDATE users SET archived_at='.($archive?'CURRENT_TIMESTAMP':'NULL').',status=:status WHERE id=:id')->execute(['status'=>$archive?'inactive':'active','id'=>$s['user_id']]);$pdo->commit();audit($pdo,(int)$u['id'],$archive?'archive':'restore','student',(int)$s['id'],['name'=>$s['name']]);json_response(['ok'=>true]);}catch(Throwable $e){$pdo->rollBack();json_response(['error'=>'Falha ao alterar arquivo.'],500);}
}
if($method==='DELETE'&&preg_match('#^/students/(\d+)$#',$route))json_response(['error'=>'Use Arquivar. Exclusão definitiva fica na área Arquivados do Super Admin.'],409);
if($method==='DELETE'&&preg_match('#^/students/(\d+)/purge$#',$route,$m)){
    verify_csrf();$actor=pf_require_admin_level('super_admin');$q=$pdo->prepare('SELECT user_id,name,archived_at FROM students WHERE id=:id');$q->execute(['id'=>(int)$m[1]]);$s=$q->fetch();if(!$s||!$s['archived_at'])json_response(['error'=>'A conta precisa estar arquivada antes da exclusão definitiva.'],409);$pdo->prepare('DELETE FROM users WHERE id=:id')->execute(['id'=>$s['user_id']]);audit($pdo,(int)$actor['id'],'purge','student',(int)$m[1],['name'=>$s['name']]);json_response(['ok'=>true]);
}
if($method==='POST'&&preg_match('#^/coaches/(\d+)/(archive|restore)$#',$route,$m)){
    verify_csrf();$actor=pf_require_admin_level('super_admin','support');$id=(int)$m[1];$archive=$m[2]==='archive';if($archive){$q=$pdo->prepare('SELECT COUNT(*) FROM students WHERE coach_id=:id AND archived_at IS NULL');$q->execute(['id'=>$id]);if((int)$q->fetchColumn()>0)json_response(['error'=>'Transfira ou arquive os alunos ativos antes de arquivar o treinador.'],409);}$pdo->prepare('UPDATE users SET archived_at='.($archive?'CURRENT_TIMESTAMP':'NULL').',status=:status WHERE id=:id AND role="coach"')->execute(['status'=>$archive?'inactive':'active','id'=>$id]);audit($pdo,(int)$actor['id'],$archive?'archive':'restore','coach',$id);json_response(['ok'=>true]);
}
if($method==='DELETE'&&preg_match('#^/coaches/(\d+)$#',$route))json_response(['error'=>'Use Arquivar. Exclusão definitiva fica na área Arquivados do Super Admin.'],409);
if($method==='DELETE'&&preg_match('#^/coaches/(\d+)/purge$#',$route,$m)){
    verify_csrf();$actor=pf_require_admin_level('super_admin');$id=(int)$m[1];$q=$pdo->prepare('SELECT name,archived_at FROM users WHERE id=:id AND role="coach"');$q->execute(['id'=>$id]);$c=$q->fetch();if(!$c||!$c['archived_at'])json_response(['error'=>'Treinador precisa estar arquivado.'],409);$q=$pdo->prepare('SELECT COUNT(*) FROM students WHERE coach_id=:id');$q->execute(['id'=>$id]);if((int)$q->fetchColumn()>0)json_response(['error'=>'Ainda existem alunos vinculados.'],409);$pdo->prepare('DELETE FROM users WHERE id=:id')->execute(['id'=>$id]);audit($pdo,(int)$actor['id'],'purge','coach',$id,['name'=>$c['name']]);json_response(['ok'=>true]);
}

// 10 — backup manual pelo Super Admin
if($method==='GET'&&$route==='/admin/backups'){
    pf_require_admin_level('super_admin');$dir=PF_STORAGE.'/backups';$files=[];foreach(glob($dir.'/pulsefit-*.sqlite')?:[] as $f)$files[]=['name'=>basename($f),'size'=>filesize($f),'createdAt'=>date(DATE_ATOM,filemtime($f))];usort($files,fn($a,$b)=>strcmp($b['createdAt'],$a['createdAt']));json_response(['backups'=>$files]);
}
if($method==='POST'&&$route==='/admin/backups'){
    verify_csrf();$actor=pf_require_admin_level('super_admin');$dir=PF_STORAGE.'/backups';if(!is_dir($dir))mkdir($dir,0750,true);$file=$dir.'/pulsefit-manual-'.gmdate('Y-m-d-His').'.sqlite';$quoted=str_replace("'","''",$file);try{$pdo->exec("VACUUM INTO '{$quoted}'");audit($pdo,(int)$actor['id'],'create','backup',null,['file'=>basename($file)]);json_response(['ok'=>true,'name'=>basename($file),'size'=>filesize($file)],201);}catch(Throwable $e){json_response(['error'=>'Falha ao gerar backup.'],500);}
}
