<?php
// index.php
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title>Famima Receipt OCR</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>
  <h1>ファミリーマート レシートOCR</h1>

  <form action="upload.php" method="post" enctype="multipart/form-data">
    <p>
      <input type="file" name="receipts[]" accept="image/*" multiple required>
    </p>
    <button type="submit">アップロードして解析</button>
  </form>

  <p style="color:#666;">※ファミリーマートのレシート3枚（テスト画像）だけ対応</p>
</body>
</html>
