<?php
// Complemento de suporte: impersonação por ID de aluno e retorno seguro ao Super Admin.
if($method==='POST'&&$route==='/admin/impersonate-student'){
  verify_csrf();start_secure_session();$actor=pf_require_admin_level('super_admin');$in=body();$studentId=(int)($in['studentId']??0);
  $q=$pdo->prepare('SELECT u.id,u.name,u.email,u.role,u.code,u.cref,u.status,u.must_change_password AS mustChangePassword FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=:sid AND s.archived_at IS NULL AND u.status="active"');$q->execute(['sid'=>$studentId]);$target=$q->fetch();
  if(!$target)json_response(['error'=>'Aluno indisponível para visualização.'],404);
  $_SESSION['original_admin']=$actor;$_SESSION['user']=$target;$_SESSION['csrf']=bin2hex(random_bytes(32));audit($pdo,(int)$actor['id'],'impersonate_start','student',$studentId,['targetUserId'=>$target['id']]);json_response(['ok'=>true,'user'=>$target,'csrf'=>$_SESSION['csrf']]);
}
