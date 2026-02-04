<?php
// vision_ocr.php

function vision_read_ocr_bytes(string $imageBytes): array {
  $endpoint = rtrim(getenv('VISION_ENDPOINT'), '/');
  $apiKey   = getenv('VISION_KEY');

  if (!$endpoint || !$apiKey) {
    throw new RuntimeException("VISION_ENDPOINT / VISION_KEY が未設定です");
  }

  // Image Analysis 4.0: POST /imageanalysis:analyze?features=read&api-version=2024-02-01
  // 画像ストリームも受け取れる（image/* or application/octet-stream）:contentReference[oaicite:3]{index=3}
  $url = $endpoint . "/imageanalysis:analyze?features=read&language=ja&api-version=2024-02-01";

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      "Ocp-Apim-Subscription-Key: {$apiKey}",
      "Content-Type: application/octet-stream",
    ],
    CURLOPT_POSTFIELDS => $imageBytes,
    CURLOPT_TIMEOUT => 60,
  ]);

  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false) {
    throw new RuntimeException("OCR通信失敗: {$err}");
  }
  $json = json_decode($resp, true);

  if ($http < 200 || $http >= 300) {
    $msg = $json['error']['message'] ?? $resp;
    throw new RuntimeException("OCR失敗 HTTP{$http}: {$msg}");
  }
  return $json;
}

function ocr_lines_from_result(array $json): array {
  $lines = [];
  $blocks = $json['readResult']['blocks'] ?? [];
  foreach ($blocks as $b) {
    foreach (($b['lines'] ?? []) as $line) {
      if (!empty($line['text'])) $lines[] = $line['text'];
    }
  }
  return $lines;
}
