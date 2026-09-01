<?php
// PulseFit V9 — onboarding, planos/trial, LGPD, exportação, checkout, PWA/push e suporte seguro.

function pf_v9_column(PDO $pdo,string $table,string $column,string $definition):void{
  $cols=array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(),'name');
  if(!in_array($column,$cols,true))$pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}
function pf_v9_migrate(PDO $pdo):void{
  foreach([
    'plan_code'=>'TEXT NOT NULL DEFAULT "trial"',
    'subscription_status'=>'TEXT NOT NULL DEFAULT "trialing"',
    'trial_ends_at'=>'TEXT',
    'onboarding_step'=>'INTEGER NOT NULL DEFAULT 0',
    'onboarding_completed_at'=>'TEXT',
    'billing_gateway'=>'TEXT',
    'billing_customer_id'=>'TEXT'
  ] as $c=>$d)pf_v9_column($pdo,'coach_profiles',$c,$d);
  $pdo->exec('CREATE TABLE IF NOT EXISTS push_subscriptions(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,endpoint TEXT NOT NULL UNIQUE,p256dh TEXT,auth TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS billing_events(id INTEGER PRIMARY KEY AUTOINCREMENT,coach_id INTEGER NOT NULL,gateway TEXT NOT NULL,external_id TEXT,status TEXT,amount_cents INTEGER,raw_json TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS idx_billing_events_coach ON billing_events(coach_id,created_at)');
  $trial=max(1,(int)setting($pdo,'trial.days','7'));
  $q=$pdo->prepare('UPDATE coach_profiles SET trial_ends_at=datetime("now","+"||:days||" days") WHERE trial_ends_at IS NULL');$q->execute(['days'=>$trial]);
  foreach(['plan.trial.limit'=>'5','plan.basic.limit'=>'20','plan.pro.limit'=>'100','plan.business.limit'=>'500'] as $k=>$v){$q=$pdo->prepare('INSERT OR IGNORE INTO app_settings(setting_key,setting_value) VALUES(:k,:v)');$q->execute(['k'=>$k,'v'=>$v]);}
  // Limite real no banco: mesmo chamadas diretas à API respeitam o plano.
  $pdo->exec('DROP TRIGGER IF EXISTS trg_students_plan_limit');
  $pdo->exec('CREATE TRIGGER trg_students_plan_limit BEFORE INSERT ON students BEGIN
    SELECT CASE WHEN
      (SELECT subscription_status FROM coach_profiles WHERE user_id=NEW.coach_id) IN ("expired","cancelled","past_due")
      OR ((SELECT subscription_status FROM coach_profiles WHERE user_id=NEW.coach_id)="trialing" AND datetime((SELECT trial_ends_at FROM coach_profiles WHERE user_id=NEW.coach_id))<datetime("now"))
    THEN RAISE(ABORT,"Plano ou trial indisponível") END;
    SELECT CASE WHEN
      (SELECT COUNT(*) FROM students WHERE coach_id=NEW.coach_id AND archived_at IS NULL) >=
      CAST(COALESCE((SELECT setting_value FROM app_settings WHERE setting_key="plan."||COALESCE((SELECT plan_code FROM coach_profiles WHERE user_id=NEW.coach_id),"trial")||".limit"),"5") AS INTEGER)
    THEN RAISE(ABORT,"Limite de alunos do plano atingido") END;
  END');
}
pf_v9_migrate($pdo);

function pf_v9_coach_plan(PDO $pdo,int $coachId):array{
  $q=$pdo->prepare('SELECT cp.plan_code AS planCode,cp.subscription_status AS subscriptionStatus,cp.trial_ends_at AS trialEndsAt,cp.onboarding_step AS onboardingStep,cp.onboarding_completed_at AS onboardingCompletedAt,cp.billing_gateway AS billingGateway,(SELECT COUNT(*) FROM students s WHERE s.coach_id=cp.user_id AND s.archived_at IS NULL) AS studentsCount FROM coach_profiles cp WHERE cp.user_id=:id');$q->execute(['id'=>$coachId]);$r=$q->fetch()?:[];
  $plan=(string)($r['planCode']??'trial');$r['studentLimit']=(int)setting($pdo,'plan.'.$plan.'.limit',$plan==='trial'?'5':'20');
  $r['trialExpired']=($r['subscriptionStatus']??'')==='trialing'&&!empty($r['trialEndsAt'])&&strtotime((string)$r['trialEndsAt'])<time();return $r;
}
function pf_v9_target_coach(array $u):int{
  if($u['role']==='coach')return (int)$u['id'];
  if($u['role']==='student'){$q=db()->prepare('SELECT coach_id FROM students WHERE user_id=:id');$q->execute(['id'=>$u['id']]);return (int)$q->fetchColumn();}
  return 0;
}
function pf_v9_http(string $url,array $headers,array $payload,bool $form=false):array{
  if(!function_exists('curl_init'))json_response(['error'=>'PHP cURL não está habilitado no servidor.'],503);
  $ch=curl_init($url);$body=$form?http_build_query($payload):json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>$body]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
  if($raw===false||$err)json_response(['error'=>'Falha de comunicação com gateway.'],502);$data=json_decode((string)$raw,true);if($status<200||$status>=300)json_response(['error'=>'Gateway recusou a solicitação.','gatewayStatus'=>$status],502);return is_array($data)?$data:[];
}

