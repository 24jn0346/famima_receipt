<?php
function envv(string $k): string {
  $v = getenv($k);
  return ($v === false) ? '' : trim($v);
}

function db(): PDO {
  $host = envv('DB_HOST');
  $name = envv('DB_NAME');
  $user = envv('DB_USER');
  $pass = envv('DB_PASS');
  $port = envv('DB_PORT') ?: '3306';

  if ($host==='' || $name==='' || $user==='' || $pass==='') {
    throw new RuntimeException("ENV missing (DB_HOST/DB_NAME/DB_USER/DB_PASS)");
  }

  $ca = __DIR__ . '/certs/azure-mysql-ca-bundle.pem';
  if (!file_exists($ca)) {
    throw new RuntimeException("CA bundle not found: {$ca}");
  }

  $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
  return new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_SSL_CA => $ca,
    // まずは接続を通す目的で false（厳密検証は後でOK）
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
  ]);
}
