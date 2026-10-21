<?php
/**
 * ブース用の読み取り画面。
 *
 * 各ブースに配る QR は、このページへの鍵つきURL（?b=トークン&key=鍵）。
 * 担当者は朝に一度開いてカメラを起動し、説明後に参加者の名札QRを読む。
 * **参加者の端末には何も保存しないので、ブラウザの違いや電池切れに左右されない。**
 */
require_once __DIR__ . '/lib/db.php';

$token = strtoupper(trim(isset($_REQUEST['b']) ? $_REQUEST['b'] : ''));
$key   = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';

if (!booth_ok($token, $key)) {
    sleep(1);                       // 鍵の総当たりを遅くする
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<p style="font:16px sans-serif;padding:24px;line-height:1.7">このページはブース担当者向けです。<br>'
       . 'お渡ししたQRコードから開いてください。</p>';
    exit;
}

$names = booth_names();
$name  = $names[$token];
$all   = count($names);

// --- 読み取りの登録（ページ内から呼ばれる） ---
if (isset($_GET['scan'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    // **必ず JSON を返す。** 例外をそのまま出すと画面に PHP のエラーが並び、
    // 当日にブース担当者を不安にさせる。
    try {
        $code = strtoupper(trim(isset($_GET['n']) ? $_GET['n'] : ''));
        if (!preg_match('/^[2-9A-HJ-NP-Z]{6}$/', $code)) {
            echo json_encode(['ok' => false, 'msg' => '名札のQRではありません']); exit;
        }
        if (!pass_ok($code)) {
            echo json_encode(['ok' => false, 'msg' => 'この名札は無効です']); exit;
        }
        if (!pass_registered($code)) {
            echo json_encode(['ok' => false,
                'msg' => '未登録です。先に受付で参加登録をお願いします']); exit;
        }
        $before = got_of($code);
        $dup    = in_array($token, $before, true);
        if (!$dup) {
            db()->prepare('INSERT INTO scan (ts, code, booth, note) VALUES (?, ?, ?, "")')
                ->execute([now_(), $code, $token]);
        }
        $n = count($dup ? $before : array_merge($before, [$token]));
        echo json_encode([
            'ok'   => true,
            'dup'  => $dup,
            'code' => $code,
            'n'    => $n,
            'all'  => $all,
            'msg'  => $dup ? 'すでに押してあります' : 'スタンプしました',
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => 'サーバで記録できませんでした']);
    }
    exit;
}

$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $h($name) ?>｜スタンプラリー</title>
<style>
 :root{--ok:#2e7d32;--dup:#f9a825;--ng:#c0392b;--line:#ddd}
 *{box-sizing:border-box;margin:0;padding:0}
 body{font-family:"Hiragino Sans",sans-serif;background:#f4f5f7;color:#222;line-height:1.6;
      padding:12px;max-width:560px;margin:0 auto}
 header{text-align:center;padding:4px 0 10px}
 header h1{font-size:17px;color:#1f4e9c}
 header p{font-size:12px;color:#777}
 .panel{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:12px}
 #res{min-height:104px;display:grid;place-content:center;text-align:center;border-radius:12px;
      padding:18px;margin-bottom:12px;background:#fff;border:1px solid var(--line)}
 #res.ok{background:#e8f5e9;border-color:var(--ok)}
 #res.dup{background:#fff8e1;border-color:var(--dup)}
 #res.ng{background:#fdecea;border-color:#e57373}
 #res .m{font-size:26px;font-weight:700}
 #res.ok .m{color:#1b5e20} #res.dup .m{color:#8d6e00} #res.ng .m{color:#b71c1c}
 #res .s{font-size:15px;color:#555;margin-top:4px}
 video{width:100%;border-radius:12px;background:#000;display:block}
 video[hidden],button[hidden]{display:none}
 button{font:inherit;width:100%;padding:16px;border:0;border-radius:10px;
        font-size:18px;font-weight:700;margin-top:10px}
 .start{background:#1f4e9c;color:#fff}
 .stop{background:#fff;border:2px solid #999;color:#555}
 .cnt{display:flex;justify-content:space-between;font-size:14px;color:#666;padding:2px 4px}
 ol{font-size:14px;color:#555;padding-left:20px} ol li{margin:4px 0}
</style>
</head>
<body>
<header>
  <h1><?= $h($name) ?></h1>
  <p>スタンプラリー　受付用（参加者には見せないでください）</p>
</header>

<div id="res"><div class="m" style="color:#888;font-size:18px">「次の参加者を読み取る」を押してください</div></div>

<div class="panel">
  <video id="v" playsinline muted hidden></video>
  <button class="start" id="startBtn" type="button">次の参加者を読み取る</button>
  <button class="stop" id="stopBtn" type="button" hidden>止める</button>
  <div class="cnt"><span>本日の読み取り</span><span><b id="cnt">0</b> 件</span></div>
</div>

<div class="panel">
  <b style="font-size:14px">使い方</b>
  <ol>
    <li>参加者に展示のご説明をしてください</li>
    <li>「次の参加者を読み取る」を押し、<b>参加者の名札のQR</b>にカメラを向けます</li>
    <li>緑色で「スタンプしました」と出れば完了です。<b>カメラは自動で閉じます</b></li>
    <li>次の参加者には、もう一度「次の参加者を読み取る」を押してください</li>
  </ol>
  <p style="font-size:13px;color:#777;margin-top:8px">
    うまく読めないときは、参加者に受付へお越しいただくようお伝えください。</p>
</div>

<script src="jsQR.js"></script>
<script>
(function () {
  const TOKEN = <?= json_encode($token) ?>, KEY = <?= json_encode($key) ?>;
  const v = document.getElementById('v'), res = document.getElementById('res'),
        startBtn = document.getElementById('startBtn'), stopBtn = document.getElementById('stopBtn'),
        cntEl = document.getElementById('cnt');
  let stream = null, timer = null, det = null, busy = false, count = 0;

  /** 読み取ったら必ずカメラを閉じる。
      開いたままだと、近くのQR（他のカードや掲示物）をうっかり拾ってしまう。 */
  function closeCamera() {
    if (timer) { clearInterval(timer); timer = null; }
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
    v.hidden = true; startBtn.hidden = false; stopBtn.hidden = true;
  }

  function show(cls, m, s) {
    res.className = cls;
    res.innerHTML = '<div><div class="m">' + m + '</div>'
                  + (s ? '<div class="s">' + s + '</div>' : '') + '</div>';
  }

  /** 読み取った文字列から名札コードを取り出す（参加者ページのURLでもコード単体でも受ける） */
  function pick(text) {
    if (!text) return '';
    let m = String(text).match(/[?&]n=([2-9A-HJ-NP-Z]{6})/i);
    if (m) return m[1].toUpperCase();
    m = String(text).trim().match(/^[2-9A-HJ-NP-Z]{6}$/i);
    return m ? m[0].toUpperCase() : '';
  }

  async function send(code) {
    busy = true;
    closeCamera();
    show('', '記録しています…', code);
    try {
      const r = await fetch('booth.php?scan=1&b=' + encodeURIComponent(TOKEN)
                          + '&key=' + encodeURIComponent(KEY)
                          + '&n=' + encodeURIComponent(code), { cache: 'no-store' });
      const j = await r.json();
      if (!j.ok) { show('ng', j.msg, code); }
      else if (j.dup) { show('dup', j.msg, code + '　' + j.n + ' / ' + j.all + ' ブース'); }
      else { count++; cntEl.textContent = count;
             show('ok', j.msg, code + '　' + j.n + ' / ' + j.all + ' ブース'); }
    } catch (e) {
      show('ng', '通信できませんでした', 'もう一度読み取ってください');
    }
    busy = false;
  }

  startBtn.addEventListener('click', async function () {
    startBtn.hidden = true; stopBtn.hidden = false; v.hidden = false;
    show('', 'カメラを起動しています…', '');
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment', width: { ideal: 1280 } }, audio: false });
    } catch (e) {
      show('ng', 'カメラを使えません', e.name + '　受付にお知らせください');
      startBtn.hidden = false; stopBtn.hidden = true; v.hidden = true; return;
    }
    v.srcObject = stream; await v.play();
    show('', '名札のQRにカメラを向けてください', '');

    if (window.BarcodeDetector) {
      try { det = new BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { det = null; }
    }
    const cv = document.createElement('canvas'),
          cx = cv.getContext('2d', { willReadFrequently: true });

    timer = setInterval(async function () {
      if (busy || !v.videoWidth) return;
      let text = '';
      if (det) { try { const r = await det.detect(v); if (r && r.length) text = r[0].rawValue; } catch (e) {} }
      if (!text && window.jsQR) {
        cv.width = v.videoWidth; cv.height = v.videoHeight;
        cx.drawImage(v, 0, 0, cv.width, cv.height);
        const img = cx.getImageData(0, 0, cv.width, cv.height);
        const r = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
        if (r) text = r.data;
      }
      if (!text) return;
      const code = pick(text);
      if (!code) {
        closeCamera();
        show('ng', '名札のQRではありません', 'もう一度お試しください');
        return;
      }
      send(code);
    }, 200);
  });

  stopBtn.addEventListener('click', function () {
    closeCamera();
    show('', '止めました', '「次の参加者を読み取る」で再開します');
  });

  // 画面が消えないように（対応端末のみ）
  if (navigator.wakeLock) {
    document.addEventListener('visibilitychange', async function () {
      if (document.visibilityState === 'visible') {
        try { await navigator.wakeLock.request('screen'); } catch (e) {}
      }
    });
  }
})();
</script>
</body>
</html>
