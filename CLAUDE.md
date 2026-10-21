# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## これは何か

イベント展示のスタンプラリー（名札QR方式）。PHP ＋ SQLite で、フレームワークやビルドは使わない。
コード中のコメント・文言はすべて日本語。作業言語も日本語。

役割分担（コードの前提）:

- **参加者は名札を下げているだけ**（端末には何も保存しない）。自分の名札QRを読めば進捗を確認できる
- **出展者がブースで参加者の名札QRを読む**（`booth.php`）。ブースは登録キーでセルフ登録（`enroll.php`）
- **受付が名札QRを読んで「参加登録」**（`admin.php`）。登録済みの名札だけがラリーに参加でき、
  必要ブース数に達したら特典（抽選）の判定をする

## テスト

```sh
node tests/test_pick.js   # admin.php の pick()（QRの中身から名札コードを抽出）を切り出して検証
node tests/test_page.js   # index.html のスクリプトを fetch モックで回して表示の分岐を確認
```

テストランナーは使わず、各ファイルが素の Node スクリプト（合格数を表示し、NG があれば exit 1）。
`test_pick.js` は PHP ソースから正規表現で関数を切り出し、`test_page.js` は HTML の `<script>` を
切り出して fetch（status.php）をモックして回す——**対象の構造や文言を変えるとテストが壊れる**ので、
変更時はテストもそろえて直す。

## ローカルで動かす

PHP 7.4 を想定した書き方（レンタルサーバ向け）。Dockerfile があるので:

```sh
docker build -t rentai-stamp .
docker run -d -p 8080:80 \
  -v "$PWD/web/lib/secret.php:/var/www/html/lib/secret.php:ro" \
  -v stamp-data:/data rentai-stamp
```

- `web/lib/secret.php` は `secret.example.php` からコピーして作る（manage.php の Basic 認証）。
  イメージには入れず（.dockerignore）実行時にマウントする
- DB の置き場所は環境変数 `STAMP_DB_DIR`（Dockerfile では `/data`。未指定だと `lib/` の4つ上の
  `rentai_stamp/`——さくらのレンタルサーバでホーム直下＝公開領域の外になる深さ）

カメラがなくても `booth.php?b=トークン&key=鍵&scan=1&n=コード` を直接叩けば読み取りを記録できる。
`admin.php?admin=鍵&api=register&n=コード` で参加登録を API からもできる（名簿からの一括登録用）。

## 構成

| パス | 役割 |
|---|---|
| `web/index.html` | 参加者ページ（進捗表示。名札QRの行き先 `?n=コード`。`status.php` を叩く） |
| `web/enroll.php` | ブースのセルフ登録（登録キー＋出展者名 → トークン・鍵と読み取りQRを発行） |
| `web/booth.php` | ブースの読み取り画面（`?b=トークン&key=鍵`。応答は必ず JSON で返す） |
| `web/admin.php` | 受付（参加登録・抽選判定。`?admin=鍵`） |
| `web/register.php` | 参加登録専用の画面 |
| `web/manage.php` | 管理コンソール（Basic 認証。イベント名・特典条件の設定、登録キーと名札QRの発行・印刷、DBのバックアップ・初期化） |
| `web/export.php` | 集計（`?admin=鍵`） |
| `web/status.php` | 進捗 JSON API |
| `web/log.php` | 旧方式（参加者が出展者QRを読む）の受け口。使っていない |
| `web/lib/config.php` | 設定・鍵まわり（`pass_ok()`・`booth_ok()`・`admin_ok()`・`needs()` など） |
| `web/lib/db.php` | DB 接続とスキーマ（`db()` が CREATE TABLE まで行う。マイグレーションは無い） |
| `web/jsQR.js` / `web/qrgen.js` | QR の読み取り／生成（バンドル） |

## 設計上の約束ごと

- **DB（SQLite）は公開領域の外に置く**。`db_path()` は置き場所に `.htaccess`（Deny from all）も書く。
  `*.sqlite` と `web/lib/secret.php` は .gitignore 済み
- **名札コードは6文字＝5文字＋チェックサム1桁**（紛らわしい字を除いた32文字 `PASS_ALPH`）。
  発行は manage.php の名札印刷（`pass_new()`）で、**サーバは発行済みコードの控えを持たず、
  `pass_ok()` の検証だけ**を行う（名簿側の外部ツールで発行しても同じ規則なら通る）。
  ただし参加には受付での「参加登録」（`pass` テーブル）が別に要る
- 鍵の持ち方: コードに残る秘密は `secret.php`（manage.php の Basic 認証）だけ。
  受付の鍵は DB 保存（manage.php で表示・再発行）、ブースの鍵は enroll 時に DB へ発行。
  照合はすべて `hash_equals()`
- **ブース用QRは booth.php への鍵つきURL**なので、一度配ったトークンと鍵は変えないこと
- イベント名・開催日・必要ブース数は manage.php から `setting` テーブルに保存する。
  `config.php` の定数（`NEEDS`・`EVENT_TITLE` など）はその既定値にすぎない
- 出展者登録キー（`ekey`）の発行は manage.php だけ。ファイルからの seed はしない
  （「すべて初期化」後に旧キーが復活しないように）
- ブース画面など当日に使う画面の API は、例外時も**必ず JSON を返す**
  （PHP のエラーがそのまま画面に出ると現場を不安にさせる）
