<?php
/**
 * スタンプラリー — 共通設定
 *
 * データ（SQLite）は必ず公開領域（ドキュメントルート）の外に置くこと。
 * 置き場所は環境変数 STAMP_DB_DIR で差し替えられる。
 */


// ===== 年次設定（年度ごとに見直すのはここだけ）=====
// 特典の段ごとの必要ブース数の既定値（昇順。manage.php でDBに上書き保存できる）
const NEEDS = [4, 8];
// 管理コンソール（manage.php）の Basic 認証は lib/secret.php（gitに入れない）。
// 無ければ secret.example.php をコピーして作る
require __DIR__ . '/secret.php';
// イベント名・開催日の既定値（manage.php でDBに上書き保存する。登録用紙などに出る）
const EVENT_TITLE = '（イベント名を manage.php で設定してください）';
const EVENT_DATE  = '';

// 鍵がクエリに入るページがあるので、Referer 経由の漏えいを防ぐ（全ページ共通）
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Referrer-Policy: same-origin');
    header('X-Content-Type-Options: nosniff');
}

/** HTTPS か（リバースプロキシ配下の X-Forwarded-Proto も見る） */
function is_https() {
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off') return true;
    return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function setting($k, $default) {
    $q = db()->prepare('SELECT v FROM setting WHERE k = ?');
    $q->execute([$k]);
    $v = $q->fetchColumn();
    return $v === false ? $default : (int)$v;
}

function setting_s($k, $default) {
    $q = db()->prepare('SELECT v FROM setting WHERE k = ?');
    $q->execute([$k]);
    $v = $q->fetchColumn();
    return ($v === false || $v === '') ? $default : $v;
}

function event_title() { return setting_s('ev_title', EVENT_TITLE); }
function event_date()  { return setting_s('ev_date',  EVENT_DATE); }

/** 受付の鍵（DB保存。無ければ生成する。再発行は manage.php） */
function admin_key() {
    $k = setting_s('admin_key', '');
    if ($k === '') $k = admin_key_rotate();
    return $k;
}

function admin_key_rotate() {
    $k = '';
    for ($i = 0; $i < 12; $i++) $k .= PASS_ALPH[random_int(0, 31)];
    db()->prepare('INSERT INTO setting (k, v) VALUES (?, ?)
                   ON CONFLICT(k) DO UPDATE SET v = excluded.v')
        ->execute(['admin_key', $k]);
    return $k;
}

/** 特典1…特典N の必要ブース数（昇順の配列。段数も含めて manage.php で設定する） */
function needs() {
    $v = setting_s('needs', '');
    if ($v === '') {
        // 旧形式（need1/need2 の個別設定）からの引き継ぎ
        $n1 = setting('need1', 0);
        $n2 = setting('need2', 0);
        if ($n1 > 0) return $n2 > 0 ? [$n1, $n2] : [$n1];
        return NEEDS;
    }
    $r = [];
    foreach (explode(',', $v) as $x) { $x = (int)trim($x); if ($x > 0) $r[] = $x; }
    sort($r);
    return $r ? $r : NEEDS;
}

/** 特典1の必要ブース数（集計の「達成」の基準） */
function need1() { return needs()[0]; }

/** 出展者登録キーの一覧（DB。発行は manage.php） */
function enroll_keys() {
    $r = [];
    foreach (db()->query('SELECT k FROM ekey ORDER BY ts, k') as $row) $r[] = $row['k'];
    return $r;
}

/**
 * SQLite の置き場所。
 *
 * 既定は「このファイルの4つ上の qr_stamp_rally/」（さくらのレンタルサーバで
 * ~/www/<ドメイン>/stamp/lib/ に置いた場合にホーム直下＝公開領域の外になる深さ）。
 * 他の環境では環境変数 STAMP_DB_DIR で明示するのが確実。
 */
function db_path() {
    $dir = getenv('STAMP_DB_DIR');            // テスト時に差し替えられるように
    if (!$dir) $dir = dirname(__DIR__, 4) . '/qr_stamp_rally';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        // 万一この場所が公開領域に入ってしまっても読まれないように
        @file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
    return $dir . '/stamp.sqlite';
}

/** 出展者。トークン => ['name'=>出展者名, 'key'=>鍵]。
 *  DBの booth テーブルから読む（登録は enroll.php。初回は lib/booths.php を取り込む）。
 *  **ブース用QRは booth.php への鍵つきURL**なので、トークンと鍵は変えないこと。 */
function booths() {
    static $b = null;
    if ($b === null) {
        $b = [];
        foreach (db()->query('SELECT token, name, key FROM booth ORDER BY ts') as $r)
            $b[$r['token']] = ['name' => $r['name'], 'key' => $r['key']];
    }
    return $b;
}

/** トークン => 出展者名 だけの配列（表示・集計用） */
function booth_names() {
    $r = [];
    foreach (booths() as $t => $v) $r[$t] = is_array($v) ? $v['name'] : $v;
    return $r;
}

/** ブースの鍵が合っているか */
function booth_ok($token, $key) {
    $b = booths();
    return isset($b[$token]) && is_array($b[$token])
        && is_string($key) && hash_equals($b[$token]['key'], $key);
}

/**
 * 参加証コードの検証（チェックサム方式・2026-10-10変更）。
 *
 * コードは6文字＝先頭5文字（紛らわしい文字を除いた32文字）＋検査文字1桁。
 * 検査文字 = ALPH[ Σ_{i=0..4} (i+1)×idx(c_i) mod 32 ]。
 * 発行は名簿側（次期システム/make_meibo.py）が行い、サーバは事前登録を持たない。
 * 形式が正しければ有効な参加証として扱う（未知でもその場で参加が始まる）。
 */
const PASS_ALPH = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

function pass_ok($code) {
    if (!is_string($code) || !preg_match('/^[2-9A-HJ-NP-Z]{6}$/', $code)) return false;
    $s = 0;
    for ($i = 0; $i < 5; $i++) $s += ($i + 1) * strpos(PASS_ALPH, $code[$i]);
    return PASS_ALPH[$s % 32] === $code[5];
}

/** 名札コードの発行（pass_ok() を満たす6文字を採番する。控えは持たない） */
function pass_new() {
    $c = '';
    for ($i = 0; $i < 5; $i++) $c .= PASS_ALPH[random_int(0, 31)];
    $s = 0;
    for ($i = 0; $i < 5; $i++) $s += ($i + 1) * strpos(PASS_ALPH, $c[$i]);
    return $c . PASS_ALPH[$s % 32];
}

function admin_ok() {
    $k = isset($_REQUEST['admin']) ? $_REQUEST['admin'] : '';
    return is_string($k) && hash_equals(admin_key(), $k);
}
