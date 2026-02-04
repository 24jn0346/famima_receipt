<?php
// famima_parser.php

/**
 * ファミマレシート（OCR行配列）から
 * - items: [{name, price}]
 * - total: int|null
 * を返す
 */
function parse_famima_receipt(array $ocrLines): array {
  // 1) 正規化（全角→半角っぽく、空白整理、余計な記号整理）
  $lines = [];
  foreach ($ocrLines as $raw) {
    $s = normalize_line($raw);
    if ($s === '') continue;
    $lines[] = $s;
  }

  // 2) 合計抽出（まずは強いパターンから）
  $total = find_total($lines);

  // 3) 明らかに不要な行を除外しつつ、商品抽出
  $items = [];
  $pendingName = null; // 「次行が価格」のときの一時保存

  for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];

    // ノイズ行はスキップ
    if (is_noise_line($line)) {
      $pendingName = null;
      continue;
    }

    // 合計行っぽいのは商品にしない
    if (is_totalish_line($line)) {
      $pendingName = null;
      continue;
    }

    // 3-A) 「この行に価格がある」パターン
    $price = extract_price_at_end($line);
    if ($price !== null) {
      $name = remove_price_part($line);
      $name = clean_item_name($name);

      // 価格だけの行だった場合、前の行を商品名として結合
      if ($name === '' && $pendingName !== null) {
        $name = clean_item_name($pendingName);
      }

      if ($name !== '' && $price > 0 && !looks_like_tax_or_payment($name)) {
        $items[] = ['name' => $name, 'price' => $price];
      }
      $pendingName = null;
      continue;
    }

    // 3-B) 「次行が価格」のために、商品名候補を貯める
    // ただし、商品名っぽくない（数字だけ等）は捨てる
    if (looks_like_item_name($line)) {
      $pendingName = $line;
    } else {
      $pendingName = null;
    }
  }

  // 4) 商品名の重複・ゴミを軽く除去（任意：必要なら強化）
  $items = post_filter_items($items);

  return ['items' => $items, 'total' => $total];
}

/* -------------------- helpers -------------------- */

function normalize_line(string $s): string {
  $s = trim($s);
  if ($s === '') return '';

  // 全角英数→半角（mbstringが有効なら効く）
  if (function_exists('mb_convert_kana')) {
    $s = mb_convert_kana($s, 'asKV', 'UTF-8'); // a:英数, s:空白, K:カナ, V:濁点
  }

  // よくある記号ゆれを統一
  $s = str_replace(['￥', '¥'], '¥', $s);
  $s = str_replace(["\t", "　"], ' ', $s);

  // 連続スペースを1つに
  $s = preg_replace('/\s+/', ' ', $s);

  // OCRが混ぜがちな不要な先頭記号は残しておく（後で商品名cleanで消す）
  return trim($s);
}

function is_noise_line(string $s): bool {
  // 店名・住所・日時・レジ・電話・領収/取引などのメタ情報を弾く
  $patterns = [
    '/ファミリーマート|Famima|FAMIMA/i',
    '/TEL|電話|東京都|区|市|町|丁目|番地/i',
    '/\d{4}\/\d{1,2}\/\d{1,2}|\d{4}-\d{1,2}-\d{1,2}/',
    '/\d{1,2}:\d{2}/',
    '/レジ|責|担当|取引|領収|お客様|店舗|店/i',
    '/ポイント|会員|クーポン/i',
    '/税込|税率|標準|軽減|対象/i',
  ];
  foreach ($patterns as $p) {
    if (preg_match($p, $s)) return true;
  }
  return false;
}

function is_totalish_line(string $s): bool {
  return (bool)preg_match('/^(合計|小計|総合計|計)\b/u', $s)
      || (bool)preg_match('/\b(合計|小計|総合計)\b/u', $s);
}

function looks_like_tax_or_payment(string $name): bool {
  // 商品名に見えても、税や支払系なら除外
  return (bool)preg_match('/消費税|内税|外税|お預り|お釣り|釣|現金|クレジット|電子|交通系|支払|預り/u', $name);
}

function extract_price_at_end(string $s): ?int {
  // 行末に「¥247」「247」「247円」などが来る想定（カンマ/スペース混在も吸収）
  if (preg_match('/(?:¥\s*)?([0-9]{1,5})(?:\s*円)?\s*$/u', $s, $m)) {
    return (int)$m[1];
  }
  return null;
}

function remove_price_part(string $s): string {
  // 行末の価格部分を除去して商品名だけに
  $s = preg_replace('/(?:¥\s*)?[0-9]{1,5}(?:\s*円)?\s*$/u', '', $s);
  return trim($s);
}

function clean_item_name(string $s): string {
  $s = trim($s);

  // 先頭の装飾記号を除去（例：◎、■、●、・、* など）
  $s = preg_replace('/^[\s\-\*\•\·\●\■\◆\◎\○\・\.\,]+/u', '', $s);
  $s = trim($s);

  // 「軽」単体、または行末に付く「軽」を除去（軽減税率のOCR混入対策）
  // 例: "天然水... 軽" → "天然水..."
  $s = preg_replace('/\s*軽\s*$/u', '', $s);
  $s = trim($s);

  // 余計な全角/半角スペースを整える
  $s = preg_replace('/\s+/', ' ', $s);

  return $s;
}

function looks_like_item_name(string $s): bool {
  // 価格が無い行を「商品名候補」として貯める条件
  if ($s === '') return false;

  // 数字だらけはNG（JANや日時など）
  if (preg_match('/^[0-9\/\-\:\. ]+$/', $s)) return false;

  // 合計/税/支払っぽいのはNG
  if (is_totalish_line($s) || looks_like_tax_or_payment($s)) return false;

  // 文字が短すぎるのも避ける（1〜2文字だけ等）
  if (mb_strlen($s, 'UTF-8') < 3) return false;

  return true;
}

function find_total(array $lines): ?int {
  // 合計が同じ行にある
  foreach ($lines as $s) {
    if (preg_match('/\b合計\b/u', $s)) {
      $p = extract_price_at_end($s);
      if ($p !== null) return $p;
    }
  }

  // 「合計」だけの行 → 次行に金額、のパターン
  for ($i = 0; $i < count($lines) - 1; $i++) {
    if (preg_match('/^合計\s*$/u', $lines[$i])) {
      $p = extract_price_at_end($lines[$i+1]);
      if ($p !== null) return $p;
    }
  }

  return null;
}

function post_filter_items(array $items): array {
  $out = [];
  foreach ($items as $it) {
    $name = trim($it['name'] ?? '');
    $price = (int)($it['price'] ?? 0);
    if ($name === '' || $price <= 0) continue;

    // 合計や税が混ざってないか最終チェック
    if (is_totalish_line($name) || looks_like_tax_or_payment($name)) continue;

    $out[] = ['name' => $name, 'price' => $price];
  }
  return $out;
}
