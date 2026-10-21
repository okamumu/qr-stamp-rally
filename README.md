# qr-stamp-rally

イベント展示のスタンプラリー（名札QR方式）。PHP ＋ SQLite で、フレームワークやビルドは使わず、
レンタルサーバにファイルを置くだけで動くことを優先した作り。

## しくみ

- **参加者は QR 入りの名札を下げているだけ**。スマートフォンも持たなくてよく、
  端末には何も保存しない（進捗はすべてサーバで管理）
- **出展者がブースで参加者の名札QRを読んで**スタンプを押す（`booth.php`）。
  ブースは配布した登録キーでセルフ登録（`enroll.php`）
- **受付が名札QRを読んで参加登録**（`register.php`）。登録済みの名札だけがラリーに参加でき、
  必要ブース数に達したら特典窓口（`prize.php`）で特典を渡す
- 参加者は自分の名札QRを読むと進捗ページ（`index.html`）で集まり具合を確認できる
- 設定・キー発行・名札印刷・集計・バックアップは管理コンソール（`manage.php`、Basic 認証）から

## ドキュメント

| | |
|---|---|
| [ユーザーマニュアル](docs/user-manual.md) | 主催者・出展者・受付スタッフ・参加者の立場別の使い方 |
| [開発者マニュアル](docs/developer-manual.md) | 構成・DBスキーマ・鍵の設計・約束ごと・デプロイ |

## ローカルで動かす

```sh
# manage.php の Basic 認証を用意する
cp web/lib/secret.example.php web/lib/secret.php
php -r "echo password_hash('あなたのパスワード', PASSWORD_BCRYPT);"  # 出力を secret.php に貼る

docker build -t qr-stamp-rally .
docker run -d -p 8080:80 \
  -v "$PWD/web/lib/secret.php:/var/www/html/lib/secret.php:ro" \
  -v stamp-data:/data qr-stamp-rally
```

http://localhost:8080/manage.php を開き、イベント名と特典の条件を設定して、
登録キーと名札を発行すれば一巡できる。カメラがなくても
`booth.php?b=トークン&key=鍵&scan=1&n=コード` を直接叩けば読み取りを試せる。

Docker イメージは PHP 8.2 だが、コードは PHP 7.4 でも動く書き方（レンタルサーバ向け）。

## テスト

```sh
node tests/test_pick.js   # QR文字列から名札コードを抽出する pick() の検証
node tests/test_page.js   # 参加者ページの表示分岐の検証（fetch をモック）
```

テストランナーは使わず、各ファイルが素の Node スクリプト（NG があれば exit 1）。

## 設計上の要点

- SQLite は公開領域の外に置く（置き場所は環境変数 `STAMP_DB_DIR`）
- 名札コードは6文字（5文字＋チェックサム1桁）。サーバは発行済みの控えを持たず、
  形式の検証と受付での参加登録だけで運用する
- 秘密としてコードに残るのは `web/lib/secret.php`（git 管理外）だけ。
  受付・ブースの鍵は DB に置き、照合はすべて `hash_equals()`
- 当日に使う画面の API は、例外時も必ず JSON を返す

詳しくは [開発者マニュアル](docs/developer-manual.md) を参照。
