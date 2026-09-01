<?php
// PulseFit — operações consolidadas do Admin Master.

if($method==='GET'&&$route==='/admin/master/coaches'){
    pf_require_admin_level('super_admin','support','finance','read_only');
    $sql='SELECT u.id,u.name,u.email,u.status,u.cref,u.created_at AS createdAt,u.last_login_at AS lastLoginAt,u.archived_at AS archivedAt,
      COALESCE(cp.plan_code,"trial") AS planCode,COALESCE(cp.subscription_status,"trialing") AS subscriptionStatus,cp.trial_ends_at AS trialEndsAt,
      COALESCE(cp.billing_gateway,"") AS billingGateway,COALESCE(cp.billing_customer_id,"") AS billingCustomerId,
      COALESCE(cp.specialty,"") AS specialty,COALESCE(cp.unit,"") AS unit,
      (SELECT COUNT(*) FROM students s WHERE s.coach_id=u.id AND s.archived_at IS NULL) AS studentsCount,
      (SELECT COUNT(*) FROM coach_leads l WHERE l.coach_id=u.id AND l.created_at>=datetime("now","-30 days")) AS leads30d,
      (SELECT COUNT(*) FROM workout_sessions ws JOIN students s2 ON s2.id=ws.student_id WHERE s2.coach_id=u.id AND ws.completed_at IS NOT NULL AND ws.started_at>=datetime("now","-30 days")) AS workouts30d
      FROM users u LEFT JOIN coach_profiles cp ON cp.user_id=u.id WHERE u.role="coach" ORDER BY u.archived_at IS NOT NULL,u.name';
    $rows=$pdo->query($sql)->fetchAll();
    foreach($rows as &$r){$plan=(string)($r['planCode']??'trial');$r['studentLimit']=(int)setting($pdo,'plan.'.$plan.'.limit',$plan==='trial'?'5':'20');$r['trialExpired']=($r['subscriptionStatus']??'')==='trialing'&&!empty($r['trialEndsAt'])&&strtotime((string)$r['trialEndsAt'])<time();$r['trialExpiringSoon']=($r['subscriptionStatus']??'')==='trialing'&&!empty($r['trialEndsAt'])&&strtotime((string)$r['trialEndsAt'])>=time()&&strtotime((string)$r['trialEndsAt'])<=time()+604800;}unset($r);
    json_response(['coaches'=>$rows]);
}

if($method==='GET'&&$route==='/admin/master/billing'){
    pf_require_admin_level('super_admin','finance','read_only');
    $events=$pdo->query('SELECT be.id,be.coach_id AS coachId,u.name AS coachName,be.gateway,be.external_id AS externalId,be.status,be.amount_cents AS amountCents,be.created_at AS createdAt FROM billing_events be JOIN users u ON u.id=be.coach_id ORDER BY be.id DESC LIMIT 300')->fetchAll();
    $summary=[
      'eventsTotal'=>(int)$pdo->query('SELECT COUNT(*) FROM billing_events')->fetchColumn(),
      'created30d'=>(int)$pdo->query('SELECT COUNT(*) FROM billing_events WHERE created_at>=datetime("now","-30 days")')->fetchColumn(),
      'activeSubscriptions'=>(int)$pdo->query('SELECT COUNT(*) FROM coach_profiles WHERE subscription_status="active" AND plan_code!="trial"')->fetchColumn(),
      'pastDue'=>(int)$pdo->query('SELECT COUNT(*) FROM coach_profiles WHERE subscription_status="past_due"')->fetchColumn(),
      'cancelled'=>(int)$pdo->query('SELECT COUNT(*) FROM coach_profiles WHERE subscription_status="cancelled"')->fetchColumn(),
      'trials'=>(int)$pdo->query('SELECT COUNT(*) FROM coach_profiles WHERE subscription_status="trialing"')->fetchColumn()
    ];
    json_response(['summary'=>$summary,'events'=>$events]);
}

if($method==='GET'&&$route==='/admin/master/overview'){
    pf_require_admin_level('super_admin','support','finance','read_only');
    $settingsRows=$pdo->query('SELECT setting_key AS key,setting_value AS value FROM app_settings ORDER BY setting_key')->fetchAll();$settings=[];foreach($settingsRows as $r)$settings[$r['key']]=$r['value'];
    $readiness=['https'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','mercadoPago'=>trim((string)getenv('MERCADOPAGO_ACCESS_TOKEN'))!=='','stripe'=>trim((string)getenv('STRIPE_SECRET_KEY'))!=='','stripeWebhook'=>trim((string)getenv('STRIPE_WEBHOOK_SECRET'))!=='','mercadoPagoWebhook'=>trim((string)getenv('MERCADOPAGO_WEBHOOK_SECRET'))!=='','gemini'=>trim((string)getenv('GEMINI_API_KEY'))!=='','vapid'=>trim((string)getenv('VAPID_PUBLIC_KEY'))!==''&&trim((string)getenv('VAPID_PRIVATE_KEY'))!==''];
    json_response(['settings'=>$settings,'readiness'=>$readiness,'risks'=>pf_prod_risks($pdo),'backup'=>pf_prod_latest_backup()]);
}
