<?php
/** 受付の管理画面。名札QRを運営端末で読むと ?n=CODE が付いて開く。 */
require_once __DIR__ . '/lib/db.php';

if (!admin_ok()) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><p style="font:16px sans-serif;padding:20px">鍵が違います</p>';
    exit;
}

$code = strtoupper(trim(isset($_REQUEST['n']) ? $_REQUEST['n'] : ''));
$msg  = '';
$boo  = booth_names();
$all  = count($boo);

// --- 操作 ---
if ($code !== '' && isset($_POST['act'])) {
    $d = draw_of($code);
    $got = count(got_of($code));
    if ($_POST['act'] === 'register' && pass_ok($code)) {
        pass_register($code);
        $msg = '参加登録しました。ラリーに参加できます';
    } elseif ($_POST['act'] === 'gift') {
        db()->prepare('UPDATE draw SET gift = "済" WHERE id = (SELECT MAX(id) FROM draw WHERE code = ?)')
            ->execute([$code]);
        $msg = '参加賞を渡しました';
    } elseif ($_POST['act'] === 'draw1' && $d['count'] < 1 && $got >= need1()) {
        db()->prepare('INSERT INTO draw (ts, code, n) VALUES (?, ?, 1)')->execute([now_(), $code]);
        $msg = '1回目の抽選を記録しました';
    } elseif ($_POST['act'] === 'draw2' && $d['count'] < 2 && $got >= need2()) {
        db()->prepare('INSERT INTO draw (ts, code, n) VALUES (?, ?, 2)')->execute([now_(), $code]);
        $msg = '2回目の抽選を記録しました';
    }
    // 二重送信を防ぐため GET へ戻す
    header('Location: ?admin=' . urlencode(admin_key()) . '&n=' . urlencode($code) . '&m=' . urlencode($msg));
    exit;
}

