<?php
declare(strict_types=1);
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec(file_get_contents(dirname(__DIR__).'/database/schema.sql'));
require dirname(__DIR__).'/api/migrations.php';
require dirname(__DIR__).'/api/migrations_branding.php';
require dirname(__DIR__).'/api/exercise_seed.php';
require dirname(__DIR__).'/api/exercise_ptbr.php';
pf_seed_exercise_library($pdo);pf_translate_exercise_library($pdo);
$count=(int)$pdo->query('SELECT COUNT(*) FROM exercise_library WHERE is_system=1')->fetchColumn();if($count<100)throw new RuntimeException('Biblioteca menor que 100 exercícios.');
$english=(int)$pdo->query("SELECT COUNT(*) FROM exercise_library WHERE is_system=1 AND name IN ('Ab wheel','Face pull','Hack squat','Nordic curl','Air bike','Mountain climber')")->fetchColumn();if($english!==0)throw new RuntimeException('Ainda existem nomes legados em inglês.');
$legacy=(int)$pdo->query("SELECT COUNT(*) FROM exercise_library WHERE is_system=1 AND media_url LIKE 'data:image/svg+xml%'")->fetchColumn();if($legacy!==0)throw new RuntimeException('Ainda existem placeholders SVG legados no banco.');
$pdo->prepare('INSERT INTO users(name,email,password_hash,role,status) VALUES("Coach Teste","coach@teste.local","x","coach","active")')->execute();$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO coach_profiles(user_id,brand_name,brand_primary) VALUES(:id,"MTFIT","#FF5500")')->execute(['id'=>$id]);$brand=$pdo->query('SELECT brand_name,brand_primary FROM coach_profiles WHERE user_id='.$id)->fetch();if(($brand['brand_name']??'')!=='MTFIT'||($brand['brand_primary']??'')!=='#FF5500')throw new RuntimeException('Brand Kit não persistiu.');
echo "Branding smoke OK: {$count} exercícios PT-BR, sem placeholders legados e Brand Kit persistente.\n";
