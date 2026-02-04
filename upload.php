<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vision_ocr.php';
require_once __DIR__ . '/famima_parser.php';

date_default_timezone_set('Asia/Tokyo');

function append_ocr_log(string $title, array $lines): void
{
  $path = __DIR__ . '/ocr.log';
  $ts = date('Y-m-d H:i:s');
  $body = "==== {$ts} {$title} ====\n" . implode("\n", $lines) . "\n\n";
  file_put_contents($path, $body, FILE_APPEND | LOCK_EX);
}

if (empty($_FILES['receipts'])) {
  http_response_code(400);
  echo "ファイルがありません";
  exit;
}

$files = $_FILES['receipts'];
$base = getenv('HOME') . '/site/wwwroot';
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

$pdo = db();

$results = [];

for ($i = 0; $i < count($files['name']); $i++) {
  if ($files['error'][$i] !== UPLOAD_ERR_OK) {
    echo "UPLOAD ERROR: " . $files['error'][$i] . " (file=" . $files['name'][$i] . ")\n";
    exit;
  }


  $origName = $files['name'][$i];
  $tmpPath  = $files['tmp_name'][$i];

  $ext = pathinfo($origName, PATHINFO_EXTENSION) ?: 'jpg';
  $stored = 'uploads/' . uniqid('rcpt_', true) . '.' . $ext;
  $storedAbs = __DIR__ . '/' . $stored;

  // まず move_uploaded_file を試す
$ok = move_uploaded_file($tmpPath, $storedAbs);

if (!$ok) {
  // move_uploaded_file がダメな環境向けフォールバック
  // 1) 本当にアップロード由来か確認ログ
  $isUploaded = is_uploaded_file($tmpPath) ? "YES" : "NO";

  // 2) copyで保存を試す（読み取りはできているので通ることが多い）
  $ok = @copy($tmpPath, $storedAbs);

  if (!$ok) {
    echo "UPLOAD SAVE FAILED\n";
    echo "tmpPath={$tmpPath}\n";
    echo "storedAbs={$storedAbs}\n";
    echo "is_uploaded_file(tmpPath)={$isUploaded}\n";
    echo "uploadDir={$uploadDir} writable=" . (is_writable($uploadDir) ? "YES" : "NO") . "\n";
    echo "storedAbs parent writable=" . (is_writable(dirname($storedAbs)) ? "YES" : "NO") . "\n";
    echo "tmp readable=" . (is_readable($tmpPath) ? "YES" : "NO") . "\n";
    exit;
  }
}


  $bytes = file_get_contents($storedAbs);

  // OCR
  $json = vision_read_ocr_bytes($bytes);
  $ocrLines = ocr_lines_from_result($json);

  // OCR全文ログ（要件）
  append_ocr_log($origName, $ocrLines);

  // 抽出
  $parsed = parse_famima_receipt($ocrLines);

  // DB: receipts
  $stmt = $pdo->prepare("INSERT INTO receipts(uploaded_at, original_filename, stored_path, total_yen) VALUES (?,?,?,?)");
  $stmt->execute([date('Y-m-d H:i:s'), $origName, $stored, $parsed['total']]);
  $receiptId = (int)$pdo->lastInsertId();

  // DB: items
  $ins = $pdo->prepare("INSERT INTO receipt_items(receipt_id, item_name, price_yen) VALUES (?,?,?)");
  foreach ($parsed['items'] as $it) {
    if ($it['name'] === '' || $it['price'] <= 0) continue;
    $ins->execute([$receiptId, $it['name'], $it['price']]);
  }

  $results[] = [
    'receipt_id' => $receiptId,
    'original' => $origName,
    'items' => $parsed['items'],
    'total' => $parsed['total'],
  ];
}
?>
<!doctype html>
<html lang="ja">

<head>
  <meta charset="utf-8">
  <title>解析結果</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    table {
      border-collapse: collapse;
      width: 100%;
      max-width: 720px
    }

    th,
    td {
      border: 1px solid #ccc;
      padding: 6px
    }

    .box {
      margin: 16px 0;
      padding: 12px;
      border: 1px solid #ddd;
      border-radius: 8px;
      max-width: 720px
    }
  </style>
</head>

<body>
  <h1>解析結果</h1>

  <p>
    <a href="index.php">← 戻る</a>
  </p>

  <p>
    <a href="ocr.log" target="_blank">ocr.log を開く</a>
  </p>

  <?php foreach ($results as $r): ?>
    <div class="box">
      <h2><?= htmlspecialchars($r['original']) ?></h2>

      <table>
        <thead>
          <tr>
            <th>商品名</th>
            <th>値段</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($r['items'] as $it): ?>
            <tr>
              <td><?= htmlspecialchars($it['name']) ?></td>
              <td>¥<?= (int)$it['price'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th>合計</th>
            <th><?= $r['total'] !== null ? '¥' . (int)$r['total'] : '（未検出）' ?></th>
          </tr>
        </tfoot>
      </table>

      <p>
        <a href="download_csv.php?receipt_id=<?= (int)$r['receipt_id'] ?>">CSVダウンロード</a>
      </p>
    </div>
  <?php endforeach; ?>
</body>

</html>