$msg = isset($_GET['m']) ? $_GET['m'] : '';
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?><!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>スタンプラリー受付</title>
<style>
 body{font-family:"Hiragino Sans",sans-serif;padding:18px;line-height:1.7;max-width:560px;margin:0 auto}
 h1{font-size:16px;color:#666;font-weight:400;margin-bottom:14px}
 h2{font-size:24px;margin:0 0 2px} .c{color:#888;font-size:13px;margin:0 0 14px}
 .big{font-size:44px;font-weight:700;color:#1f4e9c;text-align:center;margin:8px 0}
 .st{text-align:center;margin-bottom:12px}
 button{display:block;width:100%;padding:18px;border-radius:10px;margin:12px 0;border:0;
        font-size:19px;font-weight:700;font-family:inherit}
 .ok{background:#2e7d32;color:#fff}
 .sub{background:#fff;border:2px solid #1f4e9c;color:#1f4e9c}
 .ng{background:#fdecea;border:1px solid #e57373;color:#b71c1c;padding:12px;border-radius:8px;text-align:center;margin:12px 0}
 .done{background:#e8f5e9;border:1px solid #81c784;color:#1b5e20;padding:10px;border-radius:8px;text-align:center;margin:12px 0}
 input{font:inherit;padding:10px;width:100%;box-sizing:border-box;border:1px solid #ccc;border-radius:8px}
 ul{margin:14px 0 0;padding-left:18px;color:#666;font-size:14px}
</style>
<h1>企業展示スタンプラリー　受付</h1>
<?php if ($msg): ?><div class="done"><?= $h($msg) ?></div><?php endif; ?>

<?php if ($code === ''): ?>
  <button class="ok" id="scanBtn" type="button">名札QRを読む</button>
  <div id="cam" hidden>
    <video id="v" playsinline muted style="width:100%;border-radius:10px;background:#000"></video>
    <p id="camMsg" style="text-align:center;color:#666">カメラを起動しています…</p>
    <button class="sub" type="button" id="stopBtn">やめる</button>
  </div>
  <form method="get" style="margin-top:18px">
    <input type="hidden" name="admin" value="<?= $h(admin_key()) ?>">
    <input name="n" placeholder="手入力もできます（例 9KXKVT）" autocapitalize="characters">
    <button class="sub" type="submit">照会</button>
  </form>
  <script src="jsQR.js"></script>
  <script>
  (function () {
    const KEY = <?= json_encode(admin_key()) ?>;
    const btn = document.getElementById('scanBtn'),
          cam = document.getElementById('cam'),
          v   = document.getElementById('v'),
          msg = document.getElementById('camMsg');
    let stream = null, timer = null, det = null;

    /** 読み取った文字列から名札コードを取り出す。
        学生ページのURL（…/stamp/?n=XXXXXX）でも、コード単体でも受ける。 */
    function pick(text) {
      if (!text) return '';
      let m = String(text).match(/[?&]n=([2-9A-HJ-NP-Z]{6})/i);
      if (m) return m[1].toUpperCase();
      m = String(text).trim().match(/^[2-9A-HJ-NP-Z]{6}$/i);
      return m ? m[0].toUpperCase() : '';
    }

    function go(code) {
      stop();
      location.href = 'admin.php?admin=' + encodeURIComponent(KEY) + '&n=' + encodeURIComponent(code);
    }

    function stop() {
      if (timer) { clearInterval(timer); timer = null; }
      if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
      cam.hidden = true; btn.hidden = false;
    }

    btn.addEventListener('click', async function () {
      btn.hidden = true; cam.hidden = false; msg.textContent = 'カメラを起動しています…';
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'environment' }, audio: false });
      } catch (e) {
        msg.textContent = 'カメラを使えません（' + e.name + '）。手入力をお使いください。';
        btn.hidden = false; return;
      }
      v.srcObject = stream; await v.play();
      msg.textContent = '名札のQRを枠に入れてください';

      // Chrome/Android は標準の検出器、無ければ同梱の jsQR を使う（iOS Safari 対策）
      if (window.BarcodeDetector) {
        try { det = new BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { det = null; }
      }
      const cv = document.createElement('canvas'), cx = cv.getContext('2d', { willReadFrequently: true });

      timer = setInterval(async function () {
        if (!v.videoWidth) return;
        let text = '';
        if (det) {
          try { const r = await det.detect(v); if (r && r.length) text = r[0].rawValue; } catch (e) {}
        }
        if (!text && window.jsQR) {
          cv.width = v.videoWidth; cv.height = v.videoHeight;
          cx.drawImage(v, 0, 0, cv.width, cv.height);
          const img = cx.getImageData(0, 0, cv.width, cv.height);
          const r = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
          if (r) text = r.data;
        }
        const code = pick(text);
        if (code) go(code);
        else if (text) msg.textContent = 'これは名札のQRではありません';
      }, 250);
    });

    document.getElementById('stopBtn').addEventListener('click', stop);
  })();
  </script>

<?php elseif (!pass_ok($code)): ?>
  <div class="ng">この名札は無効です（コードの形式が違います）</div>
  <p class="c"><?= $h($code) ?></p>

<?php elseif (!pass_registered($code)): ?>
  <h2>参加証（未登録）</h2>
  <p class="c"><?= $h($code) ?></p>
  <div class="ng">この名札はまだラリーに参加登録されていません</div>
  <form method="post">
    <input type="hidden" name="admin" value="<?= $h(admin_key()) ?>">
    <input type="hidden" name="n" value="<?= $h($code) ?>">
    <button class="ok" name="act" value="register">参加登録する</button>
  </form>

<?php else:
  $got = got_of($code); $n = count($got); $d = draw_of($code);
  $can1 = $n >= need1() && $d['count'] < 1;
  $can2 = $n >= $all  && $d['count'] < 2;
?>
  <h2>参加証</h2>
  <p class="c"><?= $h($code) ?></p>
  <div class="big"><?= $n ?> / <?= $all ?> 社</div>
  <div class="st">抽選 <b><?= $d['count'] ?></b> 回済み　参加賞 <?= $d['gift'] ? '<b>渡し済み</b>' : 'まだ' ?></div>

  <form method="post">
    <input type="hidden" name="admin" value="<?= $h(admin_key()) ?>">
    <input type="hidden" name="n" value="<?= $h($code) ?>">
    <?php if ($can1): ?>
      <button class="ok" name="act" value="draw1">抽選（1回目）を実施</button>
    <?php elseif ($can2): ?>
      <button class="ok" name="act" value="draw2">抽選（2回目）を実施</button>
    <?php elseif ($d['count'] >= 2): ?>
      <div class="ng">2回とも抽選済みです</div>
    <?php elseif ($d['count'] >= 1): ?>
      <div class="ng">1回目は済み。<?= need2() ?>社そろうと2回目を引けます</div>
    <?php else: ?>
      <div class="ng">あと<?= need1() - $n ?>社です</div>
    <?php endif; ?>
    <?php if (!$d['gift'] && $d['count'] >= 1): ?>
      <button class="sub" name="act" value="gift">参加賞を渡した</button>
    <?php endif; ?>
  </form>

  <ul><?php foreach ($boo as $t => $name): ?>
    <li><?= in_array($t, $got, true) ? '✓ ' : '　' ?><?= $h($name) ?></li>
  <?php endforeach; ?></ul>
<?php endif; ?>

<p style="margin-top:22px"><a href="?admin=<?= $h(admin_key()) ?>">次の人を読む</a>　/　
<a href="export.php?admin=<?= $h(admin_key()) ?>">集計を見る</a></p>
