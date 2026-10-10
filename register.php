<?php
/**
 * 参加登録の専用画面（受付スタッフ用・2026-10-11新設）
 *
 * 名札QRを読むと**その場で自動登録**し、続けて次を読める（ボタン操作なし）。
 * 抽選・参加賞は admin.php（抽選台用）。登録の実体は admin.php の登録APIを叩く。
 */
require_once __DIR__ . '/lib/db.php';

if (!admin_ok()) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><p style="font:16px sans-serif;padding:20px">鍵が違います</p>';
    exit;
}
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?><!doctype html>
<html lang="ja">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>参加登録｜スタンプラリー受付</title>
<style>
 body{font-family:"Hiragino Sans",sans-serif;padding:14px;line-height:1.6;max-width:560px;margin:0 auto;background:#f4f5f7}
 h1{font-size:16px;color:#1f4e9c;margin-bottom:4px}
 p.c{color:#777;font-size:12px;margin-bottom:10px}
 #res{min-height:92px;display:grid;place-content:center;text-align:center;border-radius:12px;
      background:#fff;border:1px solid #ddd;margin-bottom:10px;font-size:18px;font-weight:700;padding:10px}
 #res.ok{background:#e8f5e9;border-color:#81c784;color:#1b5e20}
 #res.dup{background:#fff8e1;border-color:#ffd54f;color:#7a5d00}
 #res.ng{background:#fdecea;border-color:#e57373;color:#b71c1c}
 #res small{display:block;font-size:12px;font-weight:400;color:#666;margin-top:4px}
 video{width:100%;border-radius:12px;background:#000}
 button{display:block;width:100%;padding:16px;border-radius:10px;margin:10px 0;border:0;
        font-size:18px;font-weight:700;font-family:inherit;background:#1f4e9c;color:#fff}
 input{font:inherit;padding:10px;width:100%;box-sizing:border-box;border:1px solid #ccc;border-radius:8px}
 .cnt{text-align:center;color:#666;font-size:13px}
</style>
<h1>参加登録（受付）</h1>
<p class="c">名札のQRを読むと、その場で登録されます。ボタン操作は不要です。
抽選・参加賞は抽選台の画面（admin.php）で。</p>
<div id="res">カメラを起動してください<small>登録済みの読み直しは黄色で出ます（害はありません）</small></div>
<video id="v" playsinline muted hidden></video>
<button id="btn" type="button">カメラを起動して読み取り開始</button>
<form onsubmit="return manual(this)">
  <input name="n" placeholder="手入力（例 9KXKVT）" autocapitalize="characters">
</form>
<p class="cnt">この端末での登録 <b id="cnt">0</b> 件</p>
<script src="jsQR.js"></script>
<script>
(function () {
  const KEY = <?= json_encode(admin_key()) ?>;
  const v = document.getElementById('v'), btn = document.getElementById('btn'),
        res = document.getElementById('res'), cntEl = document.getElementById('cnt');
  let stream = null, timer = null, det = null, busy = false, last = '', lastAt = 0, cnt = 0;

  function pick(text) {
    if (!text) return '';
    let m = String(text).match(/[?&]n=([2-9A-HJ-NP-Z]{6})/i);
    if (m) return m[1].toUpperCase();
    m = String(text).trim().match(/^[2-9A-HJ-NP-Z]{6}$/i);
    return m ? m[0].toUpperCase() : '';
  }

  function show(cls, main, sub) {
    res.className = cls;
    res.innerHTML = main + (sub ? '<small>' + sub + '</small>' : '');
  }

  async function register(code) {
    if (busy) return;
    const now = Date.now();
    if (code === last && now - lastAt < 4000) return;   // 同じQRを映しっぱなし対策
    busy = true; last = code; lastAt = now;
    try {
      const r = await fetch('admin.php?admin=' + encodeURIComponent(KEY)
                            + '&api=register&n=' + encodeURIComponent(code));
      const d = await r.json();
      if (!d.ok)            show('ng', code + '：この名札は無効です', '形式が違います。総合受付の責任者へ');
      else if (d.already)   show('dup', code + '：登録済みです', 'そのままお通しください');
      else { cnt++; cntEl.textContent = cnt; show('ok', code + '：登録しました', '次の方のQRを読んでください'); }
    } catch (e) {
      show('ng', '通信できませんでした', 'もう一度かざしてください');
      last = '';
    }
    busy = false;
  }

  window.manual = function (f) {
    const code = pick(f.n.value);
    if (code) { register(code); f.n.value = ''; }
    else show('ng', 'コードの形式が違います', '6文字（例 9KXKVT）');
    return false;
  };

  btn.addEventListener('click', async function () {
    if (stream) return;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
    } catch (e) { show('ng', 'カメラを使えません（' + e.name + '）', '手入力をお使いください'); return; }
    v.hidden = false; v.srcObject = stream; await v.play();
    btn.hidden = true;
    show('', '名札のQRを枠に入れてください');
    if (window.BarcodeDetector) {
      try { det = new BarcodeDetector({ formats: ['qr_code'] }); } catch (e) { det = null; }
    }
    const cv = document.createElement('canvas'), cx = cv.getContext('2d', { willReadFrequently: true });
    timer = setInterval(async function () {
      if (!v.videoWidth || busy) return;
      let text = '';
      if (det) { try { const r = await det.detect(v); if (r && r.length) text = r[0].rawValue; } catch (e) {} }
      if (!text && window.jsQR) {
        cv.width = v.videoWidth; cv.height = v.videoHeight;
        cx.drawImage(v, 0, 0, cv.width, cv.height);
        const img = cx.getImageData(0, 0, cv.width, cv.height);
        const r = jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
        if (r) text = r.data;
      }
      const code = pick(text);
      if (code) register(code);
    }, 300);
  });
})();
</script>
