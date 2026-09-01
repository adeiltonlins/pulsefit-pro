<?php
// Migrações idempotentes carregadas ANTES das rotas principais.
// Mantém bancos antigos compatíveis com V8/V9 sem apagar dados.

function pf_schema_has_column(PDO $pdo,string $table,string $column):bool{
    $rows=$pdo->query('PRAGMA table_info('.$table.')')->fetchAll();
    foreach($rows as $r)if(($r['name']??null)===$column)return true;
    return false;
}
function pf_schema_add_column(PDO $pdo,string $table,string $column,string $definition):void{
    if(!pf_schema_has_column($pdo,$table,$column))$pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);
}
function pf_run_migrations(PDO $pdo):void{
    $pdo->exec('PRAGMA foreign_keys=ON');

    // V8
    pf_schema_add_column($pdo,'users','archived_at','TEXT');
    pf_schema_add_column($pdo,'students','archived_at','TEXT');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_profiles(user_id INTEGER PRIMARY KEY,admin_level TEXT NOT NULL DEFAULT "read_only",created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS checkins(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL,weight REAL,sleep_hours REAL,pain_level INTEGER NOT NULL DEFAULT 0,fatigue_level INTEGER NOT NULL DEFAULT 0,energy_level INTEGER NOT NULL DEFAULT 0,notes TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_checkins_student ON checkins(student_id,created_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS workout_templates(id INTEGER PRIMARY KEY AUTOINCREMENT,coach_id INTEGER NOT NULL,name TEXT NOT NULL,description TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS workout_template_exercises(id INTEGER PRIMARY KEY AUTOINCREMENT,template_id INTEGER NOT NULL,position INTEGER NOT NULL DEFAULT 0,library_exercise_id INTEGER,name TEXT NOT NULL,sets INTEGER NOT NULL DEFAULT 3,reps TEXT NOT NULL DEFAULT "10-12",load TEXT,rest_seconds INTEGER NOT NULL DEFAULT 60,notes TEXT,thumbnail TEXT,category TEXT,exercise_type TEXT,equipment TEXT,rpe INTEGER,tempo TEXT,instructions TEXT,FOREIGN KEY(template_id) REFERENCES workout_templates(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_templates_coach ON workout_templates(coach_id,name)');

    // Garante perfil administrativo para admins antigos.
    $admins=$pdo->query('SELECT id FROM users WHERE role="admin" ORDER BY id')->fetchAll();
    $profiles=(int)$pdo->query('SELECT COUNT(*) FROM admin_profiles')->fetchColumn();
    foreach($admins as $i=>$a){
        $level=($profiles===0&&$i===0)?'super_admin':'read_only';
        $q=$pdo->prepare('INSERT OR IGNORE INTO admin_profiles(user_id,admin_level) VALUES(:id,:level)');
        $q->execute(['id'=>$a['id'],'level'=>$level]);
    }

    // V9 comercial
    pf_schema_add_column($pdo,'coach_profiles','plan_code','TEXT NOT NULL DEFAULT "trial"');
    pf_schema_add_column($pdo,'coach_profiles','subscription_status','TEXT NOT NULL DEFAULT "trialing"');
    pf_schema_add_column($pdo,'coach_profiles','trial_ends_at','TEXT');
    pf_schema_add_column($pdo,'coach_profiles','onboarding_step','INTEGER NOT NULL DEFAULT 0');
    pf_schema_add_column($pdo,'coach_profiles','onboarding_completed_at','TEXT');
    pf_schema_add_column($pdo,'coach_profiles','billing_gateway','TEXT');
    pf_schema_add_column($pdo,'coach_profiles','billing_customer_id','TEXT');

    // Treinadores antigos podem não ter coach_profiles em versões anteriores.
    $pdo->exec('INSERT OR IGNORE INTO coach_profiles(user_id,specialty,unit) SELECT id,"","" FROM users WHERE role="coach"');

    $pdo->exec('CREATE TABLE IF NOT EXISTS push_subscriptions(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,endpoint TEXT NOT NULL UNIQUE,p256dh TEXT,auth TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS billing_events(id INTEGER PRIMARY KEY AUTOINCREMENT,coach_id INTEGER NOT NULL,gateway TEXT NOT NULL,external_id TEXT,status TEXT,amount_cents INTEGER,raw_json TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_billing_events_coach ON billing_events(coach_id,created_at)');

    $defaults=[
      'trial.days'=>'7','plan.trial.limit'=>'5','plan.basic.limit'=>'20','plan.pro.limit'=>'100','plan.business.limit'=>'500',
      'plan.basic.price_cents'=>'4900','plan.pro.price_cents'=>'9900','plan.business.price_cents'=>'19900'
    ];
    foreach($defaults as $k=>$v){
        $q=$pdo->prepare('INSERT OR IGNORE INTO app_settings(setting_key,setting_value) VALUES(:k,:v)');$q->execute(['k'=>$k,'v'=>$v]);
    }
    $trial=max(1,(int)($pdo->query('SELECT setting_value FROM app_settings WHERE setting_key="trial.days"')->fetchColumn()?:7));

    // Normaliza contas antigas: sem plano/status/data não pode bloquear cadastro por migração incompleta.
    $pdo->exec('UPDATE coach_profiles SET plan_code="trial" WHERE plan_code IS NULL OR trim(plan_code)=""');
    $pdo->exec('UPDATE coach_profiles SET subscription_status="trialing" WHERE subscription_status IS NULL OR trim(subscription_status)=""');
    $q=$pdo->prepare('UPDATE coach_profiles SET trial_ends_at=datetime("now","+"||:days||" days") WHERE trial_ends_at IS NULL OR trim(trial_ends_at)=""');
    $q->execute(['days'=>$trial]);

    // Recria o trigger só depois de toda a estrutura estar pronta.
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

pf_run_migrations($pdo);
