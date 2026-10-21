<?php
/** 名札コードの進捗を返す。参加者ページが自分の状況を表示するのに使う。 */
require_once __DIR__ . '/lib/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$code = strtoupper(trim(isset($_GET['n']) ? $_GET['n'] : ''));
if (!preg_match('/^[2-9A-HJ-NP-Z]{6}$/', $code)) {
    echo json_encode(['ok' => false]); exit;
}
try {
$names = booth_names();
if (!pass_ok($code)) { echo json_encode(['ok' => false, 'unknown' => true, 'event' => event_title(), 'date' => event_date()], JSON_UNESCAPED_UNICODE); exit; }
if (!pass_registered($code)) { echo json_encode(['ok' => false, 'unregistered' => true, 'event' => event_title(), 'date' => event_date()], JSON_UNESCAPED_UNICODE); exit; }

$got = got_of($code);
$d   = draw_of($code);
echo json_encode([
    'ok'    => true,
    'event' => event_title(),
    'date'  => event_date(),
    'code'  => $code,
    'got'   => array_values($got),
    'all'   => count($names),
    'needs' => needs(),
    'draw'  => $d['count'],
    'booths' => $names,
], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
