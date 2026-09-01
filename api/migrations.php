<?php
// Migrações idempotentes executadas pelo deploy e seguras para bancos antigos.
function pf_schema_has_column(PDO $pdo,string $table,string $column):bool{
    $rows=$pdo->query('PRAGMA table_info('.$table.')')->fetchAll();foreach($rows as $r)if(($r['name']??null)===$column)return true;return false;
}
function pf_schema_add_column(PDO $pdo,string $table,string $column,string $definition):void{
    if(pf_schema_has_column($pdo,$table,$column))return;
    try{$pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);}catch(PDOException $e){if(!str_contains(strtolower($e->getMessage()),'duplicate column'))throw $e;}
}
function pf_run_migrations(PDO $pdo):void{
    $pdo->exec('PRAGMA foreign_keys=ON');

    // V8 — maturidade administrativa.
    pf_schema_add_column($pdo,'users','archived_at','TEXT');
    pf_schema_add_column($pdo,'students','archived_at','TEXT');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_profiles(user_id INTEGER PRIMARY KEY,admin_level TEXT NOT NULL DEFAULT "read_only",created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS checkins(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL,weight REAL,sleep_hours REAL,pain_level INTEGER NOT NULL DEFAULT 0,fatigue_level INTEGER NOT NULL DEFAULT 0,energy_level INTEGER NOT NULL DEFAULT 0,notes TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_checkins_student ON checkins(student_id,created_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS workout_templates(id INTEGER PRIMARY KEY AUTOINCREMENT,coach_id INTEGER NOT NULL,name TEXT NOT NULL,description TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS workout_template_exercises(id INTEGER PRIMARY KEY AUTOINCREMENT,template_id INTEGER NOT NULL,position INTEGER NOT NULL DEFAULT 0,library_exercise_id INTEGER,name TEXT NOT NULL,sets INTEGER NOT NULL DEFAULT 3,reps TEXT NOT NULL DEFAULT "10-12",load TEXT,rest_seconds INTEGER NOT NULL DEFAULT 60,notes TEXT,thumbnail TEXT,category TEXT,exercise_type TEXT,equipment TEXT,rpe INTEGER,tempo TEXT,instructions TEXT,FOREIGN KEY(template_id) REFERENCES workout_templates(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_templates_coach ON workout_templates(coach_id,name)');
    $admins=$pdo->query('SELECT id FROM users WHERE role="admin" ORDER BY id')->fetchAll();$profiles=(int)$pdo->query('SELECT COUNT(*) FROM admin_profiles')->fetchColumn();
    foreach($admins as $i=>$a){$level=($profiles===0&&$i===0)?'super_admin':'read_only';$q=$pdo->prepare('INSERT OR IGNORE INTO admin_profiles(user_id,admin_level) VALUES(:id,:level)');$q->execute(['id'=>$a['id'],'level'=>$level]);}

    // V9 — comercial, trial, cobrança e push subscriptions.
    foreach(['plan_code'=>'TEXT NOT NULL DEFAULT "trial"','subscription_status'=>'TEXT NOT NULL DEFAULT "trialing"','trial_ends_at'=>'TEXT','onboarding_step'=>'INTEGER NOT NULL DEFAULT 0','onboarding_completed_at'=>'TEXT','billing_gateway'=>'TEXT','billing_customer_id'=>'TEXT'] as $c=>$d)pf_schema_add_column($pdo,'coach_profiles',$c,$d);
    $pdo->exec('INSERT OR IGNORE INTO coach_profiles(user_id,specialty,unit) SELECT id,"","" FROM users WHERE role="coach"');
    $pdo->exec('CREATE TABLE IF NOT EXISTS push_subscriptions(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,endpoint TEXT NOT NULL UNIQUE,p256dh TEXT,auth TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS billing_events(id INTEGER PRIMARY KEY AUTOINCREMENT,coach_id INTEGER NOT NULL,gateway TEXT NOT NULL,external_id TEXT,status TEXT,amount_cents INTEGER,raw_json TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_billing_events_coach ON billing_events(coach_id,created_at)');
    $defaults=['trial.days'=>'7','plan.trial.limit'=>'5','plan.basic.limit'=>'20','plan.pro.limit'=>'100','plan.business.limit'=>'500','plan.basic.price_cents'=>'4900','plan.pro.price_cents'=>'9900','plan.business.price_cents'=>'19900'];
    foreach($defaults as $k=>$v){$q=$pdo->prepare('INSERT OR IGNORE INTO app_settings(setting_key,setting_value) VALUES(:k,:v)');$q->execute(['k'=>$k,'v'=>$v]);}
    $trial=max(1,(int)($pdo->query('SELECT setting_value FROM app_settings WHERE setting_key="trial.days"')->fetchColumn()?:7));
    $pdo->exec('UPDATE coach_profiles SET plan_code="trial" WHERE plan_code IS NULL OR trim(plan_code)=""');$pdo->exec('UPDATE coach_profiles SET subscription_status="trialing" WHERE subscription_status IS NULL OR trim(subscription_status)=""');
    $q=$pdo->prepare('UPDATE coach_profiles SET trial_ends_at=datetime("now","+"||:days||" days") WHERE trial_ends_at IS NULL OR trim(trial_ends_at)=""');$q->execute(['days'=>$trial]);
    $pdo->exec('DROP TRIGGER IF EXISTS trg_coach_profile_trial');
    $pdo->exec('CREATE TRIGGER trg_coach_profile_trial AFTER INSERT ON coach_profiles WHEN NEW.trial_ends_at IS NULL BEGIN UPDATE coach_profiles SET trial_ends_at=datetime("now","+"||COALESCE((SELECT setting_value FROM app_settings WHERE setting_key="trial.days"),"7")||" days") WHERE user_id=NEW.user_id; END');
    $pdo->exec('DROP TRIGGER IF EXISTS trg_students_plan_limit');
    $pdo->exec('CREATE TRIGGER trg_students_plan_limit BEFORE INSERT ON students BEGIN
      SELECT CASE WHEN (SELECT subscription_status FROM coach_profiles WHERE user_id=NEW.coach_id) IN ("expired","cancelled","past_due") OR ((SELECT subscription_status FROM coach_profiles WHERE user_id=NEW.coach_id)="trialing" AND datetime((SELECT trial_ends_at FROM coach_profiles WHERE user_id=NEW.coach_id))<datetime("now")) THEN RAISE(ABORT,"Plano ou trial indisponível") END;
      SELECT CASE WHEN (SELECT COUNT(*) FROM students WHERE coach_id=NEW.coach_id AND archived_at IS NULL) >= CAST(COALESCE((SELECT setting_value FROM app_settings WHERE setting_key="plan."||COALESCE((SELECT plan_code FROM coach_profiles WHERE user_id=NEW.coach_id),"trial")||".limit"),"5") AS INTEGER) THEN RAISE(ABORT,"Limite de alunos do plano atingido") END;
    END');

    // V11 — Professor Pro.
    $pdo->exec('CREATE TABLE IF NOT EXISTS student_goals(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL,goal_type TEXT NOT NULL,target_value REAL,target_text TEXT,unit TEXT,status TEXT NOT NULL DEFAULT "active",starts_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,due_at TEXT,completed_at TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_student_goals_student ON student_goals(student_id,status)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS physical_assessments(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL,coach_id INTEGER NOT NULL,weight REAL,body_fat REAL,chest REAL,waist REAL,abdomen REAL,hip REAL,biceps_left REAL,biceps_right REAL,thigh_left REAL,thigh_right REAL,calf_left REAL,calf_right REAL,notes TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_assessments_student ON physical_assessments(student_id,created_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS student_documents(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL,coach_id INTEGER NOT NULL,title TEXT NOT NULL,file_url TEXT,document_type TEXT NOT NULL DEFAULT "other",notes TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_documents_student ON student_documents(student_id,created_at)');

    // V12 — Aluno Pro / gamificação.
    $pdo->exec('CREATE TABLE IF NOT EXISTS student_achievements(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL,achievement_key TEXT NOT NULL,title TEXT NOT NULL,description TEXT,earned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(student_id,achievement_key),FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievements_student ON student_achievements(student_id,earned_at)');

    // V13 — relatórios.
    $pdo->exec('CREATE TABLE IF NOT EXISTS report_snapshots(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL,coach_id INTEGER NOT NULL,period_days INTEGER NOT NULL DEFAULT 30,payload_json TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_student ON report_snapshots(student_id,created_at)');

    // V14 — perfil público e CRM.
    $pdo->exec('CREATE TABLE IF NOT EXISTS coach_public_profiles(coach_id INTEGER PRIMARY KEY,slug TEXT UNIQUE,bio TEXT,headline TEXT,photo_url TEXT,whatsapp TEXT,city TEXT,state TEXT,services_json TEXT,public_enabled INTEGER NOT NULL DEFAULT 0,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS coach_leads(id INTEGER PRIMARY KEY AUTOINCREMENT,coach_id INTEGER NOT NULL,name TEXT NOT NULL,email TEXT,phone TEXT,goal TEXT,source TEXT NOT NULL DEFAULT "public_profile",status TEXT NOT NULL DEFAULT "new",notes TEXT,converted_student_id INTEGER,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(converted_student_id) REFERENCES students(id) ON DELETE SET NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_coach_leads ON coach_leads(coach_id,status,created_at)');
}
pf_run_migrations($pdo);
