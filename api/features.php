<?php
// Funcionalidades avançadas do PulseFit. Este arquivo é incluído por index.php antes do 404.
require __DIR__.'/management.php';
require __DIR__.'/advanced.php';
require __DIR__.'/commercial.php';
require __DIR__.'/support.php';

function pf_student_context(array $user, ?int $requested = null): array {
    $pdo=db();
    if($user['role']==='student'){
        $q=$pdo->prepare('SELECT * FROM students WHERE user_id=:uid');$q->execute(['uid'=>$user['id']]);$s=$q->fetch();
    }elseif($user['role']==='coach'){
        if(!$requested) json_response(['error'=>'Selecione um aluno.'],422);
        $q=$pdo->prepare('SELECT * FROM students WHERE id=:id AND coach_id=:coach');$q->execute(['id'=>$requested,'coach'=>$user['id']]);$s=$q->fetch();
    }else{
        if(!$requested) json_response(['error'=>'Selecione um aluno.'],422);
        $q=$pdo->prepare('SELECT * FROM students WHERE id=:id');$q->execute(['id'=>$requested]);$s=$q->fetch();
    }
    if(!$s)json_response(['error'=>'Aluno não encontrado.'],404); return $s;
}
function pf_query_student_id(): ?int { return isset($_GET['studentId']) ? (int)$_GET['studentId'] : null; }
function pf_upload(string $field='file'): string {
    if(empty($_FILES[$field])||!is_uploaded_file($_FILES[$field]['tmp_name']))json_response(['error'=>'Arquivo não enviado.'],422);
    $f=$_FILES[$field];if((int)$f['size']>12*1024*1024)json_response(['error'=>'Arquivo maior que 12 MB.'],413);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','video/mp4'=>'mp4','video/webm'=>'webm'];
    if(!isset($allowed[$mime]))json_response(['error'=>'Formato não permitido. Use JPG, PNG, WEBP, GIF, MP4 ou WEBM.'],422);
    $dir=PF_STORAGE.'/uploads';if(!is_dir($dir))mkdir($dir,0750,true);$name=bin2hex(random_bytes(16)).'.'.$allowed[$mime];
    if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$name))json_response(['error'=>'Falha ao salvar mídia.'],500);
    return '/media/'.$name;
}

if($method==='POST' && $route==='/uploads'){
    verify_csrf();current_user();$url=pf_upload();json_response(['ok'=>true,'url'=>$url],201);
}

if($method==='GET' && $route==='/exercise-library'){
    $u=current_user();$coachId=$u['role']==='coach'?(int)$u['id']:null;
    if($u['role']==='student'){$s=pf_student_context($u);$coachId=(int)$s['coach_id'];}
    $q=db()->prepare('SELECT id,name,category,exercise_type AS type,equipment,COALESCE(media_url,"") AS thumbnail,media_type AS mediaType,COALESCE(instructions,"") AS instructions,default_sets AS sets,default_reps AS reps,default_rest AS rest,default_rpe AS rpe,default_tempo AS tempo,is_system AS isSystem FROM exercise_library WHERE is_system=1 OR coach_id=:coach ORDER BY is_system DESC,name');
    $q->execute(['coach'=>$coachId??-1]);json_response(['exercises'=>$q->fetchAll()]);
}
if($method==='POST' && $route==='/exercise-library'){
    verify_csrf();$u=require_role('coach');$in=body();$name=trim((string)($in['name']??''));if(mb_strlen($name)<2)json_response(['error'=>'Informe o nome do exercício.'],422);
    $q=db()->prepare('INSERT INTO exercise_library(coach_id,name,category,exercise_type,equipment,media_url,media_type,instructions,default_sets,default_reps,default_rest,default_rpe,default_tempo,is_system) VALUES(:coach,:name,:category,:type,:equipment,:media,:mediaType,:instructions,:sets,:reps,:rest,:rpe,:tempo,0)');
    $q->execute(['coach'=>$u['id'],'name'=>$name,'category'=>trim((string)($in['category']??'GERAL')),'type'=>trim((string)($in['type']??'ISOLADO')),'equipment'=>trim((string)($in['equipment']??'LIVRE')),'media'=>trim((string)($in['thumbnail']??'')),'mediaType'=>trim((string)($in['mediaType']??'image')),'instructions'=>trim((string)($in['instructions']??'')),'sets'=>max(1,(int)($in['sets']??3)),'reps'=>trim((string)($in['reps']??'10-12')),'rest'=>max(0,(int)($in['rest']??60)),'rpe'=>max(1,min(10,(int)($in['rpe']??8))),'tempo'=>trim((string)($in['tempo']??'2-0-2-0'))]);
    $id=(int)db()->lastInsertId();audit(db(),(int)$u['id'],'create','exercise_library',$id);json_response(['ok'=>true,'id'=>$id],201);
}

