<?php
declare(strict_types=1);

const PF_ROOT = __DIR__ . '/..';
const PF_STORAGE = PF_ROOT . '/storage';
const PF_DB = PF_STORAGE . '/pulsefit.sqlite';
const PF_SEED_DB = PF_ROOT . '/database/seed.sqlite';

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('pulsefit_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}

function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    if (!is_file(PF_DB) && is_file(PF_SEED_DB)) {
        if (!is_dir(PF_STORAGE)) mkdir(PF_STORAGE, 0750, true);
        if (!copy(PF_SEED_DB, PF_DB)) json_response(['error'=>'Não foi possível inicializar o banco de dados.'],503);
    }
    if (!is_file(PF_DB)) json_response(['error'=>'Sistema ainda não instalado.','setup'=>true],503);
    $pdo = new PDO('sqlite:' . PF_DB, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    migrate_database($pdo);
    return $pdo;
}

function migrate_database(PDO $pdo): void {
    $columns=$pdo->query('PRAGMA table_info(users)')->fetchAll();$names=array_column($columns,'name');
    if(!in_array('must_change_password',$names,true))$pdo->exec('ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('CREATE TABLE IF NOT EXISTS password_reset_tokens(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,token_hash TEXT NOT NULL UNIQUE,expires_at TEXT NOT NULL,used_at TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_password_reset_hash ON password_reset_tokens(token_hash)');
    $tables=[
      'audit_logs'=>'CREATE TABLE audit_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INTEGER,action TEXT NOT NULL,entity_type TEXT NOT NULL,entity_id INTEGER,details TEXT,ip_hash TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
      'appointments'=>'CREATE TABLE appointments(id INTEGER PRIMARY KEY AUTOINCREMENT,coach_id INTEGER NOT NULL,student_id INTEGER,title TEXT NOT NULL,starts_at TEXT NOT NULL,ends_at TEXT,status TEXT NOT NULL DEFAULT "scheduled",notes TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE)',
      'messages'=>'CREATE TABLE messages(id INTEGER PRIMARY KEY AUTOINCREMENT,coach_id INTEGER NOT NULL,student_id INTEGER NOT NULL,sender_user_id INTEGER NOT NULL,body TEXT NOT NULL,read_at TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,FOREIGN KEY(sender_user_id) REFERENCES users(id) ON DELETE CASCADE)',
      'payments'=>'CREATE TABLE payments(id INTEGER PRIMARY KEY AUTOINCREMENT,coach_id INTEGER NOT NULL,student_id INTEGER NOT NULL,amount_cents INTEGER NOT NULL,due_date TEXT NOT NULL,paid_at TEXT,status TEXT NOT NULL DEFAULT "pending",description TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE)',
      'notifications'=>'CREATE TABLE notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,title TEXT NOT NULL,body TEXT NOT NULL,read_at TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)',
      'progress_photos'=>'CREATE TABLE progress_photos(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL,file_path TEXT NOT NULL,caption TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE)',
      'consents'=>'CREATE TABLE consents(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,document_type TEXT NOT NULL,document_version TEXT NOT NULL,accepted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,ip_hash TEXT,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)',
      'app_settings'=>'CREATE TABLE app_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
      'login_attempts'=>'CREATE TABLE login_attempts(attempt_key TEXT PRIMARY KEY,attempts INTEGER NOT NULL DEFAULT 0,first_attempt_at TEXT NOT NULL,last_attempt_at TEXT NOT NULL,blocked_until TEXT)'];
    foreach($tables as $sql)$pdo->exec(str_replace('CREATE TABLE ','CREATE TABLE IF NOT EXISTS ',$sql));
    $workoutColumns=array_column($pdo->query('PRAGMA table_info(workouts)')->fetchAll(),'name');
    if(!in_array('archived_at',$workoutColumns,true))$pdo->exec('ALTER TABLE workouts ADD COLUMN archived_at TEXT');
    if(!in_array('updated_at',$workoutColumns,true))$pdo->exec('ALTER TABLE workouts ADD COLUMN updated_at TEXT');
    $studentColumns=array_column($pdo->query('PRAGMA table_info(students)')->fetchAll(),'name');
    if(!in_array('notes',$studentColumns,true))$pdo->exec('ALTER TABLE students ADD COLUMN notes TEXT');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_thread ON messages(coach_id,student_id,created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appointments_coach ON appointments(coach_id,starts_at)');
    $featureTables=[
      'exercise_library'=>'CREATE TABLE exercise_library(id INTEGER PRIMARY KEY AUTOINCREMENT,coach_id INTEGER,name TEXT NOT NULL,category TEXT NOT NULL DEFAULT "GERAL",exercise_type TEXT NOT NULL DEFAULT "ISOLADO",equipment TEXT NOT NULL DEFAULT "LIVRE",media_url TEXT,media_type TEXT NOT NULL DEFAULT "image",instructions TEXT,default_sets INTEGER NOT NULL DEFAULT 3,default_reps TEXT NOT NULL DEFAULT "10-12",default_rest INTEGER NOT NULL DEFAULT 60,default_rpe INTEGER NOT NULL DEFAULT 8,default_tempo TEXT NOT NULL DEFAULT "2-0-2-0",is_system INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(coach_id) REFERENCES users(id) ON DELETE CASCADE)',
      'anamneses'=>'CREATE TABLE anamneses(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER NOT NULL UNIQUE,objective TEXT,conditions_json TEXT,injuries TEXT,experience TEXT,availability TEXT,sleep_hours REAL,stress_level INTEGER,notes TEXT,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE)',
      'workout_sessions'=>'CREATE TABLE workout_sessions(id INTEGER PRIMARY KEY AUTOINCREMENT,workout_id INTEGER NOT NULL,student_id INTEGER NOT NULL,started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,completed_at TEXT,total_volume REAL NOT NULL DEFAULT 0,duration_seconds INTEGER NOT NULL DEFAULT 0,FOREIGN KEY(workout_id) REFERENCES workouts(id) ON DELETE CASCADE,FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE)',
      'workout_set_logs'=>'CREATE TABLE workout_set_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,session_id INTEGER NOT NULL,exercise_id INTEGER,exercise_name TEXT NOT NULL,set_number INTEGER NOT NULL,reps INTEGER NOT NULL,load REAL NOT NULL DEFAULT 0,rpe REAL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(session_id) REFERENCES workout_sessions(id) ON DELETE CASCADE,FOREIGN KEY(exercise_id) REFERENCES exercises(id) ON DELETE SET NULL)'];
    foreach($featureTables as $sql)$pdo->exec(str_replace('CREATE TABLE ','CREATE TABLE IF NOT EXISTS ',$sql));
    $exerciseColumns=array_column($pdo->query('PRAGMA table_info(exercises)')->fetchAll(),'name');
    foreach(['library_exercise_id'=>'INTEGER','thumbnail'=>'TEXT','category'=>'TEXT','exercise_type'=>'TEXT','equipment'=>'TEXT','rpe'=>'INTEGER','tempo'=>'TEXT','instructions'=>'TEXT'] as $col=>$type)if(!in_array($col,$exerciseColumns,true))$pdo->exec("ALTER TABLE exercises ADD COLUMN {$col} {$type}");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_library_coach ON exercise_library(coach_id,name)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_student ON workout_sessions(student_id,started_at)');
    seed_exercise_library($pdo);maybe_backup_database($pdo);
}

function seed_exercise_library(PDO $pdo): void {
    $count=(int)$pdo->query('SELECT COUNT(*) FROM exercise_library WHERE is_system=1')->fetchColumn();if($count>0)return;
    $items=[
      ['Agachamento Livre','QUADRÍCEPS','COMPOSTO','BARRA','', 'Mantenha o tronco firme, joelhos acompanhando a linha dos pés e amplitude segura.',4,'8-10',120,8,'3-0-1-0'],
      ['Levantamento Terra Romeno','POSTERIOR','COMPOSTO','BARRA','', 'Quadril para trás, coluna neutra e tensão contínua nos posteriores.',3,'10-12',90,8,'3-1-1-0'],
      ['Supino Reto','PEITO','COMPOSTO','BARRA','', 'Escápulas retraídas, pés firmes e controle na descida.',4,'8-12',90,8,'3-1-1-0'],
      ['Remada Curvada','COSTAS','COMPOSTO','BARRA','', 'Tronco estável e puxada em direção ao abdômen.',4,'8-12',90,8,'2-1-2-0'],
      ['Desenvolvimento Militar','OMBROS','COMPOSTO','HALTERES','', 'Abdômen firme e trajetória vertical controlada.',3,'8-12',90,8,'2-0-2-0'],
      ['Rosca Direta','BÍCEPS','ISOLADO','BARRA','', 'Cotovelos próximos ao tronco e sem balanço.',3,'10-15',60,8,'2-0-2-0'],
      ['Tríceps Corda','TRÍCEPS','ISOLADO','POLIA','', 'Estenda completamente e abra a corda no final.',3,'12-15',60,8,'2-0-1-1'],
      ['Leg Press 45°','QUADRÍCEPS','COMPOSTO','MÁQUINA','', 'Controle a amplitude sem retirar a lombar do encosto.',4,'10-15',90,8,'2-1-1-0']];
    $q=$pdo->prepare('INSERT INTO exercise_library(name,category,exercise_type,equipment,media_url,instructions,default_sets,default_reps,default_rest,default_rpe,default_tempo,is_system) VALUES(:name,:category,:type,:equipment,:media,:instructions,:sets,:reps,:rest,:rpe,:tempo,1)');
    foreach($items as $x)$q->execute(['name'=>$x[0],'category'=>$x[1],'type'=>$x[2],'equipment'=>$x[3],'media'=>$x[4],'instructions'=>$x[5],'sets'=>$x[6],'reps'=>$x[7],'rest'=>$x[8],'rpe'=>$x[9],'tempo'=>$x[10]]);
}
function client_ip_hash(): string{return hash('sha256',($_SERVER['REMOTE_ADDR']??'unknown').'|pulsefit');}
function audit(PDO $pdo,?int $actorId,string $action,string $type,?int $entityId=null,array $details=[]):void{$stmt=$pdo->prepare('INSERT INTO audit_logs(actor_id,action,entity_type,entity_id,details,ip_hash) VALUES(:actor,:action,:type,:entity,:details,:ip)');$stmt->execute(['actor'=>$actorId,'action'=>$action,'type'=>$type,'entity'=>$entityId,'details'=>json_encode($details,JSON_UNESCAPED_UNICODE),'ip'=>client_ip_hash()]);}
function maybe_backup_database(PDO $pdo):void{static $done=false;if($done)return;$done=true;$dir=PF_STORAGE.'/backups';if(!is_dir($dir))@mkdir($dir,0750,true);$file=$dir.'/pulsefit-'.gmdate('Y-m-d').'.sqlite';if(!is_file($file)){try{$quoted=str_replace("'","''",$file);$pdo->exec("VACUUM INTO '{$quoted}'");}catch(Throwable $e){}}$files=glob($dir.'/pulsefit-*.sqlite')?:[];sort($files);while(count($files)>14){$old=array_shift($files);if($old)@unlink($old);}}
function setting(PDO $pdo,string $key,?string $default=null):?string{$stmt=$pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:key');$stmt->execute(['key'=>$key]);$v=$stmt->fetchColumn();return $v===false?$default:(string)$v;}
function deliver_email(string $to,string $subject,string $message):bool{$host=preg_replace('/[^a-z0-9.-]/i','',$_SERVER['SERVER_NAME']??'localhost');if(!$host||$host==='localhost')return false;$headers="From: PulseFit Pro <no-reply@{$host}>\r\nReply-To: no-reply@{$host}\r\nContent-Type: text/plain; charset=UTF-8\r\n";return @mail($to,$subject,$message,$headers);}
function temporary_password():string{return strtoupper(substr(bin2hex(random_bytes(3)),0,3)).'-'.substr(bin2hex(random_bytes(5)),0,8);}
function send_access_email(string $email,string $name,string $password,string $role):bool{$label=$role==='coach'?'treinador':'aluno';return deliver_email($email,'Seu acesso ao PulseFit Pro',"Olá, {$name}!\n\nSua conta de {$label} no PulseFit Pro foi liberada.\n\nE-mail: {$email}\nSenha temporária: {$password}\n\nEntre no sistema e altere a senha no primeiro acesso.\n");}
function send_reset_email(string $email,string $name,string $token):bool{$host=preg_replace('/[^a-z0-9.-]/i','',$_SERVER['SERVER_NAME']??'localhost');$scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';$link=$scheme.'://'.$host.'/?reset='.rawurlencode($token);return deliver_email($email,'Redefina sua senha do PulseFit Pro',"Olá, {$name}!\n\nAbra o link abaixo em até 60 minutos:\n{$link}\n");}
function body():array{$raw=file_get_contents('php://input')?:'';$data=json_decode($raw,true);return is_array($data)?$data:$_POST;}
function current_user():array{start_secure_session();if(empty($_SESSION['user']))json_response(['error'=>'Não autenticado.'],401);return $_SESSION['user'];}
function require_role(string ...$roles):array{$user=current_user();if(!in_array($user['role'],$roles,true))json_response(['error'=>'Acesso negado.'],403);return $user;}
function csrf_token():string{start_secure_session();if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function verify_csrf():void{start_secure_session();$sent=$_SERVER['HTTP_X_CSRF_TOKEN']??'';if(!$sent||empty($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$sent))json_response(['error'=>'Sessão inválida. Atualize a página e tente novamente.'],419);}
function clean_email(mixed $value):string{$email=strtolower(trim((string)$value));if(!filter_var($email,FILTER_VALIDATE_EMAIL))json_response(['error'=>'E-mail inválido.'],422);return $email;}
function route_path():string{$uri=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';$marker='/api/';$pos=strpos($uri,$marker);return $pos===false?'/':'/'.trim(substr($uri,$pos+strlen($marker)),'/');}
