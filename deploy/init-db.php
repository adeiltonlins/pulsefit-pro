<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Somente CLI.\n"); exit(1); }
$root = dirname(__DIR__);
$storage = $root . '/storage';
$dbFile = $storage . '/pulsefit.sqlite';
$schema = $root . '/database/schema.sql';
$name = trim($argv[1] ?? 'Administrador PulseFit');
$email = strtolower(trim($argv[2] ?? ''));
$password = (string)($argv[3] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR, "E-mail inválido.\n"); exit(2); }
if (strlen($password) < 10) { fwrite(STDERR, "A senha precisa ter pelo menos 10 caracteres.\n"); exit(3); }
if (!is_dir($storage) && !mkdir($storage, 0770, true)) { fwrite(STDERR, "Falha ao criar storage.\n"); exit(4); }
if (!is_file($schema)) { fwrite(STDERR, "schema.sql não encontrado.\n"); exit(5); }
$pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON;');
$pdo->exec(file_get_contents($schema));
$count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($count === 0) {
    $stmt = $pdo->prepare('INSERT INTO users(name,email,password_hash,role,status,must_change_password) VALUES(:name,:email,:hash,"admin","active",0)');
    $stmt->execute(['name'=>$name,'email'=>$email,'hash'=>password_hash($password, PASSWORD_DEFAULT)]);
    echo "Administrador criado com sucesso.\n";
} else {
    echo "Banco já possui usuários; nenhum administrador adicional foi criado.\n";
}
