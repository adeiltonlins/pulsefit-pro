<?php
// Gestão SaaS: contas, perfis, transferências, acesso, auditoria, financeiro e configurações.

function pf_find_student_for_actor(array $actor,int $id): array {
    $sql='SELECT s.*,u.id AS account_id FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=:id';
    $args=['id'=>$id];
    if($actor['role']==='coach'){$sql.=' AND s.coach_id=:coach';$args['coach']=$actor['id'];}
    $q=db()->prepare($sql);$q->execute($args);$row=$q->fetch();
    if(!$row)json_response(['error'=>'Aluno não encontrado ou sem permissão.'],404);
    return $row;
}
function pf_new_access_password(int $userId): string {
    $pass=temporary_password();
    db()->prepare('UPDATE users SET password_hash=:hash,must_change_password=1 WHERE id=:id')->execute(['hash'=>password_hash($pass,PASSWORD_DEFAULT),'id'=>$userId]);
    db()->prepare('DELETE FROM password_reset_tokens WHERE user_id=:id')->execute(['id'=>$userId]);
    return $pass;
}

if($method==='GET'&&$route==='/admin/overview'){
    require_role('admin');
    $counts=[
      'coaches'=>(int)$pdo->query('SELECT COUNT(*) FROM users WHERE role="coach"')->fetchColumn(),
      'activeCoaches'=>(int)$pdo->query('SELECT COUNT(*) FROM users WHERE role="coach" AND status="active"')->fetchColumn(),
      'students'=>(int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),
      'activeStudents'=>(int)$pdo->query('SELECT COUNT(*) FROM students WHERE status="active"')->fetchColumn(),
      'publishedWorkouts'=>(int)$pdo->query('SELECT COUNT(*) FROM workouts WHERE status="published"')->fetchColumn(),
      'sessions30d'=>(int)$pdo->query('SELECT COUNT(*) FROM workout_sessions WHERE started_at>=datetime("now","-30 days")')->fetchColumn(),
      'pendingCents'=>(int)$pdo->query('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE status="pending"')->fetchColumn(),
      'paidCents30d'=>(int)$pdo->query('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE status="paid" AND paid_at>=datetime("now","-30 days")')->fetchColumn(),
    ];
    $recent=$pdo->query('SELECT a.id,a.action,a.entity_type AS entityType,a.entity_id AS entityId,a.details,a.created_at AS createdAt,COALESCE(u.name,"Sistema") AS actorName FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 30')->fetchAll();
    json_response(['overview'=>$counts,'recentAudit'=>$recent]);
}

if($method==='GET'&&$route==='/audit-logs'){
    require_role('admin');
    $q=$pdo->query('SELECT a.id,a.action,a.entity_type AS entityType,a.entity_id AS entityId,a.details,a.created_at AS createdAt,COALESCE(u.name,"Sistema") AS actorName FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 300');
    json_response(['logs'=>$q->fetchAll()]);
}

if($method==='GET'&&preg_match('#^/students/(\d+)/detail$#',$route,$m)){
    $actor=require_role('admin','coach');$s=pf_find_student_for_actor($actor,(int)$m[1]);
    $q=$pdo->prepare('SELECT id,title,status,created_at AS createdAt,published_at AS publishedAt FROM workouts WHERE student_id=:id ORDER BY id DESC');$q->execute(['id'=>$s['id']]);$workouts=$q->fetchAll();
    $q=$pdo->prepare('SELECT id,amount_cents AS amountCents,due_date AS dueDate,paid_at AS paidAt,status,description FROM payments WHERE student_id=:id ORDER BY id DESC');$q->execute(['id'=>$s['id']]);$payments=$q->fetchAll();
    $q=$pdo->prepare('SELECT id,started_at AS startedAt,completed_at AS completedAt,total_volume AS totalVolume,duration_seconds AS durationSeconds FROM workout_sessions WHERE student_id=:id ORDER BY id DESC LIMIT 20');$q->execute(['id'=>$s['id']]);$sessions=$q->fetchAll();
    json_response(['student'=>$s,'workouts'=>$workouts,'payments'=>$payments,'sessions'=>$sessions]);
}

if($method==='PATCH'&&preg_match('#^/students/(\d+)$#',$route,$m)){
    verify_csrf();$actor=require_role('admin','coach');$s=pf_find_student_for_actor($actor,(int)$m[1]);$in=body();
    $name=trim((string)($in['name']??$s['name']));$email=clean_email($in['email']??$s['email']);if(mb_strlen($name)<3)json_response(['error'=>'Nome inválido.'],422);
    $pdo->beginTransaction();try{
      $pdo->prepare('UPDATE users SET name=:name,email=:email WHERE id=:uid')->execute(['name'=>$name,'email'=>$email,'uid'=>$s['user_id']]);
      $pdo->prepare('UPDATE students SET name=:name,email=:email,program_name=:program,phase=:phase,age=:age,height=:height,weight=:weight,body_fat=:bf,plan_name=:plan,notes=:notes WHERE id=:id')->execute([
        'name'=>$name,'email'=>$email,'program'=>trim((string)($in['programName']??$s['program_name'])),'phase'=>trim((string)($in['phase']??$s['phase'])),'age'=>(int)($in['age']??$s['age']),'height'=>(float)($in['height']??$s['height']),'weight'=>(float)($in['weight']??$s['weight']),'bf'=>(float)($in['bodyFat']??$s['body_fat']),'plan'=>trim((string)($in['planName']??$s['plan_name'])),'notes'=>trim((string)($in['notes']??$s['notes'])),'id'=>$s['id']]);
      $pdo->commit();audit($pdo,(int)$actor['id'],'update','student',(int)$s['id']);json_response(['ok'=>true]);
    }catch(Throwable $e){$pdo->rollBack();json_response(['error'=>str_contains($e->getMessage(),'UNIQUE')?'E-mail já utilizado.':'Falha ao atualizar aluno.'],409);}
}