// Onboarding guiado e status comercial do treinador.
if($method==='GET'&&$route==='/commercial/status'){
  $u=current_user();$coach=pf_v9_target_coach($u);$status=$coach?pf_v9_coach_plan($pdo,$coach):[];json_response(['status'=>$status,'impersonating'=>!empty($_SESSION['original_admin'])]);
}
if($method==='PATCH'&&$route==='/coach/onboarding'){
  verify_csrf();$u=require_role('coach');$in=body();$step=max(0,min(5,(int)($in['step']??0)));$done=!empty($in['completed']);$pdo->prepare('UPDATE coach_profiles SET onboarding_step=:s,onboarding_completed_at='.($done?'CURRENT_TIMESTAMP':'onboarding_completed_at').' WHERE user_id=:id')->execute(['s'=>$step,'id'=>$u['id']]);audit($pdo,(int)$u['id'],'update','onboarding',(int)$u['id'],['step'=>$step,'completed'=>$done]);json_response(['ok'=>true,'status'=>pf_v9_coach_plan($pdo,(int)$u['id'])]);
}
if($method==='PATCH'&&preg_match('#^/admin/coaches/(\d+)/plan$#',$route,$m)){
  verify_csrf();$actor=pf_require_admin_level('super_admin','finance');$in=body();$plan=in_array(($in['planCode']??''),['trial','basic','pro','business'],true)?$in['planCode']:'basic';$status=in_array(($in['subscriptionStatus']??''),['trialing','active','past_due','cancelled','expired'],true)?$in['subscriptionStatus']:'active';$pdo->prepare('UPDATE coach_profiles SET plan_code=:p,subscription_status=:s WHERE user_id=:id')->execute(['p'=>$plan,'s'=>$status,'id'=>(int)$m[1]]);audit($pdo,(int)$actor['id'],'update_plan','coach',(int)$m[1],['plan'=>$plan,'status'=>$status]);json_response(['ok'=>true]);
}

