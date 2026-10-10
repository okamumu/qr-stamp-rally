<?php
require_once __DIR__ . '/config.php';

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . db_path());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // 同時書き込みに強くする
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS scan (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts TEXT NOT NULL, code TEXT NOT NULL, booth TEXT NOT NULL, note TEXT DEFAULT "")');
    $pdo->exec('CREATE TABLE IF NOT EXISTS draw (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts TEXT NOT NULL, code TEXT NOT NULL, n INTEGER NOT NULL, gift TEXT DEFAULT "")');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_scan_code ON scan(code)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS ix_draw_code ON draw(code)');
    // 参加登録（受付で登録した名札だけがラリーに参加できる）
    $pdo->exec('CREATE TABLE IF NOT EXISTS pass (
        code TEXT PRIMARY KEY, ts TEXT NOT NULL, note TEXT DEFAULT "")');
    // 企業ブース（enroll.php が登録する。初回は lib/booths.php から取り込む）
    $pdo->exec('CREATE TABLE IF NOT EXISTS booth (
        token TEXT PRIMARY KEY, name TEXT NOT NULL, key TEXT NOT NULL,
        enroll_key TEXT DEFAULT "", ts TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS setting (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
    // 企業登録キー。発行は manage.php だけ（ファイルからのseedはしない＝
    // 年次切替の「すべて初期化」後に旧年のキーが復活しないように）
    $pdo->exec('CREATE TABLE IF NOT EXISTS ekey (k TEXT PRIMARY KEY, ts TEXT NOT NULL)');
    if ((int)$pdo->query('SELECT COUNT(*) FROM booth')->fetchColumn() === 0
        && is_file(__DIR__ . '/booths.php')) {
        $seed = require __DIR__ . '/booths.php';
        $st = $pdo->prepare('INSERT INTO booth (token,name,key,enroll_key,ts) VALUES (?,?,?,?,?)');
        foreach ($seed as $t => $v)
            $st->execute([$t, is_array($v) ? $v['name'] : $v,
                          is_array($v) ? $v['key'] : '', 'seed', now_()]);
    }
    return $pdo;
}

function now_() { return date('Y-m-d H:i:s'); }

/** その参加証がこれまでに取得した企業トークン */
function got_of($code) {
    $q = db()->prepare('SELECT DISTINCT booth FROM scan WHERE code = ? AND booth <> "start"');
    $q->execute([$code]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

/** 抽選の回数と参加賞の受取 */
function draw_of($code) {
    $q = db()->prepare('SELECT COUNT(*) c, MAX(CASE WHEN gift <> "" THEN 1 ELSE 0 END) g FROM draw WHERE code = ?');
    $q->execute([$code]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return ['count' => (int)$r['c'], 'gift' => (bool)$r['g']];
}

/** 参加登録済みか（受付で登録した名札だけがラリーに参加できる） */
function pass_registered($code) {
    $q = db()->prepare('SELECT 1 FROM pass WHERE code = ?');
    $q->execute([$code]);
    return (bool)$q->fetchColumn();
}

function pass_register($code) {
    db()->prepare('INSERT OR IGNORE INTO pass (code, ts) VALUES (?, ?)')
        ->execute([$code, now_()]);
}
