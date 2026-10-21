<?php
/**
 * 管理コンソール（Basic認証・2026-10-10新設）
 *
 * できること:
 *   - 特典の条件（段数と必要ブース数）の変更（DBに保存。config の NEEDS は既定値）
 *   - 出展者登録キーの追加発行と一覧（使用状況つき）
 *   - 未使用キーの登録用紙の印刷ビュー（?print=1）
 *   - 名札QRの発行（?print=badges。採番して印刷するだけで、サーバは控えを持たない）
 *   - DBの控えのダウンロード（?backup=1）
 *   - DBの初期化（記録のみ／全部）
 *
 * ID/PW は lib/secret.php の MANAGE_USER / MANAGE_PASS_HASH（secret.example.php から作る。gitに入れない）。
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

// --- DBの控えのダウンロード（初期化の前に取っておく） ---
if (isset($_GET['backup'])) {
    $tmp = dirname(db_path()) . '/backup_' . date('Ymd_His') . '.sqlite';
    // WAL の書きかけを含まない一貫した控えを取る
    db()->exec('VACUUM INTO ' . db()->quote($tmp));
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($tmp) . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'event') {
        $st = db()->prepare('INSERT INTO setting (k, v) VALUES (?, ?)
                             ON CONFLICT(k) DO UPDATE SET v = excluded.v');
        $st->execute(['ev_title', trim((string)($_POST['ev_title'] ?? ''))]);
        $st->execute(['ev_date',  trim((string)($_POST['ev_date'] ?? ''))]);
        $msg = 'イベント名・開催日を保存しました';
    } elseif ($act === 'need') {
        $r = [];
        foreach (explode(',', (string)($_POST['needs'] ?? '')) as $x) {
            $x = (int)trim($x);
            if ($x > 0) $r[] = $x;
        }
        sort($r);
        if (!$r) {
            $msg = '必要ブース数を1つ以上入れてください（例 4,8）';
        } else {
            db()->prepare('INSERT INTO setting (k, v) VALUES (?, ?)
                           ON CONFLICT(k) DO UPDATE SET v = excluded.v')
                ->execute(['needs', implode(',', $r)]);
            $p = [];
            foreach ($r as $i => $x) $p[] = '特典' . ($i + 1) . '＝' . $x . 'ブース';
            $msg = '特典の条件を保存しました（' . implode('、', $p) . '）';
        }
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
        $msg = '記録（スタンプ・特典・参加登録）を初期化しました';
    } elseif ($act === 'reset_all' && ($_POST['sure'] ?? '') === 'yes') {
        foreach (['scan', 'draw', 'pass', 'booth', 'ekey', 'setting'] as $t)
            db()->exec("DELETE FROM $t");
        $msg = 'すべて初期化しました（ブース・登録キー・設定も消去）';
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

// --- 印刷ビュー（print=1: 出展者登録用紙 / print=admin: 受付スタッフ用紙） ---
if (isset($_GET['print'])) {
    $print_admin  = ($_GET['print'] === 'admin');
    $print_badges = ($_GET['print'] === 'badges');
    ?><!doctype html><html lang="ja"><head><meta charset="utf-8">
<title><?= $print_badges ? '名札' : 'ブース登録用紙' ?></title>
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
/* 名札（91mm×55mm・A4に2列×5段） */
.badges { display:grid; grid-template-columns:91mm 91mm; width:182mm; margin:11mm auto; }
.badge { width:91mm; height:55mm; border:0.2mm dashed #aaa; padding:4mm 5mm;
         display:flex; align-items:center; gap:4mm; page-break-inside:avoid; }
