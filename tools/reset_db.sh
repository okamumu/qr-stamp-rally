#!/bin/sh
# スタンプラリーのデータをリセットする（さくら本番）
#   ./reset_db.sh           件数を表示するだけ
#   ./reset_db.sh --backup  手元へ控えを取ってからリセット
#   ./reset_db.sh --go      リセットする
#
# さくらのログインシェルは csh なので、`ssh host 'sh -s' < スクリプト` で流し込む。
# 引用符を重ねて渡そうとすると必ず壊れる。
HOST=snowwolf94@www3796.sakura.ne.jp

stat_remote() {
ssh -o BatchMode=yes "$HOST" 'sh -s' <<'SH'
P="$HOME/rentai_stamp/stamp.sqlite"
if [ ! -f "$P" ]; then echo "DBなし（まっさらです）"; exit 0; fi
php -r '
$p = getenv("HOME")."/rentai_stamp/stamp.sqlite";
$d = new PDO("sqlite:$p");
printf("スキャン: %d件\n", $d->query("SELECT COUNT(*) FROM scan")->fetchColumn());
printf("抽選:     %d件\n", $d->query("SELECT COUNT(*) FROM draw")->fetchColumn());
printf("参加者:   %d名\n", $d->query("SELECT COUNT(DISTINCT code) FROM scan")->fetchColumn());
'
SH
}

wipe_remote() {
ssh -o BatchMode=yes "$HOST" 'sh -s' <<'SH'
rm -f "$HOME"/rentai_stamp/stamp.sqlite*
mkdir -p "$HOME/rentai_stamp"; chmod 700 "$HOME/rentai_stamp"
printf 'Deny from all\n' > "$HOME/rentai_stamp/.htaccess"
echo "リセットしました（次のアクセスで作り直されます）"
SH
}

case "$1" in
  --backup)
    B="backup_$(date +%Y%m%d_%H%M%S).sqlite"
    scp -q "$HOST:rentai_stamp/stamp.sqlite" "$B" && echo "控え: $B"
    wipe_remote ;;
  --go)
    wipe_remote ;;
  *)
    echo "現在の件数（--go でリセット／--backup で控えを取ってからリセット）"
    stat_remote ;;
esac
