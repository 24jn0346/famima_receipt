<?php
// famima_parser.php

function normalize_line(string $s): string {
  $s = trim($s);
  // 全角→半角っぽいゆらぎを少し吸収（必要最低限）
  $s = str_replace(["￥", "¥ "], ["¥", "¥"], $s);
  // 余計なスペースを詰める
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim($s);
}

function clean_item_name(string $name): string {
  $name = trim($name);
  // 末尾の「軽」など不要な1文字を落とす（要件：余計な文字は入れない）
  $name = preg_replace('/[軽税]$/u', '', $name);
  // 末尾の記号ゴミ
  $name = preg_replace('/[.,。・]$/u', '', $name);
  return trim($name);
}

function parse_famima_receipt(array $ocrLines): array {
  $lines = array_values(array_filter(array_map('normalize_line', $ocrLines)));

  $items = [];
  $total = null;

  // 1) 合計抽出（「合 計」「合計」どちらもOK）
  foreach ($lines as $ln) {
    $compact = preg_replace('/\s+/u', '', $ln);
    if (mb_strpos($compact, '合計') !== false) {
      if (preg_match('/[¥￥]?\s*([0-9]{2,6})/u', $ln, $m)) {
        $total = (int)$m[1];
        break;
      }
    }
  }

  // 2) 商品行抽出： "商品名 .... ¥123" もしくは "商品名 .... 123"
  //    ただし「電話」「登録番号」「レジ」「No」などは除外
  $ng = ['電話','登録番号','レジ','No','対象','内消費税','交通系','残高','カード番号','領収証','日時','東京都','新宿区'];

  foreach ($lines as $ln) {
    $skip = false;
    foreach ($ng as $w) {
      if (mb_strpos($ln, $w) !== false) { $skip = true; break; }
    }
    if ($skip) continue;

    // 価格が含まれる行だけ拾う
    if (preg_match('/^(.*?)[ ]*[¥￥]?\s*([0-9]{2,6})\s*([軽税])?$/u', $ln, $m)) {
      $name = clean_item_name($m[1]);
      $price = (int)$m[2];

      // 合計行などを混ぜない
      $c = preg_replace('/\s+/u', '', $name);
      if ($c === '' || mb_strpos($c, '合計') !== false) continue;

      $items[] = ['name' => $name, 'price' => $price];
    }
  }

  // 3) Famimaレシートは「合計」より上に商品があるので、
  //    合計より後ろで拾った怪しい行を落としたい場合はここで調整可能

  return ['items' => $items, 'total' => $total];
}