if($method==='GET' && $route==='/anamnese'){
    $u=current_user();$s=pf_student_context($u,pf_query_student_id());$q=db()->prepare('SELECT objective,conditions_json AS conditionsJson,injuries,experience,availability,sleep_hours AS sleepHours,stress_level AS stressLevel,notes,updated_at AS updatedAt FROM anamneses WHERE student_id=:id');$q->execute(['id'=>$s['id']]);$a=$q->fetch()?:[];if(isset($a['conditionsJson']))$a['conditions']=json_decode((string)$a['conditionsJson'],true)?:[];unset($a['conditionsJson']);json_response(['anamnese'=>$a,'studentId'=>$s['id']]);
}
if($method==='PUT' && $route==='/anamnese'){
    verify_csrf();$u=current_user();$in=body();$s=pf_student_context($u,isset($in['studentId'])?(int)$in['studentId']:null);$q=db()->prepare('INSERT INTO anamneses(student_id,objective,conditions_json,injuries,experience,availability,sleep_hours,stress_level,notes,updated_at) VALUES(:student,:objective,:conditions,:injuries,:experience,:availability,:sleep,:stress,:notes,CURRENT_TIMESTAMP) ON CONFLICT(student_id) DO UPDATE SET objective=:objective,conditions_json=:conditions,injuries=:injuries,experience=:experience,availability=:availability,sleep_hours=:sleep,stress_level=:stress,notes=:notes,updated_at=CURRENT_TIMESTAMP');$q->execute(['student'=>$s['id'],'objective'=>trim((string)($in['objective']??'')),'conditions'=>json_encode($in['conditions']??[],JSON_UNESCAPED_UNICODE),'injuries'=>trim((string)($in['injuries']??'')),'experience'=>trim((string)($in['experience']??'')),'availability'=>trim((string)($in['availability']??'')),'sleep'=>(float)($in['sleepHours']??0),'stress'=>(int)($in['stressLevel']??0),'notes'=>trim((string)($in['notes']??''))]);audit(db(),(int)$u['id'],'save','anamnese',(int)$s['id']);json_response(['ok'=>true]);
}

if($method==='GET' && $route==='/messages'){
    $u=current_user();$s=pf_student_context($u,pf_query_student_id());$q=db()->prepare('SELECT m.id,m.sender_user_id AS senderUserId,u.role AS senderRole,u.name AS senderName,m.body AS text,m.created_at AS createdAt FROM messages m JOIN users u ON u.id=m.sender_user_id WHERE m.student_id=:student AND m.coach_id=:coach ORDER BY m.id ASC LIMIT 300');$q->execute(['student'=>$s['id'],'coach'=>$s['coach_id']]);json_response(['messages'=>$q->fetchAll(),'student'=>['id'=>$s['id'],'name'=>$s['name']]]);
}
if($method==='POST' && $route==='/messages'){
    verify_csrf();$u=current_user();$in=body();$s=pf_student_context($u,isset($in['studentId'])?(int)$in['studentId']:null);$text=trim((string)($in['text']??''));if($text===''||mb_strlen($text)>3000)json_response(['error'=>'Mensagem inválida.'],422);$q=db()->prepare('INSERT INTO messages(coach_id,student_id,sender_user_id,body) VALUES(:coach,:student,:sender,:body)');$q->execute(['coach'=>$s['coach_id'],'student'=>$s['id'],'sender'=>$u['id'],'body'=>$text]);$other=$u['role']==='student'?(int)$s['coach_id']:(int)$s['user_id'];db()->prepare('INSERT INTO notifications(user_id,title,body) VALUES(:user,"Nova mensagem",:body)')->execute(['user'=>$other,'body'=>mb_substr($text,0,140)]);json_response(['ok'=>true,'id'=>(int)db()->lastInsertId()],201);
}

