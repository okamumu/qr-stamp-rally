<?php
/**
 * ブースの登録（セルフ登録API・2026-10-10新設）
 *
 * 事前に紙で渡した登録キー（lib/enroll_keys.php・1キー=1ブース）と出展者名で登録すると、
 * ブースのトークンと鍵が発行され、読み取り画面のURLとQRがその場で表示される。
 * 同じキーでもう一度登録すると：出展者名を入れていれば出展者名を修正、
 * 空のままなら再表示だけする（QRとURLは変わらない）。
 *
 *   画面   /stamp/enroll.php            … 入力フォーム
 *   登録   POST name=出展者名&key=登録キー
 */
require_once __DIR__ . '/lib/db.php';

$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$keys = enroll_keys();

$err = ''; $booth = null; $renamed = false;

// ブースの鍵（booth.php のURLと同じ b＋key）でも開ける。
// 読み取り画面の「登録情報を表示」から戻ってきて、QR・URLの再表示と名前の修正ができる
$bt = strtoupper(trim((string)($_REQUEST['b'] ?? '')));
if ($bt !== '' && booth_ok($bt, (string)($_REQUEST['key'] ?? ''))) {
    $all   = booths();
    $booth = ['token' => $bt, 'name' => $all[$bt]['name'], 'key' => $all[$bt]['key']];
    $name  = trim((string)($_POST['name'] ?? ''));
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $name !== '' && $name !== $booth['name']) {
        if (mb_strlen($name) > 60) {
            $err = '出展者名は60文字までです';
        } else {
            db()->prepare('UPDATE booth SET name = ? WHERE token = ?')->execute([$name, $bt]);
            $booth['name'] = $name;
            $renamed = true;
        }
    }
} elseif ($bt !== '') {
    sleep(1);                       // ブース鍵の総当たりを遅くする（入力フォームに落とす）
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $key  = strtoupper(trim((string)($_POST['key'] ?? '')));
    $valid = false;
    foreach ($keys as $k) if (hash_equals($k, $key)) { $valid = true; break; }
    if (!$valid) {
        sleep(1);                   // キーの総当たりを遅くする
        $err = '登録キーが違います';
    } else {
        $q = db()->prepare('SELECT token, name, key FROM booth WHERE enroll_key = ?');
        $q->execute([$key]);
        $booth = $q->fetch(PDO::FETCH_ASSOC);
        if ($booth && $name !== '' && $name !== $booth['name'] && mb_strlen($name) <= 60) {
            db()->prepare('UPDATE booth SET name = ? WHERE token = ?')
                ->execute([$name, $booth['token']]);
            $booth['name'] = $name;
            $renamed = true;
        }
        if (!$booth) {
            if ($name === '' || mb_strlen($name) > 60) {
                $err = '出展者名を入力してください（60文字まで）';
            } else {
                $A = PASS_ALPH;
                do {
                    $token = '';
                    for ($i = 0; $i < 4; $i++) $token .= $A[random_int(0, 31)];
                    $dup = db()->prepare('SELECT 1 FROM booth WHERE token = ?');
                    $dup->execute([$token]);
                } while ($dup->fetchColumn());
                $bkey = '';
                for ($i = 0; $i < 10; $i++) $bkey .= $A[random_int(0, 31)];
                db()->prepare('INSERT INTO booth (token,name,key,enroll_key,ts) VALUES (?,?,?,?,?)')
                    ->execute([$token, $name, $bkey, $key, now_()]);
                $booth = ['token' => $token, 'name' => $name, 'key' => $bkey];
            }
        }
    }
}

$url = $booth
    ? (is_https() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
      . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/booth.php?b=' . rawurlencode($booth['token'])
      . '&key=' . rawurlencode($booth['key'])
    : '';
?><!doctype html>
<html lang="ja">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>ブースの登録｜スタンプラリー</title>
<style>
 body{font-family:"Hiragino Sans",sans-serif;padding:18px;line-height:1.7;max-width:560px;margin:0 auto}
 h1{font-size:17px;color:#1f4e9c;margin-bottom:12px}
 input{font:inherit;padding:10px;width:100%;box-sizing:border-box;border:1px solid #ccc;border-radius:8px;margin:4px 0 12px}
 button{display:block;width:100%;padding:14px;border-radius:10px;border:0;background:#1f4e9c;color:#fff;font-size:17px;font-weight:700}
 .ng{background:#fdecea;border:1px solid #e57373;color:#b71c1c;padding:10px;border-radius:8px;margin:12px 0}
 .done{background:#e8f5e9;border:1px solid #81c784;padding:14px;border-radius:8px;margin:12px 0;text-align:center}
 .done .qr{margin:10px auto}
 .url{font-family:ui-monospace,monospace;font-size:11px;word-break:break-all}
 .url a{color:#1f4e9c}
 .copy{display:inline-block;width:auto;padding:8px 16px;font-size:14px;margin-top:8px}
 p.c{color:#777;font-size:13px}
</style>
<h1>スタンプラリー　ブース登録</h1>
<p style="color:#777;font-size:13px;margin-bottom:10px"><?= $h(event_title()) ?>　<?= $h(event_date()) ?></p>
<?php if ($err): ?><div class="ng"><?= $h($err) ?></div><?php endif; ?>
<?php if ($booth): ?>
  <div class="done">
    <b><?= $h($booth['name']) ?></b> のブースを<?= $renamed ? '修正しました（出展者名のみ。QRはそのまま有効です）' : '登録しました' ?>。<br>
    この端末で使うなら下のリンクを開いて<b>ブックマーク</b>、
    別の端末で使うなら下のQRをその端末で読んでください。<br>
    <div class="qr" id="qr"></div>
    <div class="url"><a href="<?= $h($url) ?>"><?= $h($url) ?></a></div>
    <button class="copy" type="button" id="copyBtn">URLをコピー</button>
  </div>
  <form method="post" style="text-align:left">
    <input type="hidden" name="b" value="<?= $h($booth['token']) ?>">
    <input type="hidden" name="key" value="<?= $h($booth['key']) ?>">
    <label>出展者名の修正（名札や集計に表示されます。QRとURLは変わりません）
      <input name="name" value="<?= $h($booth['name']) ?>" maxlength="60"></label>
    <button type="submit">名前を保存</button>
  </form>
  <script src="qrgen.js"></script>
  <script>
    var q = qrcode(0, 'M');
    q.addData(<?= json_encode($url) ?>);
    q.make();
    document.getElementById('qr').innerHTML = q.createImgTag(5, 8);
    // コピーボタン（http のローカル環境など clipboard が使えないときは選択で代用）
    document.getElementById('copyBtn').addEventListener('click', async function () {
      var u = <?= json_encode($url) ?>, b = this;
      try {
        await navigator.clipboard.writeText(u);
        b.textContent = 'コピーしました';
      } catch (e) {
        var r = document.createRange();
        r.selectNodeContents(document.querySelector('.url'));
        var s = getSelection(); s.removeAllRanges(); s.addRange(r);
        b.textContent = '選択しました（長押しでコピー）';
      }
    });
  </script>
<?php else: ?>
  <form method="post">
    <label>出展者名（名札や集計に表示されます）<input name="name" maxlength="60"></label>
    <label>登録キー（お渡しした紙に記載）<input name="key" autocapitalize="characters"></label>
    <button type="submit">登録する</button>
  </form>
  <p class="c">同じ登録キーでもう一度登録すると、出展者名を修正できます（QRは変わりません）。
  出展者名を空のまま送信すると、発行済みのQRを再表示します。</p>
<?php endif; ?>
