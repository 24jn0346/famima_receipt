<?php
// document_intel.php

/**
 * Document Intelligence v4.0 (2024-11-30)
 * POST  {endpoint}/documentintelligence/documentModels/prebuilt-receipt:analyze?api-version=2024-11-30
 * -> 202 + Operation-Location
 * GET   Operation-Location をポーリングして結果取得
 *
 * 参考: REST の呼び出し形式（documentModels/{modelId}:analyze + api-version） :contentReference[oaicite:1]{index=1}
 */

function di_analyze_receipt_bytes(string $bytes): array {
  $endpoint = rtrim((string)getenv('DI_ENDPOINT'), '/');
  $key = (string)getenv('DI_KEY');

  if ($endpoint === '' || $key === '') {
    throw new RuntimeException("DI_ENDPOINT / DI_KEY が未設定です");
  }

  $apiVersion = "2024-11-30";
  $url = "{$endpoint}/documentintelligence/documentModels/prebuilt-receipt:analyze?api-version={$apiVersion}";

  // --- POST (binary) ---
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true, // ヘッダーから Operation-Location を拾う
    CURLOPT_HTTPHEADER => [
      "Ocp-Apim-Subscription-Key: {$key}",
      "Content-Type: application/octet-stream",
    ],
    CURLOPT_POSTFIELDS => $bytes,
    CURLOPT_TIMEOUT => 120,
  ]);

  $resp = curl_exec($ch);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException("DI通信失敗: {$err}");
  }

  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);

  $rawHeaders = substr($resp, 0, $headerSize);
  $body = substr($resp, $headerSize);

  if ($http !== 202) {
    // 失敗時は body にエラーJSONが入ることが多い
    $json = json_decode($body, true);
    $msg = $json['error']['message'] ?? $body;
    throw new RuntimeException("DI POST失敗 HTTP{$http}: {$msg}");
  }

  $opLoc = di_pick_header($rawHeaders, 'operation-location');
  if (!$opLoc) {
    throw new RuntimeException("Operation-Location が取得できませんでした。レスポンスヘッダ: {$rawHeaders}");
  }

  // --- GET (polling) ---
  $deadline = time() + 60; // 最大60秒待つ
  while (true) {
    if (time() > $deadline) {
      throw new RuntimeException("DI解析がタイムアウトしました");
    }
    usleep(600000); // 0.6秒

    $ch = curl_init($opLoc);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        "Ocp-Apim-Subscription-Key: {$key}",
      ],
      CURLOPT_TIMEOUT => 60,
    ]);
    $r = curl_exec($ch);
    $h = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $e = curl_error($ch);
    curl_close($ch);

    if ($r === false) {
      throw new RuntimeException("DI GET失敗: {$e}");
    }

    $json = json_decode($r, true);
    if ($h < 200 || $h >= 300) {
      $msg = $json['error']['message'] ?? $r;
      throw new RuntimeException("DI GET失敗 HTTP{$h}: {$msg}");
    }

    $status = $json['status'] ?? '';
    if ($status === 'succeeded') {
      return $json;
    }
    if ($status === 'failed') {
      $msg = $json['error']['message'] ?? json_encode($json, JSON_UNESCAPED_UNICODE);
      throw new RuntimeException("DI 解析 failed: {$msg}");
    }
    // running / notStarted は継続
  }
}

function di_pick_header(string $rawHeaders, string $name): ?string {
  $lines = preg_split("/\r\n|\n|\r/", $rawHeaders);
  $nameLower = strtolower($name);
  foreach ($lines as $line) {
    $pos = strpos($line, ':');
    if ($pos === false) continue;
    $k = strtolower(trim(substr($line, 0, $pos)));
    if ($k === $nameLower) {
      return trim(substr($line, $pos + 1));
    }
  }
  return null;
}

/** ocr.log 用：ページの lines を文字列配列で返す（なければ content を1行で返す） */
function di_lines_from_result(array $json): array {
  $lines = [];
  $pages = $json['analyzeResult']['pages'] ?? [];
  foreach ($pages as $p) {
    foreach (($p['lines'] ?? []) as $ln) {
      $t = $ln['content'] ?? '';
      if ($t !== '') $lines[] = $t;
    }
  }
  if (!$lines) {
    $content = $json['analyzeResult']['content'] ?? '';
    if ($content !== '') $lines = preg_split("/\r\n|\n|\r/", $content);
  }
  return array_values(array_filter(array_map('trim', $lines), fn($s) => $s !== ''));
}

/**
 * prebuilt-receipt から items/total を抽出（できるだけ頑丈に）
 * - Items: 配列（各要素が object）
 * - Total / TotalPrice / TotalAmount など、環境差を吸収
 */
function di_extract_items_total(array $json): array {
  $doc = $json['analyzeResult']['documents'][0] ?? null;
  $fields = $doc['fields'] ?? [];

  // 合計（候補を順に探す）
  $total = null;
  foreach (['Total', 'TotalPrice', 'TotalAmount', 'AmountDue'] as $k) {
    if (isset($fields[$k])) {
      $cand = di_field_money_or_number($fields[$k]);
      if ($cand !== null) { $total = $cand; break; }
    }
  }

  // Items
  $items = [];
  $itemsField = $fields['Items'] ?? $fields['items'] ?? null;
  $arr = $itemsField['valueArray'] ?? null;

  if (is_array($arr)) {
    foreach ($arr as $row) {
      $obj = $row['valueObject'] ?? [];
      // 商品名候補
      $name = di_field_string($obj['Description'] ?? $obj['Name'] ?? $obj['ItemName'] ?? null);
      // 値段候補
      $price = di_field_money_or_number($obj['TotalPrice'] ?? $obj['Price'] ?? $obj['Amount'] ?? $obj['UnitPrice'] ?? null);

      $name = normalize_item_name($name);
      if ($name !== '' && $price !== null && $price > 0) {
        $items[] = ['name' => $name, 'price' => (int)$price];
      }
    }
  }

  return ['items' => $items, 'total' => $total !== null ? (int)$total : null];
}

function di_field_string($field): string {
  if (!is_array($field)) return '';
  // valueString があればそれ優先、なければ content
  $s = $field['valueString'] ?? $field['content'] ?? '';
  return is_string($s) ? trim($s) : '';
}

function di_field_money_or_number($field): ?int {
  if (!is_array($field)) return null;

  // valueCurrency: { amount: 123, currencyCode: ... }
  if (isset($field['valueCurrency']['amount'])) {
    return (int)round($field['valueCurrency']['amount']);
  }
  // valueNumber
  if (isset($field['valueNumber'])) {
    return (int)round($field['valueNumber']);
  }
  // content から抽出（￥やカンマや軽などが混ざっても拾う）
  $c = $field['content'] ?? '';
  if (!is_string($c)) return null;
  if (preg_match('/(\d[\d,]*)/', $c, $m)) {
    return (int)str_replace(',', '', $m[1]);
  }
  return null;
}

/**
 * “軽”や記号など、課題で「余計な文字は禁止」を満たすための最低限の正規化
 */
function normalize_item_name(string $name): string {
  $name = trim($name);
  // 先頭の記号（◎/○/●/© 等）を落とす
  $name = preg_replace('/^[^一-龠ぁ-んァ-ンA-Za-z0-9]+/u', '', $name) ?? $name;
  // 末尾の「軽」など余計な1文字が混ざるケースを落とす
  $name = preg_replace('/(軽|税|込|内|外)$/u', '', $name) ?? $name;
  return trim($name);
}
