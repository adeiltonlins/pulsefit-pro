<?php
// PulseFit V9 — onboarding, planos/trial, LGPD, exportação, cobrança recorrente, PWA/push e suporte seguro.

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
  foreach(['plan.trial.limit'=>'5','plan.basic.limit'=>'20','plan.pro.limit'=>'100','plan.business.limit'=>'500','plan.basic.price_cents'=>'4900','plan.pro.price_cents'=>'9900','plan.business.price_cents'=>'19900'] as $k=>$v){$q=$pdo->prepare('INSERT OR IGNORE INTO app_settings(setting_key,setting_value) VALUES(:k,:v)');$q->execute(['k'=>$k,'v'=>$v]);}
  // Novos treinadores também recebem fim de trial automaticamente.
  $pdo->exec('DROP TRIGGER IF EXISTS trg_coach_profile_trial');
  $pdo->exec('CREATE TRIGGER trg_coach_profile_trial AFTER INSERT ON coach_profiles WHEN NEW.trial_ends_at IS NULL BEGIN UPDATE coach_profiles SET trial_ends_at=datetime("now","+"||COALESCE((SELECT setting_value FROM app_settings WHERE setting_key="trial.days"),"7")||" days") WHERE user_id=NEW.user_id; END');
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
function pf_v9_http_get(string $url,array $headers):array{
  if(!function_exists('curl_init'))json_response(['error'=>'PHP cURL não está habilitado no servidor.'],503);$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=json_decode((string)$raw,true);if($status<200||$status>=300||!is_array($data))json_response(['error'=>'Falha ao consultar gateway.'],502);return $data;
}
function pf_v9_reference(string $ref):array{
  if(preg_match('/^coach:(\d+):plan:(trial|basic|pro|business)$/',$ref,$m))return ['coachId'=>(int)$m[1],'planCode'=>$m[2]];return ['coachId'=>0,'planCode'=>''];
}
function pf_v9_activate_plan(PDO $pdo,int $coach,string $plan,string $gateway,string $external,string $status,array $raw=[]):void{
  if(!$coach||!in_array($plan,['basic','pro','business'],true))return;$mapped=in_array($status,['active','authorized','paid','complete','completed'],true)?'active':(in_array($status,['cancelled','canceled','inactive'],true)?'cancelled':(in_array($status,['past_due','paused'],true)?'past_due':'trialing'));
  $pdo->prepare('UPDATE coach_profiles SET plan_code=:p,subscription_status=:s,billing_gateway=:g,billing_customer_id=:e WHERE user_id=:id')->execute(['p'=>$plan,'s'=>$mapped,'g'=>$gateway,'e'=>$external,'id'=>$coach]);$pdo->prepare('INSERT INTO billing_events(coach_id,gateway,external_id,status,raw_json) VALUES(:c,:g,:e,:s,:r)')->execute(['c'=>$coach,'g'=>$gateway,'e'=>$external,'s'=>$status,'r'=>json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
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

// PWA/push: service worker + armazenamento seguro de subscriptions.
if($method==='GET'&&$route==='/push/config')json_response(['publicKey'=>(string)getenv('VAPID_PUBLIC_KEY'),'enabled'=>(string)getenv('VAPID_PUBLIC_KEY')!=='']);
if($method==='POST'&&$route==='/push/subscriptions'){
  verify_csrf();$u=current_user();$in=body();$endpoint=trim((string)($in['endpoint']??''));if(!filter_var($endpoint,FILTER_VALIDATE_URL))json_response(['error'=>'Subscription inválida.'],422);$keys=$in['keys']??[];$q=$pdo->prepare('INSERT INTO push_subscriptions(user_id,endpoint,p256dh,auth) VALUES(:u,:e,:p,:a) ON CONFLICT(endpoint) DO UPDATE SET user_id=:u,p256dh=:p,auth=:a');$q->execute(['u'=>$u['id'],'e'=>$endpoint,'p'=>(string)($keys['p256dh']??''),'a'=>(string)($keys['auth']??'')]);json_response(['ok'=>true]);
}

// Webhooks de cobrança recorrente. Stripe usa assinatura HMAC; Mercado Pago é confirmado consultando a API autenticada.
if($method==='POST'&&$route==='/billing/webhook/stripe'){
  $raw=file_get_contents('php://input')?:'';$secret=(string)getenv('STRIPE_WEBHOOK_SECRET');if($secret==='')json_response(['error'=>'Webhook Stripe não configurado.'],503);$header=$_SERVER['HTTP_STRIPE_SIGNATURE']??'';$parts=[];foreach(explode(',',$header) as $p){[$k,$v]=array_pad(explode('=',$p,2),2,'');$parts[$k][]=$v;}$ts=(string)($parts['t'][0]??'');$valid=false;foreach(($parts['v1']??[]) as $sig){if($ts&&hash_equals(hash_hmac('sha256',$ts.'.'.$raw,$secret),$sig)){$valid=true;break;}}if(!$valid||abs(time()-(int)$ts)>300)json_response(['error'=>'Assinatura Stripe inválida.'],401);$event=json_decode($raw,true)?:[];$obj=$event['data']['object']??[];$meta=$obj['metadata']??($obj['subscription_details']['metadata']??[]);$ref=(string)($obj['client_reference_id']??'');$parsed=$ref?pf_v9_reference($ref):['coachId'=>(int)($meta['coach_id']??0),'planCode'=>(string)($meta['plan_code']??'')];$type=(string)($event['type']??'');$status=(string)($obj['status']??'');if($type==='checkout.session.completed')$status='active';if($type==='customer.subscription.deleted')$status='cancelled';if($type==='invoice.payment_failed')$status='past_due';if($parsed['coachId'])pf_v9_activate_plan($pdo,$parsed['coachId'],$parsed['planCode'],'stripe',(string)($obj['subscription']??$obj['id']??''),$status,$event);json_response(['received'=>true]);
}
if($method==='POST'&&$route==='/billing/webhook/mercadopago'){
  $raw=file_get_contents('php://input')?:'';$body=json_decode($raw,true)?:[];$id=(string)($body['data']['id']??$_GET['data_id']??'');$token=(string)getenv('MERCADOPAGO_ACCESS_TOKEN');if($id===''||$token==='')json_response(['received'=>true]);$sub=pf_v9_http_get('https://api.mercadopago.com/preapproval/'.rawurlencode($id),['Authorization: Bearer '.$token,'Content-Type: application/json']);$parsed=pf_v9_reference((string)($sub['external_reference']??''));if($parsed['coachId'])pf_v9_activate_plan($pdo,$parsed['coachId'],$parsed['planCode'],'mercadopago',$id,(string)($sub['status']??''),$sub);json_response(['received'=>true]);
}

// Checkout recorrente do treinador. Segredos ficam SOMENTE em variáveis de ambiente da VPS.
if($method==='POST'&&$route==='/billing/checkout'){
  verify_csrf();$u=require_role('coach');$in=body();$gateway=($in['gateway']??'mercadopago')==='stripe'?'stripe':'mercadopago';$plan=in_array(($in['planCode']??''),['basic','pro','business'],true)?$in['planCode']:'basic';$price=(int)setting($pdo,'plan.'.$plan.'.price_cents',$plan==='basic'?'4900':($plan==='pro'?'9900':'19900'));$origin=((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost');$ref='coach:'.$u['id'].':plan:'.$plan;
  if($gateway==='mercadopago'){$token=(string)getenv('MERCADOPAGO_ACCESS_TOKEN');if($token==='')json_response(['error'=>'Mercado Pago ainda não configurado na VPS.'],503);$data=pf_v9_http('https://api.mercadopago.com/preapproval_plan',['Authorization: Bearer '.$token,'Content-Type: application/json'],['reason'=>'PulseFit Pro - '.strtoupper($plan),'external_reference'=>$ref,'auto_recurring'=>['frequency'=>1,'frequency_type'=>'months','transaction_amount'=>$price/100,'currency_id'=>'BRL'],'back_url'=>$origin.'/?billing=success']);$url=$data['init_point']??null;if(!$url)json_response(['error'=>'Mercado Pago não retornou URL de assinatura.'],502);$pdo->prepare('UPDATE coach_profiles SET billing_gateway="mercadopago" WHERE user_id=:id')->execute(['id'=>$u['id']]);$pdo->prepare('INSERT INTO billing_events(coach_id,gateway,external_id,status,amount_cents,raw_json) VALUES(:c,"mercadopago",:e,"created",:a,:r)')->execute(['c'=>$u['id'],'e'=>(string)($data['id']??''),'a'=>$price,'r'=>json_encode($data)]);json_response(['ok'=>true,'checkoutUrl'=>$url,'gateway'=>'mercadopago','recurring'=>true]);}
  $secret=(string)getenv('STRIPE_SECRET_KEY');if($secret==='')json_response(['error'=>'Stripe ainda não configurado na VPS.'],503);$data=pf_v9_http('https://api.stripe.com/v1/checkout/sessions',['Authorization: Bearer '.$secret,'Content-Type: application/x-www-form-urlencoded'],['mode'=>'subscription','success_url'=>$origin.'/?billing=success','cancel_url'=>$origin.'/?billing=cancel','client_reference_id'=>$ref,'customer_email'=>$u['email'],'subscription_data[metadata][coach_id]'=>$u['id'],'subscription_data[metadata][plan_code]'=>$plan,'line_items[0][price_data][currency]'=>'brl','line_items[0][price_data][product_data][name]'=>'PulseFit Pro - '.strtoupper($plan),'line_items[0][price_data][unit_amount]'=>$price,'line_items[0][price_data][recurring][interval]'=>'month','line_items[0][quantity]'=>1],true);if(empty($data['url']))json_response(['error'=>'Stripe não retornou URL de assinatura.'],502);$pdo->prepare('UPDATE coach_profiles SET billing_gateway="stripe" WHERE user_id=:id')->execute(['id'=>$u['id']]);$pdo->prepare('INSERT INTO billing_events(coach_id,gateway,external_id,status,amount_cents,raw_json) VALUES(:c,"stripe",:e,"created",:a,:r)')->execute(['c'=>$u['id'],'e'=>(string)($data['id']??''),'a'=>$price,'r'=>json_encode($data)]);json_response(['ok'=>true,'checkoutUrl'=>$data['url'],'gateway'=>'stripe','recurring'=>true]);
}

// Suporte: visualizar como usuário sem conhecer senha. Apenas Super Admin; sempre auditado.
if($method==='POST'&&$route==='/admin/impersonate'){
  verify_csrf();start_secure_session();$actor=pf_require_admin_level('super_admin');$in=body();$target=(int)($in['userId']??0);$q=$pdo->prepare('SELECT id,name,email,role,code,cref,status,must_change_password AS mustChangePassword FROM users WHERE id=:id AND status="active" AND archived_at IS NULL');$q->execute(['id'=>$target]);$t=$q->fetch();if(!$t||$t['role']==='admin')json_response(['error'=>'Conta não disponível para visualização.'],422);$_SESSION['original_admin']=$actor;$_SESSION['user']=$t;$_SESSION['csrf']=bin2hex(random_bytes(32));audit($pdo,(int)$actor['id'],'impersonate_start','user',$target,['targetRole'=>$t['role']]);json_response(['ok'=>true,'user'=>$t,'csrf'=>$_SESSION['csrf']]);
}
if($method==='POST'&&$route==='/admin/impersonate/stop'){
  verify_csrf();start_secure_session();$original=$_SESSION['original_admin']??null;if(!is_array($original))json_response(['error'=>'Nenhuma visualização de suporte ativa.'],409);$target=$_SESSION['user']??[];$_SESSION['user']=$original;unset($_SESSION['original_admin']);$_SESSION['csrf']=bin2hex(random_bytes(32));audit($pdo,(int)$original['id'],'impersonate_stop','user',isset($target['id'])?(int)$target['id']:null);json_response(['ok'=>true,'user'=>$original,'csrf'=>$_SESSION['csrf']]);
}
