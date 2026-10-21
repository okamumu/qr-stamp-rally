<?php
/** 集計の表示とCSV書き出し。大会後の出展企業への報告に使う。 */
require_once __DIR__ . '/lib/db.php';

if (!admin_ok()) { http_response_code(403); echo '鍵が違います'; exit; }

$boo = booth_names();
$fmt = isset($_GET['csv']) ? $_GET['csv'] : '';

/** 企業ごとの来訪者数（同じ人の重複は数えない） */
$visit = db()->query('SELECT booth, COUNT(DISTINCT code) n FROM scan
                      WHERE booth <> "start" GROUP BY booth')->fetchAll(PDO::FETCH_KEY_PAIR);
$total   = (int)db()->query('SELECT COUNT(DISTINCT code) FROM scan')->fetchColumn();
$reached = (int)db()->query('SELECT COUNT(*) FROM (SELECT code FROM scan WHERE booth <> "start"
                             GROUP BY code HAVING COUNT(DISTINCT booth) >= ' . need1() . ')')->fetchColumn();
$drawn   = (int)db()->query('SELECT COUNT(*) FROM draw')->fetchColumn();
$gift    = (int)db()->query('SELECT COUNT(*) FROM draw WHERE gift <> ""')->fetchColumn();

if ($fmt === 'booth' || $fmt === 'scan' || $fmt === 'draw') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stamp_' . $fmt . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                       // Excel で開けるように BOM
    if ($fmt === 'booth') {
        fputcsv($out, ['区画', '企業名', 'トークン', '来訪者数']);
        $i = 0;
        foreach ($boo as $t => $name) {
            fputcsv($out, [++$i, $name, $t, isset($visit[$t]) ? $visit[$t] : 0]);
        }
    } else {
        $rows = db()->query('SELECT * FROM ' . $fmt . ' ORDER BY id');
        $head = false;
        foreach ($rows as $r) {
            $r = array_filter($r, 'is_string', ARRAY_FILTER_USE_KEY);
            if (!$head) { fputcsv($out, array_keys($r)); $head = true; }
            fputcsv($out, array_values($r));
        }
    }
    exit;
}

$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$k = urlencode(admin_key());
?><!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>スタンプラリー 集計</title>
<style>
 body{font-family:"Hiragino Sans",sans-serif;padding:18px;line-height:1.7;max-width:640px;margin:0 auto}
 table{border-collapse:collapse;width:100%;margin:12px 0}
 th,td{border:1px solid #ddd;padding:7px 9px;font-size:15px}
 th{background:#f2f4f7;text-align:left} td.n{text-align:right;font-variant-numeric:tabular-nums}
 .sum{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0}
 .sum div{flex:1 1 120px;border:1px solid #ddd;border-radius:8px;padding:10px;text-align:center}
 .sum b{display:block;font-size:26px;color:#1f4e9c}
</style>
<h2>企業展示スタンプラリー　集計</h2>
<div class="sum">
  <div><b><?= $total ?></b>参加者</div>
  <div><b><?= $reached ?></b><?= need1() ?>社達成</div>
  <div><b><?= $drawn ?></b>抽選</div>
  <div><b><?= $gift ?></b>参加賞</div>
</div>
<table>
  <tr><th>区画</th><th>企業名</th><th>来訪者数</th></tr>
  <?php $i = 0; foreach ($boo as $t => $name): ?>
  <tr><td><?= ++$i ?></td><td><?= $h($name) ?></td>
      <td class="n"><?= isset($visit[$t]) ? $visit[$t] : 0 ?></td></tr>
  <?php endforeach; ?>
</table>
<p>CSV：
 <a href="?admin=<?= $k ?>&csv=booth">企業別</a>　
 <a href="?admin=<?= $k ?>&csv=scan">スキャン全件</a>　
 <a href="?admin=<?= $k ?>&csv=draw">抽選記録</a></p>
<p><a href="admin.php?admin=<?= $k ?>">受付へ戻る</a></p>
