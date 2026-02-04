<?php
require_once __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');

try {
  $pdo = db();
  $stmt = $pdo->query("SELECT NOW() AS now_time");
  $row = $stmt->fetch();
  echo "DB CONNECT OK\n";
  echo $row['now_time'] . "\n";
} catch (Throwable $e) {
  echo "DB CONNECT NG\n";
  echo $e->getMessage();
}