// LGPD/consentimentos.
if($method==='GET'&&$route==='/privacy/consents'){
  $u=current_user();$q=$pdo->prepare('SELECT id,document_type AS documentType,document_version AS documentVersion,accepted_at AS acceptedAt FROM consents WHERE user_id=:id ORDER BY id DESC');$q->execute(['id'=>$u['id']]);json_response(['consents'=>$q->fetchAll(),'requiredVersion'=>'2026-09']);
}
if($method==='POST'&&$route==='/privacy/consents'){
  verify_csrf();$u=current_user();$in=body();$type=in_array(($in['documentType']??''),['terms','privacy','health_data'],true)?$in['documentType']:'privacy';$version=trim((string)($in['documentVersion']??'2026-09'));$q=$pdo->prepare('INSERT INTO consents(user_id,document_type,document_version,ip_hash) VALUES(:u,:t,:v,:ip)');$q->execute(['u'=>$u['id'],'t'=>$type,'v'=>$version,'ip'=>client_ip_hash()]);audit($pdo,(int)$u['id'],'accept','consent',(int)$pdo->lastInsertId(),['type'=>$type,'version'=>$version]);json_response(['ok'=>true],201);
}

// Portabilidade: exportação completa do aluno autenticado ou pelo treinador/admin autorizado.
if($method==='GET'&&$route==='/privacy/export'){
  $u=current_user();$sid=(int)($_GET['studentId']??0);if($u['role']==='student'){$q=$pdo->prepare('SELECT id FROM students WHERE user_id=:uid');$q->execute(['uid'=>$u['id']]);$sid=(int)$q->fetchColumn();}elseif($u['role']==='coach'){$q=$pdo->prepare('SELECT id FROM students WHERE id=:id AND coach_id=:coach');$q->execute(['id'=>$sid,'coach'=>$u['id']]);if(!$q->fetch())json_response(['error'=>'Aluno não encontrado.'],404);}elseif($u['role']==='admin'&&!$sid)json_response(['error'=>'Informe o aluno.'],422);
  if(!$sid)json_response(['error'=>'Aluno não encontrado.'],404);$tables=['students','metrics','workouts','progress_photos','anamneses','checkins','payments','appointments','workout_sessions'];$out=['exportedAt'=>gmdate('c')];foreach($tables as $t){$col=$t==='students'?'id':'student_id';$q=$pdo->prepare("SELECT * FROM {$t} WHERE {$col}=:id");$q->execute(['id'=>$sid]);$out[$t]=$q->fetchAll();}audit($pdo,(int)$u['id'],'export','student',$sid);json_response(['export'=>$out]);
}

// PWA/push: registro de subscription. O envio remoto usa VAPID externo quando configurado.
if($method==='POST'&&$route==='/push/subscriptions'){
  verify_csrf();$u=current_user();$in=body();$endpoint=trim((string)($in['endpoint']??''));if(!filter_var($endpoint,FILTER_VALIDATE_URL))json_response(['error'=>'Subscription inválida.'],422);$keys=$in['keys']??[];$q=$pdo->prepare('INSERT INTO push_subscriptions(user_id,endpoint,p256dh,auth) VALUES(:u,:e,:p,:a) ON CONFLICT(endpoint) DO UPDATE SET user_id=:u,p256dh=:p,auth=:a');$q->execute(['u'=>$u['id'],'e'=>$endpoint,'p'=>(string)($keys['p256dh']??''),'a'=>(string)($keys['auth']??'')]);json_response(['ok'=>true]);
}

