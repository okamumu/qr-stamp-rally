<?php
/**
 * 管理コンソール（Basic認証・2026-10-10新設）
 *
 * できること:
 *   - 抽選条件（NEED1/NEED2）の変更（DBに保存。configの値は既定値）
 *   - 企業登録キーの追加発行と一覧（使用状況つき）
 *   - 未使用キーの登録用紙の印刷ビュー（?print=1）
 *   - DBの初期化（記録のみ／全部）
 *
 * ID/PW は lib/config.php の MANAGE_USER / MANAGE_PASS（年次設定）。
 */
require_once __DIR__ . '/lib/db.php';

$u = $_SERVER['PHP_AUTH_USER'] ?? '';
$p = $_SERVER['PHP_AUTH_PW'] ?? '';
if (!hash_equals(MANAGE_USER, $u) || !password_verify($p, MANAGE_PASS_HASH)) {
    header('WWW-Authenticate: Basic realm="stamp manage"');
    http_response_code(401);
    exit('認証が必要です');
}

$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'event') {
        $st = db()->prepare('INSERT INTO setting (k, v) VALUES (?, ?)
                             ON CONFLICT(k) DO UPDATE SET v = excluded.v');
        $st->execute(['ev_title', trim((string)($_POST['ev_title'] ?? ''))]);
        $st->execute(['ev_date',  trim((string)($_POST['ev_date'] ?? ''))]);
        $msg = '大会名・開催日を保存しました';
    } elseif ($act === 'need') {
        $n1 = max(1, (int)($_POST['need1'] ?? 1));
        $n2 = max(0, (int)($_POST['need2'] ?? 0));
        $st = db()->prepare('INSERT INTO setting (k, v) VALUES (?, ?)
                             ON CONFLICT(k) DO UPDATE SET v = excluded.v');
        $st->execute(['need1', $n1]);
        $st->execute(['need2', $n2]);
        $msg = "抽選条件を保存しました（1回目 {$n1}社／2回目 " . ($n2 ?: '全') . '社）';
    } elseif ($act === 'adminkey' && ($_POST['sure'] ?? '') === 'yes') {
        admin_key_rotate();
        $msg = '受付の鍵を再発行しました（古いURL・QRは無効になります）';
    } elseif ($act === 'addkeys') {
        $n = min(50, max(1, (int)($_POST['n'] ?? 1)));
        $A = PASS_ALPH;
        $st = db()->prepare('INSERT OR IGNORE INTO ekey (k, ts) VALUES (?, ?)');
        for ($i = 0; $i < $n; $i++) {
            $k = 'EN';
            for ($j = 0; $j < 10; $j++) $k .= $A[random_int(0, 31)];
            $st->execute([$k, now_()]);
        }
        $msg = "登録キーを {$n} 件発行しました";
    } elseif ($act === 'reset_rec' && ($_POST['sure'] ?? '') === 'yes') {
        db()->exec('DELETE FROM scan'); db()->exec('DELETE FROM draw');
        db()->exec('DELETE FROM pass');
        $msg = '記録（スタンプ・抽選・参加登録）を初期化しました';
    } elseif ($act === 'reset_all' && ($_POST['sure'] ?? '') === 'yes') {
        foreach (['scan', 'draw', 'pass', 'booth', 'ekey', 'setting'] as $t)
            db()->exec("DELETE FROM $t");
        $msg = 'すべて初期化しました（企業・登録キー・設定も消去）';
    }
    // 二重送信（リロードでの再POST）を防ぐため GET へ戻す
    header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?m=' . urlencode($msg));
    exit;
}
$msg = isset($_GET['m']) ? (string)$_GET['m'] : '';

// 集計
$keys = [];
foreach (db()->query('SELECT e.k, e.ts, b.name FROM ekey e
                      LEFT JOIN booth b ON b.enroll_key = e.k ORDER BY e.ts, e.k') as $r)
    $keys[] = $r;
$unused = array_filter($keys, function ($k) { return $k['name'] === null; });
$cnt = [];
foreach (['scan', 'draw', 'pass', 'booth'] as $t)
    $cnt[$t] = (int)db()->query("SELECT COUNT(*) FROM $t")->fetchColumn();

// --- 印刷ビュー（print=1: 企業登録用紙 / print=admin: 受付スタッフ用紙） ---
if (isset($_GET['print'])) {
    $print_admin = ($_GET['print'] === 'admin');
    ?><!doctype html><html lang="ja"><head><meta charset="utf-8">
<title>企業ブース登録用紙</title>
<style>
@page { size: A4 portrait; margin: 0; }
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:"Hiragino Sans",sans-serif; }
.slip { width:210mm; height:148.5mm; padding:14mm 16mm; border-bottom:0.2mm dashed #aaa; }
.slip:nth-child(2n) { page-break-after:always; }
h1 { font-size:14pt; margin-bottom:1mm; }
.ev { font-size:9pt; color:#555; margin-bottom:4mm; }
ol { margin:0 0 4mm 5mm; font-size:10.5pt; line-height:1.8; }
.row { display:flex; align-items:center; gap:10mm; }
.key { border:0.5mm solid #333; border-radius:2mm; padding:4mm 8mm; font-size:11pt; }
.key b { font-family:ui-monospace,monospace; font-size:16pt; letter-spacing:0.08em; }
.qr svg { width:30mm; height:30mm; }
.n { font-size:8.5pt; color:#666; margin-top:4mm; }
</style></head><body>
<?php
$base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
      . dirname($_SERVER['SCRIPT_NAME']);
if ($print_admin):
    $rurl = $base . '/register.php?admin=' . rawurlencode(admin_key());
    $aurl = $base . '/admin.php?admin=' . rawurlencode(admin_key());
    $copies = min(10, max(1, (int)($_GET['n'] ?? 4)));
    for ($i = 0; $i < $copies; $i++): ?>
<div class="slip">
<h1>スタンプラリー運営　スタッフ用（取扱注意）</h1>
<p class="ev"><?= $h(event_title()) ?>　<?= $h(event_date()) ?></p>
<p style="font-size:10pt;margin-bottom:3mm">担当の画面のQRを端末で読み、<b>ブックマーク</b>してください。</p>
<div class="row" style="gap:16mm">
<div style="text-align:center">
  <div class="qr" data-u="<?= $h($rurl) ?>"></div>
  <b style="font-size:10.5pt">① 参加登録（受付係）</b><br>
  <span style="font-size:8.5pt;color:#555">名札QRを読むと自動で登録。<br>ボタン操作なし</span>
</div>
<div style="text-align:center">
  <div class="qr" data-u="<?= $h($aurl) ?>"></div>
  <b style="font-size:10.5pt">② 照会・抽選（抽選台）</b><br>
  <span style="font-size:8.5pt;color:#555">名札QRを読む→抽選の実施・<br>参加賞の受渡しを記録</span>
</div>
</div>
<p class="n">※QRには運営の鍵が入っています。<b>掲示したり机に置いたままにしないでください</b>
（読まれると参加者が自分で抽選済みにできてしまいます）。紛失時は管理コンソールから鍵を再発行してください。</p>
</div>
<?php endfor; else:
$eurl = $base . '/enroll.php';
foreach ($unused as $k): ?>
<div class="slip">
<h1>企業展示スタンプラリー　ブース登録のご案内</h1>
<p class="ev"><?= $h(event_title()) ?>　<?= $h(event_date()) ?></p>
<ol>
<li>ブースでお使いになる端末（スマートフォン等）で右のQRを読み、登録ページを開きます</li>
<li><b>社名</b>と下の<b>登録キー</b>を入力して「登録する」を押します</li>
<li>表示されたQR（読み取り画面）を<b>ブックマーク</b>してください。当日はその画面で
学生の名札QRを読み、スタンプを押します</li>
</ol>
<div class="row">
<div class="key">登録キー<br><b><?= $h($k['k']) ?></b></div>
<div class="qr" data-u="<?= $h($eurl) ?>"></div>
</div>
<p class="n">※同じキーでもう一度開くと、発行済みの画面を再表示できます。
お困りの際は総合受付までお知らせください。</p>
</div>
<?php endforeach; endif; ?>
<script src="qrgen.js"></script>
<script>
document.querySelectorAll('.qr').forEach(function (e) {
  var q = qrcode(0, 'M'); q.addData(e.dataset.u); q.make();
  e.innerHTML = q.createSvgTag({ cellSize: 2, margin: 0 });
});
</script></body></html><?php
    exit;
}
?><!doctype html>
<html lang="ja">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>スタンプラリー 管理コンソール</title>
<style>
 body{font-family:"Hiragino Sans",sans-serif;padding:18px;line-height:1.7;max-width:640px;margin:0 auto}
 h1{font-size:17px;color:#1f4e9c;margin-bottom:8px}
 h2{font-size:14px;margin:20px 0 6px;border-left:4px solid #1f4e9c;padding-left:8px}
 .done{background:#e8f5e9;border:1px solid #81c784;padding:10px;border-radius:8px;margin:10px 0}
 .st{color:#555;font-size:13px}
 input[type=number]{font:inherit;padding:6px;width:5em;border:1px solid #ccc;border-radius:6px}
 button{font:inherit;padding:8px 14px;border-radius:8px;border:0;background:#1f4e9c;color:#fff;font-weight:700}
 button.warn{background:#b71c1c}
 table{border-collapse:collapse;font-size:13px;margin-top:6px}
 td,th{border:1px solid #ddd;padding:3px 8px;text-align:left}
 .mono{font-family:ui-monospace,monospace}
 label{margin-right:10px}
 form{margin:6px 0}
</style>
<h1>企業展示スタンプラリー　管理コンソール</h1>
<p class="st">記録：スタンプ <?= $cnt['scan'] ?>件／抽選 <?= $cnt['draw'] ?>件／
参加登録 <?= $cnt['pass'] ?>名／企業 <?= $cnt['booth'] ?>社</p>
<?php if ($msg): ?><div class="done"><?= $h($msg) ?></div><?php endif; ?>

<h2>大会名・開催日（登録用紙に印字されます）</h2>
<form method="post">
  <input type="text" name="ev_title" value="<?= $h(event_title()) ?>"
         style="width:100%;font:inherit;padding:6px;border:1px solid #ccc;border-radius:6px;margin-bottom:4px">
  <input type="text" name="ev_date" value="<?= $h(event_date()) ?>"
         style="width:14em;font:inherit;padding:6px;border:1px solid #ccc;border-radius:6px">
  <button name="act" value="event">保存</button>
</form>

<h2>抽選条件</h2>
<form method="post">
  <label>1回目 <input type="number" name="need1" min="1" value="<?= need1() ?>"> 社</label>
  <label>2回目 <input type="number" name="need2" min="0"
         value="<?= setting('need2', NEED2) ?>"> 社（0=全社）</label>
  <button name="act" value="need">保存</button>
</form>

<h2>企業登録キー（<?= count($keys) ?>件・未使用 <?= count($unused) ?>件）</h2>
<form method="post">
  <input type="number" name="n" min="1" max="50" value="5"> 件
  <button name="act" value="addkeys">追加発行</button>
  <a href="?print=1" target="_blank" style="margin-left:10px">未使用キーの登録用紙を印刷</a>
</form>
<table><tr><th>キー</th><th>発行</th><th>使用</th></tr>
<?php foreach ($keys as $k): ?>
<tr><td class="mono"><?= $h($k['k']) ?></td><td><?= $h(substr($k['ts'], 0, 10)) ?></td>
<td><?= $k['name'] !== null ? $h($k['name']) : '—' ?></td></tr>
<?php endforeach; ?></table>

<h2>初期化</h2>
<form method="post" onsubmit="return confirm('記録（スタンプ・抽選・参加登録）を消します。よろしいですか？')">
  <input type="hidden" name="sure" value="yes">
  <button class="warn" name="act" value="reset_rec">記録を初期化（企業・キーは残す）</button>
</form>
<form method="post" onsubmit="return confirm('企業・登録キー・設定も含めて全部消します。本当によろしいですか？')">
  <input type="hidden" name="sure" value="yes">
  <button class="warn" name="act" value="reset_all">すべて初期化（年次の切り替え用）</button>
</form>
<h2>受付の鍵（admin.php・export.php 用）</h2>
<?php $aurl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
            . dirname($_SERVER['SCRIPT_NAME']) . '/admin.php?admin=' . rawurlencode(admin_key()); ?>
<p class="st mono"><?= $h(admin_key()) ?></p>
<p class="st">参加登録（受付係） <a href="register.php?admin=<?= $h(admin_key()) ?>">register.php</a>／
照会・抽選（抽選台） <a href="admin.php?admin=<?= $h(admin_key()) ?>">admin.php</a>／
集計 <a href="export.php?admin=<?= $h(admin_key()) ?>">export.php</a>／
<a href="#" onclick="document.getElementById('aqr').hidden=!document.getElementById('aqr').hidden;return false">受付用QRを表示</a>／
<a href="?print=admin" target="_blank">受付スタッフ用紙を印刷</a>（4枚。枚数は ?print=admin&n=6 のように）</p>
<div id="aqr" hidden>
  <div id="aqrimg"></div>
  <p class="st">運営端末でこのQRを読むと受付画面が開く。**鍵入りなので掲示しない**こと</p>
</div>
<form method="post" onsubmit="return confirm('受付の鍵を再発行します。配布済みの受付QR・URLは使えなくなります。よろしいですか？')">
  <input type="hidden" name="sure" value="yes">
  <button class="warn" name="act" value="adminkey">受付の鍵を再発行</button>
</form>
<script src="qrgen.js"></script>
<script>
(function(){ var q=qrcode(0,'M'); q.addData(<?= json_encode($aurl) ?>); q.make();
  document.getElementById('aqrimg').innerHTML=q.createImgTag(4,8); })();
</script>
