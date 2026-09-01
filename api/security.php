<?php
// PulseFit V10/V15 — segurança central: lockout, troca obrigatória, ownership e níveis admin.

if(!function_exists('pf_security_attempt_key')){
function pf_security_attempt_key(string $email): string {
    return hash('sha256', strtolower(trim($email)).'|'.client_ip_hash());
}
function pf_security_table_exists(PDO $pdo,string $table):bool{
    $q=$pdo->prepare('SELECT 1 FROM sqlite_master WHERE type="table" AND name=:name');$q->execute(['name'=>$table]);return (bool)$q->fetchColumn();
}
function pf_security_admin_level(PDO $pdo,array $u):string{
    if(($u['role']??'')!=='admin')return '';
    if(!pf_security_table_exists($pdo,'admin_profiles'))return 'super_admin';
    $q=$pdo->prepare('SELECT admin_level FROM admin_profiles WHERE user_id=:id');$q->execute(['id'=>$u['id']]);$level=(string)($q->fetchColumn()?:'');
    if($level!=='')return $level;
    $count=(int)$pdo->query('SELECT COUNT(*) FROM admin_profiles')->fetchColumn();return $count===0?'super_admin':'read_only';
}
function pf_security_admin_guard(PDO $pdo,array $u,string $route,string $method):void{
    if(($u['role']??'')!=='admin'||in_array($method,['GET','HEAD'],true))return;
    $level=pf_security_admin_level($pdo,$u);if($level==='super_admin')return;
    if($level==='finance'&&(preg_match('#^/payments(?:/|$)#',$route)||preg_match('#^/admin/coaches/\d+/plan$#',$route)))return;
    if($level==='support'&&(preg_match('#^/students(?:/|$)#',$route)||preg_match('#^/coaches(?:/|$)#',$route)||preg_match('#^/notifications(?:/|$)#',$route)))return;
    json_response(['error'=>'Seu nível administrativo não permite esta ação.'],403);
}
function pf_security_login(PDO $pdo): never {
    $in=body();$email=clean_email($in['email']??'');$password=(string)($in['password']??'');$key=pf_security_attempt_key($email);
    $q=$pdo->prepare('SELECT attempts,first_attempt_at,last_attempt_at,blocked_until FROM login_attempts WHERE attempt_key=:k');$q->execute(['k'=>$key]);$attempt=$q->fetch();
    if($attempt&&!empty($attempt['blocked_until'])&&strtotime((string)$attempt['blocked_until'])>time())json_response(['error'=>'Muitas tentativas de login. Tente novamente em alguns minutos.','code'=>'LOGIN_LOCKED'],429);
    $q=$pdo->prepare('SELECT id,name,email,password_hash,role,code,cref,status,must_change_password AS mustChangePassword FROM users WHERE email=:email LIMIT 1');$q->execute(['email'=>$email]);$u=$q->fetch();
    $valid=$u&&$u['status']==='active'&&password_verify($password,(string)$u['password_hash']);
    if(!$valid){$now=gmdate('Y-m-d H:i:s');$fresh=!$attempt||strtotime((string)($attempt['first_attempt_at']??''))<time()-900;$count=$fresh?1:((int)$attempt['attempts']+1);$blocked=$count>=5?gmdate('Y-m-d H:i:s',time()+900):null;
        $pdo->prepare('INSERT INTO login_attempts(attempt_key,attempts,first_attempt_at,last_attempt_at,blocked_until) VALUES(:k,:a,:first,:last,:blocked) ON CONFLICT(attempt_key) DO UPDATE SET attempts=excluded.attempts,first_attempt_at=excluded.first_attempt_at,last_attempt_at=excluded.last_attempt_at,blocked_until=excluded.blocked_until')->execute(['k'=>$key,'a'=>$count,'first'=>$fresh?$now:(string)$attempt['first_attempt_at'],'last'=>$now,'blocked'=>$blocked]);
        audit($pdo,null,'login_failed','auth',null,['emailHash'=>hash('sha256',$email),'attempts'=>$count,'blocked'=>$blocked!==null]);
        if($blocked)json_response(['error'=>'Muitas tentativas de login. Acesso bloqueado por 15 minutos.','code'=>'LOGIN_LOCKED'],429);
        json_response(['error'=>'E-mail ou senha incorretos.','remainingAttempts'=>max(0,5-$count)],401);
    }
    $pdo->prepare('DELETE FROM login_attempts WHERE attempt_key=:k')->execute(['k'=>$key]);start_secure_session();session_regenerate_id(true);unset($u['password_hash']);$u['mustChangePassword']=(bool)$u['mustChangePassword'];$_SESSION['user']=$u;$_SESSION['csrf']=bin2hex(random_bytes(32));$pdo->prepare('UPDATE users SET last_login_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id'=>$u['id']]);audit($pdo,(int)$u['id'],'login','user',(int)$u['id']);json_response(['user'=>$u,'csrf'=>$_SESSION['csrf']]);
}
function pf_security_student(PDO $pdo,int $studentId,array $user): array {
    if(($user['role']??'')==='admin'){$q=$pdo->prepare('SELECT * FROM students WHERE id=:id');$q->execute(['id'=>$studentId]);}
    elseif(($user['role']??'')==='coach'){$q=$pdo->prepare('SELECT * FROM students WHERE id=:id AND coach_id=:coach');$q->execute(['id'=>$studentId,'coach'=>$user['id']]);}
    else{$q=$pdo->prepare('SELECT * FROM students WHERE id=:id AND user_id=:uid');$q->execute(['id'=>$studentId,'uid'=>$user['id']]);}
    $s=$q->fetch();if(!$s)json_response(['error'=>'Aluno não encontrado ou acesso não permitido.'],404);return $s;
}
function pf_security_validate_body_student(PDO $pdo,array $user): void {
    if(!in_array(($user['role']??''),['coach','admin'],true))return;$in=body();$sid=(int)($in['studentId']??0);if($sid<=0)json_response(['error'=>'Selecione o aluno.'],422);pf_security_student($pdo,$sid,$user);
}
function pf_security_impersonate(PDO $pdo,array $actor):never{
    if(pf_security_admin_level($pdo,$actor)!=='super_admin')json_response(['error'=>'Apenas Super Admin pode visualizar como usuário.'],403);verify_csrf();$in=body();$target=(int)($in['userId']??0);$q=$pdo->prepare('SELECT id,name,email,role,code,cref,status,must_change_password AS mustChangePassword FROM users WHERE id=:id AND status="active" AND archived_at IS NULL');$q->execute(['id'=>$target]);$t=$q->fetch();if(!$t||$t['role']==='admin')json_response(['error'=>'Conta não disponível para visualização.'],422);$_SESSION['original_admin']=$actor;session_regenerate_id(true);$_SESSION['user']=$t;$_SESSION['csrf']=bin2hex(random_bytes(32));audit($pdo,(int)$actor['id'],'impersonate_start','user',$target,['targetRole'=>$t['role']]);json_response(['ok'=>true,'user'=>$t,'csrf'=>$_SESSION['csrf']]);
}
function pf_security_stop_impersonation(PDO $pdo):never{
    verify_csrf();$original=$_SESSION['original_admin']??null;if(!is_array($original))json_response(['error'=>'Nenhuma visualização de suporte ativa.'],409);$target=$_SESSION['user']??[];session_regenerate_id(true);$_SESSION['user']=$original;unset($_SESSION['original_admin']);$_SESSION['csrf']=bin2hex(random_bytes(32));audit($pdo,(int)$original['id'],'impersonate_stop','user',isset($target['id'])?(int)$target['id']:null);json_response(['ok'=>true,'user'=>$original,'csrf'=>$_SESSION['csrf']]);
}
function pf_security_dispatch(): void {
    $route=route_path();$method=$_SERVER['REQUEST_METHOD']??'GET';if($route==='/health')return;$pdo=db();if($method==='POST'&&$route==='/auth/login')pf_security_login($pdo);start_secure_session();$u=$_SESSION['user']??null;
    if(is_array($u)){$q=$pdo->prepare('SELECT status,must_change_password FROM users WHERE id=:id');$q->execute(['id'=>$u['id']]);$fresh=$q->fetch();if(!$fresh||$fresh['status']!=='active'){$_SESSION=[];session_destroy();json_response(['error'=>'Conta desativada.'],401);}$_SESSION['user']['mustChangePassword']=(bool)$fresh['must_change_password'];$u=$_SESSION['user'];if($method==='POST'&&$route==='/admin/impersonate/stop'&&!empty($_SESSION['original_admin']))pf_security_stop_impersonation($pdo);if(!empty($u['mustChangePassword'])&&!in_array($route,['/auth/me','/auth/logout','/auth/change-password'],true))json_response(['error'=>'Altere sua senha temporária antes de continuar.','code'=>'PASSWORD_CHANGE_REQUIRED'],428);pf_security_admin_guard($pdo,$u,$route,$method);if($method==='POST'&&$route==='/admin/impersonate'&&($u['role']??'')==='admin')pf_security_impersonate($pdo,$u);if(preg_match('#^/students/(\d+)/metrics$#',$route,$m))pf_security_student($pdo,(int)$m[1],$u);if(in_array($route,['/appointments','/payments'],true)&&$method==='POST')pf_security_validate_body_student($pdo,$u);}
}
}
if(function_exists('pf_security_dispatch'))pf_security_dispatch();