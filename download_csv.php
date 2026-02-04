<?php
require_once __DIR__ . '/db.php';

$receiptId = isset($_GET['receipt_id']) ? (int)$_GET['receipt_id'] : 0;
if ($receiptId <= 0) { http_response_code(400); echo "bad receipt_id"; exit; }

$pdo = db();

$r = $pdo->prepare("SELECT * FROM receipts WHERE id=?");
$r->execute([$receiptId]);
$receipt = $r->fetch();
if (!$receipt) { http_response_code(404); echo "not found"; exit; }

$i = $pdo->prepare("SELECT item_name, price_yen FROM receipt_items WHERE receipt_id=? ORDER BY id ASC");
$i->execute([$receiptId]);
$items = $i->fetchAll();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="receipt_'.$receiptId.'.csv"');

$out = fopen('php://output', 'w');
// Excel対策（UTF-8 BOM）
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['商品名', '値段']);
foreach ($items as $it) {
  // 要件：余計な文字を入れない（priceは数値で保持、表示は¥付きでOKならここで付与）
  fputcsv($out, [$it['item_name'], '¥'.$it['price_yen']]);
}
fputcsv($out, ['合計', $receipt['total_yen'] !== null ? '¥'.$receipt['total_yen'] : '']);
fclose($out);