if($method==='POST'&&preg_match('#^/students/(\d+)/reset-access$#',$route,$m)){
    verify_csrf();$actor=require_role('admin','coach');$s=pf_find_student_for_actor($actor,(int)$m[1]);$pass=pf_new_access_password((int)$s['user_id']);$sent=send_access_email($s['email'],$s['name'],$pass,'student');audit($pdo,(int)$actor['id'],'reset_access','student',(int)$s['id']);json_response(['ok'=>true,'temporaryPassword'=>$pass,'emailSent'=>$sent]);
}

if($method==='PATCH'&&preg_match('#^/students/(\d+)/transfer$#',$route,$m)){
    verify_csrf();$actor=require_role('admin');$id=(int)$m[1];$in=body();$coach=(int)($in['coachId']??0);
    $q=$pdo->prepare('SELECT id FROM users WHERE id=:id AND role="coach" AND status="active"');$q->execute(['id'=>$coach]);if(!$q->fetch())json_response(['error'=>'Treinador de destino inválido.'],422);
    $pdo->beginTransaction();try{$pdo->prepare('UPDATE students SET coach_id=:coach WHERE id=:id')->execute(['coach'=>$coach,'id'=>$id]);$pdo->prepare('UPDATE workouts SET coach_id=:coach WHERE student_id=:id')->execute(['coach'=>$coach,'id'=>$id]);$pdo->prepare('UPDATE appointments SET coach_id=:coach WHERE student_id=:id')->execute(['coach'=>$coach,'id'=>$id]);$pdo->prepare('UPDATE payments SET coach_id=:coach WHERE student_id=:id')->execute(['coach'=>$coach,'id'=>$id]);$pdo->prepare('UPDATE messages SET coach_id=:coach WHERE student_id=:id')->execute(['coach'=>$coach,'id'=>$id]);$pdo->commit();audit($pdo,(int)$actor['id'],'transfer','student',$id,['coachId'=>$coach]);json_response(['ok'=>true]);}catch(Throwable $e){$pdo->rollBack();json_response(['error'=>'Não foi possível transferir o aluno.'],500);}
}

if($method==='DELETE'&&preg_match('#^/students/(\d+)$#',$route,$m)){
    verify_csrf();$actor=require_role('admin','coach');$s=pf_find_student_for_actor($actor,(int)$m[1]);$pdo->beginTransaction();try{$pdo->prepare('DELETE FROM users WHERE id=:id')->execute(['id'=>$s['user_id']]);$pdo->commit();audit($pdo,(int)$actor['id'],'delete','student',(int)$s['id']);json_response(['ok'=>true]);}catch(Throwable $e){$pdo->rollBack();json_response(['error'=>'Falha ao excluir aluno.'],500);}
}

if($method==='PATCH'&&preg_match('#^/coaches/(\d+)$#',$route,$m)){
    verify_csrf();$actor=require_role('admin');$id=(int)$m[1];$in=body();
    $q=$pdo->prepare('SELECT u.*,COALESCE(cp.specialty,"") specialty,COALESCE(cp.unit,"") unit FROM users u LEFT JOIN coach_profiles cp ON cp.user_id=u.id WHERE u.id=:id AND u.role="coach"');$q->execute(['id'=>$id]);$c=$q->fetch();if(!$c)json_response(['error'=>'Treinador não encontrado.'],404);
    $name=trim((string)($in['name']??$c['name']));$email=clean_email($in['email']??$c['email']);
    $pdo->beginTransaction();try{$pdo->prepare('UPDATE users SET name=:name,email=:email,cref=:cref WHERE id=:id')->execute(['name'=>$name,'email'=>$email,'cref'=>trim((string)($in['cref']??$c['cref'])),'id'=>$id]);$pdo->prepare('INSERT INTO coach_profiles(user_id,specialty,unit) VALUES(:id,:specialty,:unit) ON CONFLICT(user_id) DO UPDATE SET specialty=:specialty,unit=:unit')->execute(['id'=>$id,'specialty'=>trim((string)($in['specialty']??$c['specialty'])),'unit'=>trim((string)($in['unit']??$c['unit']))]);$pdo->commit();audit($pdo,(int)$actor['id'],'update','coach',$id);json_response(['ok'=>true]);}catch(Throwable $e){$pdo->rollBack();json_response(['error'=>'Falha ao atualizar treinador.'],500);}
}