// Checkout comercial do treinador. Segredos ficam SOMENTE em variáveis de ambiente da VPS.
if($method==='POST'&&$route==='/billing/checkout'){
  verify_csrf();$u=require_role('coach');$in=body();$gateway=($in['gateway']??'mercadopago')==='stripe'?'stripe':'mercadopago';$plan=in_array(($in['planCode']??''),['basic','pro','business'],true)?$in['planCode']:'basic';$price=(int)setting($pdo,'plan.'.$plan.'.price_cents',$plan==='basic'?'4900':($plan==='pro'?'9900':'19900'));$origin=((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost');
  if($gateway==='mercadopago'){$token=(string)getenv('MERCADOPAGO_ACCESS_TOKEN');if($token==='')json_response(['error'=>'Mercado Pago ainda não configurado na VPS.'],503);$data=pf_v9_http('https://api.mercadopago.com/checkout/preferences',['Authorization: Bearer '.$token,'Content-Type: application/json'],['items'=>[['title'=>'PulseFit Pro - '.strtoupper($plan),'quantity'=>1,'currency_id'=>'BRL','unit_price'=>$price/100]],'payer'=>['email'=>$u['email']],'external_reference'=>'coach:'.$u['id'].':plan:'.$plan,'back_urls'=>['success'=>$origin.'/','pending'=>$origin.'/','failure'=>$origin.'/'],'auto_return'=>'approved']);$url=$data['init_point']??$data['sandbox_init_point']??null;if(!$url)json_response(['error'=>'Gateway não retornou URL de checkout.'],502);$pdo->prepare('UPDATE coach_profiles SET billing_gateway="mercadopago" WHERE user_id=:id')->execute(['id'=>$u['id']]);json_response(['ok'=>true,'checkoutUrl'=>$url,'gateway'=>'mercadopago']);}
  $secret=(string)getenv('STRIPE_SECRET_KEY');if($secret==='')json_response(['error'=>'Stripe ainda não configurado na VPS.'],503);$data=pf_v9_http('https://api.stripe.com/v1/checkout/sessions',['Authorization: Bearer '.$secret,'Content-Type: application/x-www-form-urlencoded'],['mode'=>'payment','success_url'=>$origin.'/?billing=success','cancel_url'=>$origin.'/?billing=cancel','client_reference_id'=>'coach:'.$u['id'].':plan:'.$plan,'customer_email'=>$u['email'],'line_items[0][price_data][currency]'=>'brl','line_items[0][price_data][product_data][name]'=>'PulseFit Pro - '.strtoupper($plan),'line_items[0][price_data][unit_amount]'=>$price,'line_items[0][quantity]'=>1],true);if(empty($data['url']))json_response(['error'=>'Stripe não retornou URL de checkout.'],502);$pdo->prepare('UPDATE coach_profiles SET billing_gateway="stripe" WHERE user_id=:id')->execute(['id'=>$u['id']]);json_response(['ok'=>true,'checkoutUrl'=>$data['url'],'gateway'=>'stripe']);
}

// Suporte: visualizar como usuário sem conhecer senha. Apenas Super Admin; sempre auditado.
if($method==='POST'&&$route==='/admin/impersonate'){
  verify_csrf();start_secure_session();$actor=pf_require_admin_level('super_admin');$in=body();$target=(int)($in['userId']??0);$q=$pdo->prepare('SELECT id,name,email,role,code,cref,status,must_change_password AS mustChangePassword FROM users WHERE id=:id AND status="active" AND archived_at IS NULL');$q->execute(['id'=>$target]);$t=$q->fetch();if(!$t||$t['role']==='admin')json_response(['error'=>'Conta não disponível para visualização.'],422);$_SESSION['original_admin']=$actor;$_SESSION['user']=$t;$_SESSION['csrf']=bin2hex(random_bytes(32));audit($pdo,(int)$actor['id'],'impersonate_start','user',$target,['targetRole'=>$t['role']]);json_response(['ok'=>true,'user'=>$t,'csrf'=>$_SESSION['csrf']]);
}
if($method==='POST'&&$route==='/admin/impersonate/stop'){
  verify_csrf();start_secure_session();$original=$_SESSION['original_admin']??null;if(!is_array($original))json_response(['error'=>'Nenhuma visualização de suporte ativa.'],409);$target=$_SESSION['user']??[];$_SESSION['user']=$original;unset($_SESSION['original_admin']);$_SESSION['csrf']=bin2hex(random_bytes(32));audit($pdo,(int)$original['id'],'impersonate_stop','user',isset($target['id'])?(int)$target['id']:null);json_response(['ok'=>true,'user'=>$original,'csrf'=>$_SESSION['csrf']]);
}