if($method==='GET' && $route==='/progress'){
    $u=current_user();$s=pf_student_context($u,pf_query_student_id());
    $m=db()->prepare('SELECT id,weight,body_fat AS bodyFat,chest,waist,biceps,thighs,created_at AS createdAt FROM metrics WHERE student_id=:id ORDER BY created_at ASC');$m->execute(['id'=>$s['id']]);
    $p=db()->prepare('SELECT id,file_path AS url,COALESCE(caption,"") AS caption,created_at AS createdAt FROM progress_photos WHERE student_id=:id ORDER BY id DESC');$p->execute(['id'=>$s['id']]);
    $h=db()->prepare('SELECT ws.id,w.title,ws.started_at AS startedAt,ws.completed_at AS completedAt,ws.total_volume AS totalVolume,ws.duration_seconds AS durationSeconds FROM workout_sessions ws JOIN workouts w ON w.id=ws.workout_id WHERE ws.student_id=:id ORDER BY ws.id DESC LIMIT 50');$h->execute(['id'=>$s['id']]);
    json_response(['student'=>['id'=>$s['id'],'name'=>$s['name'],'weight'=>$s['weight'],'bodyFat'=>$s['body_fat']],'metrics'=>$m->fetchAll(),'photos'=>$p->fetchAll(),'sessions'=>$h->fetchAll()]);
}
if($method==='POST' && $route==='/progress/photos'){
    verify_csrf();$u=current_user();$sid=isset($_POST['studentId'])?(int)$_POST['studentId']:null;$s=pf_student_context($u,$sid);$url=pf_upload();$caption=trim((string)($_POST['caption']??''));$q=db()->prepare('INSERT INTO progress_photos(student_id,file_path,caption) VALUES(:student,:path,:caption)');$q->execute(['student'=>$s['id'],'path'=>$url,'caption'=>$caption]);json_response(['ok'=>true,'url'=>$url],201);
}

if($method==='POST' && preg_match('#^/workouts/(\d+)/start$#',$route,$m)){
    verify_csrf();$u=require_role('student');$s=pf_student_context($u);$wid=(int)$m[1];$q=db()->prepare('SELECT id FROM workouts WHERE id=:id AND student_id=:student AND status="published"');$q->execute(['id'=>$wid,'student'=>$s['id']]);if(!$q->fetch())json_response(['error'=>'Treino não encontrado.'],404);$q=db()->prepare('INSERT INTO workout_sessions(workout_id,student_id) VALUES(:workout,:student)');$q->execute(['workout'=>$wid,'student'=>$s['id']]);json_response(['ok'=>true,'sessionId'=>(int)db()->lastInsertId()],201);
}
if($method==='POST' && preg_match('#^/workout-sessions/(\d+)/sets$#',$route,$m)){
    verify_csrf();$u=require_role('student');$s=pf_student_context($u);$session=(int)$m[1];$in=body();$check=db()->prepare('SELECT id FROM workout_sessions WHERE id=:id AND student_id=:student AND completed_at IS NULL');$check->execute(['id'=>$session,'student'=>$s['id']]);if(!$check->fetch())json_response(['error'=>'Sessão inválida.'],404);$reps=max(0,(int)($in['reps']??0));$load=max(0,(float)($in['load']??0));$q=db()->prepare('INSERT INTO workout_set_logs(session_id,exercise_id,exercise_name,set_number,reps,load,rpe) VALUES(:session,:exercise,:name,:setnum,:reps,:load,:rpe)');$q->execute(['session'=>$session,'exercise'=>isset($in['exerciseId'])?(int)$in['exerciseId']:null,'name'=>trim((string)($in['exerciseName']??'')),'setnum'=>max(1,(int)($in['setNumber']??1)),'reps'=>$reps,'load'=>$load,'rpe'=>(float)($in['rpe']??0)]);db()->prepare('UPDATE workout_sessions SET total_volume=total_volume+:volume WHERE id=:id')->execute(['volume'=>$reps*$load,'id'=>$session]);json_response(['ok'=>true],201);
}
if($method==='PATCH' && preg_match('#^/workout-sessions/(\d+)/complete$#',$route,$m)){
    verify_csrf();$u=require_role('student');$s=pf_student_context($u);$in=body();$q=db()->prepare('UPDATE workout_sessions SET completed_at=CURRENT_TIMESTAMP,duration_seconds=:duration WHERE id=:id AND student_id=:student AND completed_at IS NULL');$q->execute(['duration'=>max(0,(int)($in['durationSeconds']??0)),'id'=>(int)$m[1],'student'=>$s['id']]);if(!$q->rowCount())json_response(['error'=>'Sessão não encontrada.'],404);db()->prepare('UPDATE students SET last_check_in=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id'=>$s['id']]);db()->prepare('INSERT INTO notifications(user_id,title,body) VALUES(:user,"Treino concluído",:body)')->execute(['user'=>$s['coach_id'],'body'=>$s['name'].' concluiu um treino.']);json_response(['ok'=>true]);
}
