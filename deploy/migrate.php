<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){fwrite(STDERR,"Somente CLI.\n");exit(1);}
require dirname(__DIR__).'/api/bootstrap.php';
$pdo=db();
require dirname(__DIR__).'/api/migrations.php';
require dirname(__DIR__).'/api/migrations_performance.php';
require dirname(__DIR__).'/api/exercise_seed.php';
pf_seed_exercise_library($pdo);
echo "Migrações PulseFit aplicadas com sucesso.\n";
echo "Biblioteca de exercícios de sistema: ".(int)$pdo->query('SELECT COUNT(*) FROM exercise_library WHERE is_system=1')->fetchColumn()." exercícios.\n";

$rows=$pdo->query('SELECT u.id,u.name,cp.plan_code,cp.subscription_status,cp.trial_ends_at,(SELECT COUNT(*) FROM students s WHERE s.coach_id=u.id AND s.archived_at IS NULL) AS students_count FROM users u LEFT JOIN coach_profiles cp ON cp.user_id=u.id WHERE u.role="coach" ORDER BY u.id')->fetchAll();
foreach($rows as $r){
  echo sprintf("Treinador #%d %s | plano=%s | status=%s | trial=%s | alunos=%d\n",(int)$r['id'],$r['name']??'', $r['plan_code']??'sem-plano',$r['subscription_status']??'sem-status',$r['trial_ends_at']??'sem-data',(int)($r['students_count']??0));
}
