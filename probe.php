<?php
header('Content-Type: text/plain; charset=utf-8');

echo "SCRIPT_FILENAME=" . ($_SERVER['SCRIPT_FILENAME'] ?? '') . "\n";
echo "__DIR__=" . __DIR__ . "\n";
echo "DOCUMENT_ROOT=" . ($_SERVER['DOCUMENT_ROOT'] ?? '') . "\n";
echo "REQUEST_URI=" . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
echo "METHOD=" . ($_SERVER['REQUEST_METHOD'] ?? '') . "\n\n";

echo "FILES IN __DIR__:\n";
foreach (scandir(__DIR__) as $f) {
  if ($f === '.' || $f === '..') continue;
  echo " - {$f}\n";
}
echo "\n";
echo "upload.php exists? " . (file_exists(__DIR__ . '/upload.php') ? "YES" : "NO") . "\n";