.badge .qr svg { width:24mm; height:24mm; }
.badge .ev2 { font-size:7.5pt; color:#555; margin-bottom:1mm; }
.badge .nm { border-bottom:0.3mm solid #999; height:11mm; margin:2mm 0 2mm; }
.badge .cd { font-family:ui-monospace,monospace; font-size:10pt; letter-spacing:0.1em; }
</style></head><body>
<?php
$base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
      . dirname($_SERVER['SCRIPT_NAME']);
if ($print_badges):
    $n = min(100, max(1, (int)($_GET['n'] ?? 10)));
    $codes = [];
    while (count($codes) < $n) $codes[pass_new()] = 1;   // 念のため重複を除く
    $codes = array_keys($codes);
    ?>
<div class="badges">
<?php foreach ($codes as $c): ?>
<div class="badge">
  <div class="qr" data-u="<?= $h($base . '/?n=' . $c) ?>"></div>
  <div style="flex:1">
    <div class="ev2"><?= $h(event_title()) ?>　<?= $h(event_date()) ?></div>
    <div class="nm"></div>
    <div class="cd"><?= $h($c) ?></div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php elseif ($print_admin):
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
  <b style="font-size:10.5pt">② 特典窓口（照会・受け渡し）</b><br>
  <span style="font-size:8.5pt;color:#555">名札QRを読む→<br>特典の受渡しを記録</span>
</div>
</div>
<p class="n">※QRには運営の鍵が入っています。<b>掲示したり机に置いたままにしないでください</b>
（読まれると参加者が自分で特典を受け取り済みにできてしまいます）。紛失時は管理コンソールから鍵を再発行してください。</p>
</div>
<?php endfor; else:
$eurl = $base . '/enroll.php';
foreach ($unused as $k): ?>
<div class="slip">
<h1>スタンプラリー　ブース登録のご案内</h1>
<p class="ev"><?= $h(event_title()) ?>　<?= $h(event_date()) ?></p>
<ol>
<li>ブースでお使いになる端末（スマートフォン等）で右のQRを読み、登録ページを開きます</li>
<li><b>出展者名</b>と下の<b>登録キー</b>を入力して「登録する」を押します</li>
<li>表示されたQR（読み取り画面）を<b>ブックマーク</b>してください。当日はその画面で
参加者の名札QRを読み、スタンプを押します</li>
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
<h1>スタンプラリー　管理コンソール</h1>
<p class="st">記録：スタンプ <?= $cnt['scan'] ?>件／特典 <?= $cnt['draw'] ?>件／
参加登録 <?= $cnt['pass'] ?>名／ブース <?= $cnt['booth'] ?>件</p>
<?php if ($msg): ?><div class="done"><?= $h($msg) ?></div><?php endif; ?>

<h2>イベント名・開催日（登録用紙に印字されます）</h2>
<form method="post">
  <input type="text" name="ev_title" value="<?= $h(event_title()) ?>"
         style="width:100%;font:inherit;padding:6px;border:1px solid #ccc;border-radius:6px;margin-bottom:4px">
  <input type="text" name="ev_date" value="<?= $h(event_date()) ?>"
         style="width:14em;font:inherit;padding:6px;border:1px solid #ccc;border-radius:6px">
  <button name="act" value="event">保存</button>
</form>

<h2>特典の条件</h2>
<form method="post">
  <label>必要ブース数（カンマ区切りで特典1から順に。1つなら特典1だけ。例 4,8）
    <input name="needs" value="<?= $h(implode(',', needs())) ?>"></label>
  <button name="act" value="need">保存</button>
</form>

<h2>出展者登録キー（<?= count($keys) ?>件・未使用 <?= count($unused) ?>件）</h2>
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

<h2>名札QRの発行</h2>
<form method="get" target="_blank">
  <input type="hidden" name="print" value="badges">
  <input type="number" name="n" min="1" max="100" value="10"> 枚
  <button>名札を印刷</button>
</form>
<p class="st">コードはその場で採番し、サーバは控えを持ちません（開き直すたびに別のコードになります）。
名札の形式が正しいかの検証だけを行うので、追加発行はいつでもできます。
ラリーへの参加には受付での参加登録が別に必要です。</p>

<h2>バックアップ</h2>
<p class="st"><a href="?backup=1">DBの控えをダウンロード</a>（初期化や年次の切り替えの前に取っておく）</p>

<h2>初期化</h2>
<form method="post" onsubmit="return confirm('記録（スタンプ・特典・参加登録）を消します。よろしいですか？')">
  <input type="hidden" name="sure" value="yes">
  <button class="warn" name="act" value="reset_rec">記録を初期化（ブース・キーは残す）</button>
</form>
<form method="post" onsubmit="return confirm('ブース・登録キー・設定も含めて全部消します。本当によろしいですか？')">
  <input type="hidden" name="sure" value="yes">
  <button class="warn" name="act" value="reset_all">すべて初期化（年次の切り替え用）</button>
</form>
<h2>受付の鍵（admin.php・export.php 用）</h2>
<?php $aurl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
            . dirname($_SERVER['SCRIPT_NAME']) . '/admin.php?admin=' . rawurlencode(admin_key()); ?>
<p class="st mono"><?= $h(admin_key()) ?></p>
<p class="st">参加登録（受付係） <a href="register.php?admin=<?= $h(admin_key()) ?>">register.php</a>／
特典窓口（照会・受け渡し） <a href="admin.php?admin=<?= $h(admin_key()) ?>">admin.php</a>／
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
