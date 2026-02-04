<?php
// famima_parser.php（型宣言なし・安全版）

function parse_famima_receipt($ocrLines) {
  if (!is_array($ocrLines)) $ocrLines = [];

  $lines = [];
  foreach ($ocrLines as $raw) {
    $s = normalize_line($raw);
    if ($s !== '') $lines[] = $s;
  }

  list($start, $end) = detect_item_region($lines);
  $region = array_slice($lines, $start, max(0, $end - $start + 1));

  $total = find_total_strong($region);
  if ($total === null) $total = find_total_strong($lines);

  $items = [];
  $pendingName = null;

  for ($i = 0; $i < count($region); $i++) {
    $line = $region[$i];

    if (is_noise_line_strong($line)) {
      $pendingName = null;
      continue;
    }
    if (is_totalish_line($line)) {
      $pendingName = null;
      continue;
    }

    $next = ($i + 1 < count($region)) ? $region[$i + 1] : null;

    $p = extract_price_yen($line);
    if ($p !== null) {
      $name = clean_item_name(remove_price_part_yen($line));
      if ($name !== '' && $p > 0) $items[] = ['name' => $name, 'price' => $p];
      $pendingName = null;
      continue;
    }

    $p2 = extract_price_only_line($line);
    if ($p2 !== null && $pendingName !== null) {
      $name = clean_item_name($pendingName);
      if ($name !== '' && $p2 > 0) $items[] = ['name' => $name, 'price' => $p2];
      $pendingName = null;
      continue;
    }

    if (looks_like_item_name($line)) {
      if (ends_with_small_number($line) && $next !== null && extract_price_yen($next) !== null) {
        $pendingName = $line;
      } else {
        $pendingName = $line;
      }
      continue;
    }

    $pendingName = null;
  }

  $items = array_values(array_filter($items, function($it){
    $n = isset($it['name']) ? trim($it['name']) : '';
    $p = isset($it['price']) ? (int)$it['price'] : 0;
    if ($n === '' || $p <= 0) return false;
    if (looks_like_tax_or_payment($n)) return false;
    if (is_totalish_line($n)) return false;
    return true;
  }));

  return ['items' => $items, 'total' => $total];
}

/* ---------- helpers（型宣言なし） ---------- */

function normalize_line($s) {
  // 配列が来ても落ちない保険
  if (is_array($s)) $s = implode(' ', $s);
  $s = (string)$s;

  $s = trim($s);
  if ($s === '') return '';

  if (function_exists('mb_convert_kana')) {
    $s = mb_convert_kana($s, 'asKV', 'UTF-8');
  }
  $s = str_replace(['￥', '¥'], '¥', $s);
  $s = str_replace(["\t", "　"], ' ', $s);
  $s = preg_replace('/\s+/', ' ', $s);
  return trim($s);
}

function compact_str($s) {
  if (is_array($s)) $s = implode('', $s);
  $s = (string)$s;
  return preg_replace('/\s+/u', '', $s);
}

function detect_item_region($lines) {
  $start = 0;
  $end = count($lines) - 1;

  for ($i = 0; $i < count($lines); $i++) {
    $c = compact_str($lines[$i]);
    if (mb_strpos($c, '領収証') !== false || mb_strpos($c, '領収') !== false) {
      $start = min($i + 1, count($lines) - 1);
      break;
    }
  }

  for ($i = $start; $i < count($lines); $i++) {
    $c = compact_str($lines[$i]);
    if (mb_strpos($c, '合計') !== false || preg_match('/^合計/u', $c)) {
      $end = min($i + 2, count($lines) - 1);
      break;
    }
  }

  return [$start, $end];
}

function is_noise_line_strong($s) {
  $c = compact_str($s);
  $pats = [
    '/登録番号/u','/賞No/u','/レジ/u','/電話|TEL/u',
    '/東京都|新宿区|北新宿/u','/\d{4}年\d{1,2}月\d{1,2}日/u','/\d{1,2}:\d{2}/u',
    '/交通系|支払|残高/u','/内消費税|消費税|税率/u',
  ];
  foreach ($pats as $p) if (preg_match($p, $c)) return true;
  return false;
}

function is_totalish_line($s) {
  $c = compact_str($s);
  return (mb_strpos($c, '合計') !== false || mb_strpos($c, '小計') !== false);
}

function extract_price_yen($s) {
  $s = (string)$s;
  if (preg_match('/¥\s*([0-9]{1,5})\s*(?:軽)?\s*$/u', $s, $m)) return (int)$m[1];
  return null;
}

function remove_price_part_yen($s) {
  $s = (string)$s;
  $s = preg_replace('/¥\s*[0-9]{1,5}\s*(?:軽)?\s*$/u', '', $s);
  return trim($s);
}

function extract_price_only_line($s) {
  $c = compact_str($s);
  if (preg_match('/^¥([0-9]{1,5})$/u', $c, $m)) return (int)$m[1];
  if (preg_match('/^([0-9]{1,5})$/u', $c, $m)) return (int)$m[1];
  return null;
}

function clean_item_name($s) {
  $s = (string)$s;
  $s = trim($s);
  $s = preg_replace('/^[\s\-\*\•\·\●\■\◆\◎\○\・\.\,]+/u', '', $s);
  $s = preg_replace('/\s*軽\s*$/u', '', $s);
  $s = preg_replace('/\s+/', ' ', $s);
  return trim($s);
}

function looks_like_tax_or_payment($s) {
  $s = (string)$s;
  return (bool)preg_match('/消費税|内税|外税|支払|交通系|残高|お預り|お釣り|釣/u', $s);
}

function looks_like_item_name($s) {
  $c = compact_str($s);
  if ($c === '') return false;
  if (is_totalish_line($s)) return false;
  if (looks_like_tax_or_payment($s)) return false;
  if (preg_match('/^[0-9\/\-\:\.]+$/', $c)) return false;
  if (mb_strlen($c, 'UTF-8') < 3) return false;
  return true;
}

function ends_with_small_number($s) {
  $s = (string)$s;
  return (bool)preg_match('/\s([0-9]{1,2})$/u', $s) || (bool)preg_match('/([0-9]{1,2})$/u', compact_str($s));
}

function find_total_strong($lines) {
  for ($i = 0; $i < count($lines); $i++) {
    $c = compact_str($lines[$i]);
    if (mb_strpos($c, '合計') !== false) {
      $p = extract_price_yen($lines[$i]);
      if ($p !== null) return $p;

      for ($j = 1; $j <= 3 && $i + $j < count($lines); $j++) {
        $p2 = extract_price_yen($lines[$i + $j]);
        if ($p2 !== null) return $p2;
        $p3 = extract_price_only_line($lines[$i + $j]);
        if ($p3 !== null) return $p3;
      }
    }
  }
  return null;
}