if($method==='POST'&&preg_match('#^/coaches/(\d+)/reset-access$#',$route,$m)){
    verify_csrf();$actor=require_role('admin');$id=(int)$m[1];$q=$pdo->prepare('SELECT id,name,email FROM users WHERE id=:id AND role="coach"');$q->execute(['id'=>$id]);$c=$q->fetch();if(!$c)json_response(['error'=>'Treinador não encontrado.'],404);$pass=pf_new_access_password($id);$sent=send_access_email($c['email'],$c['name'],$pass,'coach');audit($pdo,(int)$actor['id'],'reset_access','coach',$id);json_response(['ok'=>true,'temporaryPassword'=>$pass,'emailSent'=>$sent]);
}

if($method==='DELETE'&&preg_match('#^/coaches/(\d+)$#',$route,$m)){
    verify_csrf();$actor=require_role('admin');$id=(int)$m[1];$q=$pdo->prepare('SELECT COUNT(*) FROM students WHERE coach_id=:id');$q->execute(['id'=>$id]);if((int)$q->fetchColumn()>0)json_response(['error'=>'Transfira ou exclua os alunos deste treinador antes de excluir a conta.'],409);$q=$pdo->prepare('DELETE FROM users WHERE id=:id AND role="coach"');$q->execute(['id'=>$id]);if(!$q->rowCount())json_response(['error'=>'Treinador não encontrado.'],404);audit($pdo,(int)$actor['id'],'delete','coach',$id);json_response(['ok'=>true]);
}

if($method==='POST'&&$route==='/auth/change-password'){
    verify_csrf();$u=current_user();$in=body();$current=(string)($in['currentPassword']??'');$new=(string)($in['newPassword']??'');if(strlen($new)<12)json_response(['error'=>'A nova senha precisa ter pelo menos 12 caracteres.'],422);$q=$pdo->prepare('SELECT password_hash FROM users WHERE id=:id');$q->execute(['id'=>$u['id']]);$hash=(string)$q->fetchColumn();if(!password_verify($current,$hash))json_response(['error'=>'Senha atual incorreta.'],422);$pdo->prepare('UPDATE users SET password_hash=:hash,must_change_password=0 WHERE id=:id')->execute(['hash'=>password_hash($new,PASSWORD_DEFAULT),'id'=>$u['id']]);audit($pdo,(int)$u['id'],'change_password','user',(int)$u['id']);json_response(['ok'=>true]);
}

if($method==='PATCH'&&preg_match('#^/payments/(\d+)/status$#',$route,$m)){
    verify_csrf();$u=require_role('admin','coach');$id=(int)$m[1];$in=body();$status=in_array(($in['status']??''),['pending','paid','cancelled'],true)?$in['status']:'pending';$sql='UPDATE payments SET status=:status,paid_at='.($status==='paid'?'CURRENT_TIMESTAMP':'NULL').' WHERE id=:id';$args=['status'=>$status,'id'=>$id];if($u['role']==='coach'){$sql.=' AND coach_id=:coach';$args['coach']=$u['id'];}$q=$pdo->prepare($sql);$q->execute($args);if(!$q->rowCount())json_response(['error'=>'Cobrança não encontrada.'],404);audit($pdo,(int)$u['id'],'payment_'.$status,'payment',$id);json_response(['ok'=>true]);
}

if($method==='POST'&&preg_match('#^/notifications/(\d+)/read$#',$route,$m)){
    verify_csrf();$u=current_user();$pdo->prepare('UPDATE notifications SET read_at=CURRENT_TIMESTAMP WHERE id=:id AND user_id=:user')->execute(['id'=>(int)$m[1],'user'=>$u['id']]);json_response(['ok'=>true]);
}
if($method==='POST'&&$route==='/notifications/read-all'){
    verify_csrf();$u=current_user();$pdo->prepare('UPDATE notifications SET read_at=CURRENT_TIMESTAMP WHERE user_id=:user AND read_at IS NULL')->execute(['user'=>$u['id']]);json_response(['ok'=>true]);
}

if($method==='GET'&&$route==='/admin/settings'){
    require_role('admin');$rows=$pdo->query('SELECT setting_key AS key,setting_value AS value,updated_at AS updatedAt FROM app_settings ORDER BY setting_key')->fetchAll();json_response(['settings'=>$rows]);
}
if($method==='PATCH'&&$route==='/admin/settings'){
    verify_csrf();$u=require_role('admin');$in=body();foreach($in as $key=>$value){if(!preg_match('/^[a-z0-9_.-]{2,80}$/i',(string)$key))continue;$q=$pdo->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_at) VALUES(:key,:value,CURRENT_TIMESTAMP) ON CONFLICT(setting_key) DO UPDATE SET setting_value=:value,updated_at=CURRENT_TIMESTAMP');$q->execute(['key'=>$key,'value'=>is_scalar($value)?(string)$value:json_encode($value,JSON_UNESCAPED_UNICODE)]);}audit($pdo,(int)$u['id'],'update','settings',null);json_response(['ok'=>true]);
}
