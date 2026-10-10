<?php
/** 学生ページからの記録受け。同一オリジンなので CORS は不要。 */
require_once __DIR__ . '/lib/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$code  = strtoupper(trim(isset($_REQUEST['n']) ? $_REQUEST['n'] : ''));
$booth = strtoupper(trim(isset($_REQUEST['b']) ? $_REQUEST['b'] : ''));

if ($code === '' || !preg_match('/^[2-9A-HJ-NP-Z]{6}$/', $code)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad code']); exit;
}

$known  = pass_ok($code);
$boo    = booth_names();
$note   = !$known ? '無効コード' : (pass_registered($code) ? '' : '未登録');
if ($booth !== 'START' && $booth !== '' && !isset($boo[$booth])) $note = trim($note . ' 不明トークン');

try {
    $st = db()->prepare('INSERT INTO scan (ts, code, booth, note) VALUES (?, ?, ?, ?)');
    $st->execute([now_(), $code, $booth === '' ? 'start' : strtolower($booth) === 'start' ? 'start' : $booth, $note]);
    echo json_encode(['ok' => true, 'got' => count(got_of($code))]);
} catch (Exception $e) {
    // 記録に失敗しても学生の画面は止めない
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
}
