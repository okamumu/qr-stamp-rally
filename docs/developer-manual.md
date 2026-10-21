# 開発者マニュアル — QRスタンプラリー

PHP ＋ SQLite（PDO）。7.4 でも動く書き方を守る（レンタルサーバ向け。Docker イメージは 8.2）。
フレームワーク・ビルド・Composer は使わない。
レンタルサーバ（さくら等）にファイルを置くだけで動くことを優先した作り。
コード中のコメント・文言はすべて日本語。

## 1. 設計の前提

役割分担（これがコード全体の前提）:

- **参加者は名札を下げているだけ**。端末には何も保存しない（iOS のコードスキャナは
  読むたびに使い捨て領域で開くため、localStorage 方式は破綻した経緯がある。
  `index.html` のコメント参照）
- **出展者が参加者の名札QRを読む**（`booth.php`）。ブースは登録キーでセルフ登録（`enroll.php`）
- **受付が名札QRを読んで参加登録**（`register.php` / `admin.php`）。登録済み（`pass` テーブル）の
  名札だけがラリーに参加でき、必要ブース数に達したら特典を渡して記録する

## 2. ファイル構成

| パス | 役割 |
|---|---|
| `web/index.html` | 参加者ページ。名札QRの行き先（`?n=コード`）。`status.php` を fetch して進捗を描画 |
| `web/status.php` | 進捗 JSON API（`?n=コード`） |
| `web/enroll.php` | ブースのセルフ登録。登録キー＋出展者名 → トークン・鍵を発行し読み取りQRを表示 |
| `web/booth.php` | ブースの読み取り画面（`?b=トークン&key=鍵`）。`?scan=1&n=コード` で記録（JSON） |
| `web/register.php` | 受付の参加登録専用画面。実体は admin.php の登録APIを叩く |
| `web/admin.php` | 特典窓口（照会・特典記録）＋登録API（`?admin=鍵`） |
| `web/export.php` | 集計表示と CSV 書き出し（`?admin=鍵`） |
| `web/manage.php` | 管理コンソール（Basic 認証）。設定・キー発行・名札印刷・バックアップ・初期化 |
| `web/lib/config.php` | 設定・鍵・コード検証（`pass_ok()` `booth_ok()` `admin_ok()` `needs()` など） |
| `web/lib/db.php` | DB 接続・スキーマ・共通クエリ（`db()` `got_of()` `draw_of()` `pass_register()` など） |
| `web/lib/secret.php` | manage.php の Basic 認証（git管理外。`secret.example.php` からコピー） |
| `web/jsQR.js` / `web/qrgen.js` | QR 読み取り／生成のバンドル（手を入れない） |
| `tests/` | 素の Node スクリプトによるテスト |

## 3. データベース

SQLite 1ファイル。`db()`（`lib/db.php`）が接続時に `CREATE TABLE IF NOT EXISTS` まで行う。
**マイグレーションの仕組みは無い**——列を増やすときは既存DBへの影響を自分で考える。
WAL モード・busy_timeout 5000ms。

| テーブル | 役割 | 主な列 |
|---|---|---|
| `scan` | スタンプ（読み取り）記録 | ts, code（名札）, booth（トークン） |
| `draw` | 特典の受け渡し記録 | ts, code, n（特典の段。1始まり） |
| `pass` | 参加登録（受付で登録した名札） | code（PK）, ts |
| `booth` | ブース | token（PK）, name, key, enroll_key, ts |
| `ekey` | 出展者登録キー | k（PK）, ts |
| `setting` | 設定（key-value） | k（PK）, v |

`setting` の主なキー: `ev_title` `ev_date` `needs`（カンマ区切り昇順）`admin_key`。
`config.php` の定数 `NEEDS` `EVENT_TITLE` は **DB に値が無いときの既定値にすぎない**。

### DB の置き場所

`db_path()`（config.php）が決める。**必ず公開領域の外に置く。**

- 環境変数 `STAMP_DB_DIR` があればそこ（Docker では `/data`）
- 無ければ `lib/` の4つ上の `qr_stamp_rally/`（さくらで `~/www/<ドメイン>/stamp/lib/` に
  置いた場合、ホーム直下＝公開領域の外になる深さ）
- 置き場所には念のため `.htaccess`（Deny from all）も書く
- `*.sqlite` と `secret.php` は .gitignore 済み

## 4. 鍵とコードの設計

### 名札コード（6文字＝5文字＋チェックサム1桁）

- 字母は紛らわしい字（0/O/1/I）を除いた32文字 `PASS_ALPH = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'`
- 検査文字 = `ALPH[ Σ_{i=0..4} (i+1)×idx(c_i) mod 32 ]`
- **サーバは発行済みコードの控えを持たない**。`pass_ok()` の形式検証のみ行う。
  発行は `pass_new()`（manage.php の名札印刷）だが、名簿側の外部ツールで
  同じ規則で発行しても通る
- ただし参加には受付での「参加登録」（`pass` テーブルへの insert）が別に必要。
  形式が正しいだけの未登録コードはブースで弾かれる

### 鍵の持ち方

| 鍵 | 置き場所 | 発行・再発行 |
|---|---|---|
| manage.php の Basic 認証 | `lib/secret.php`（コード中で唯一の秘密） | 手動（`password_hash()` で作る） |
| 受付の鍵（admin.php / register.php / export.php の `?admin=`） | DB（setting.admin_key） | manage.php で表示・再発行 |
| ブースの鍵（booth.php の `?key=`） | DB（booth.key）。enroll 時に発行 | 再発行の仕組みは無い（下記） |
| 出展者登録キー（ekey） | DB | manage.php のみ。**ファイルからの seed はしない**（「すべて初期化」後に旧キーが復活しないように） |

照合はすべて `hash_equals()`（タイミング攻撃対策）。

**ブース用QRは booth.php への鍵つきURLそのもの**を印刷・ブックマークして配っている。
一度配ったトークンと鍵を変えると全ブースのQRが紙ごと無効になるので、**変えないこと**。

## 5. コーディング上の約束ごと

- **当日に使う画面の API（booth.php の scan、admin.php の api、status.php）は、
  例外時も必ず JSON を返す**。PHP のエラーがそのまま画面に出ると現場を不安にさせる
  （`try { … } catch (Throwable $e) { … json … }` のパターン）
- POST 処理後は `header('Location: …')` で GET に戻す（リロードによる二重送信防止）
- 出力は必ず `$h()`（htmlspecialchars のローカル関数）でエスケープ
- QR 読み取りは `BarcodeDetector`（Chrome/Android）→ 無ければ同梱 `jsQR`（iOS Safari 対策）
  の2段構え。読み取り文字列からコードを抜く `pick()` は booth/admin/register に
  同じ実装がコピーされている——**直すときは3か所そろえる**（test_pick.js が守っている
  のは admin.php のもの）
- ブースの読み取りは1件ごとにカメラを閉じる（開きっぱなしだと近くの別QRを拾うため）
- 全ページ `noindex,nofollow`
- PHP 7.4 で動く書き方を守る（match 式・enum・readonly 等は使わない）

## 6. テスト

```sh
node tests/test_pick.js   # admin.php の pick()（QR文字列→名札コード抽出）を切り出して検証
node tests/test_page.js   # index.html の <script> を fetch モックで回して表示の分岐を確認
```

- テストランナー無し。各ファイルが素の Node スクリプトで、合格数を表示し NG があれば exit 1
- `test_pick.js` は **PHP ソースから正規表現で関数を切り出す**、`test_page.js` は
  **HTML から `<script>` を切り出して status.php の fetch をモックする**。
  つまり対象の構造や文言を変えるとテストが壊れる——**変更時はテストもそろえて直す**

## 7. ローカルで動かす

```sh
cp web/lib/secret.example.php web/lib/secret.php
php -r "echo password_hash('パスワード', PASSWORD_BCRYPT);"   # 出力を secret.php に貼る

docker build -t qr-stamp-rally .
docker run -d -p 8080:80 \
  -v "$PWD/web/lib/secret.php:/var/www/html/lib/secret.php:ro" \
  -v stamp-data:/data qr-stamp-rally
```

- `secret.php` はイメージに入れず（.dockerignore）実行時にマウント
- DB はボリューム `/data`（`STAMP_DB_DIR` で指定）

Docker を使わない場合は `php -S localhost:8080 -t web` でも動くが、
`STAMP_DB_DIR` を明示しないと DB が `web/` の4つ上に作られる点に注意。

### カメラ無しでの動作確認

- スタンプ記録: `booth.php?b=トークン&key=鍵&scan=1&n=コード`
- 参加登録 API: `admin.php?admin=鍵&api=register&n=コード`（名簿からの一括登録にも使う）
- 進捗: `status.php?n=コード`
- テスト用コードの採番は manage.php の名札印刷で（画面に出るコードは `pass_ok()` を満たす）

## 8. デプロイ（レンタルサーバ）

1. `web/` の中身を公開ディレクトリ（例 `~/www/<ドメイン>/stamp/`）へ置く
2. `lib/secret.php` を作る（example をコピーしてハッシュを貼る）
3. DB の置き場所を確認する。既定の深さ（lib の4つ上）が公開領域の外になるか確かめ、
   ずれる構成なら `STAMP_DB_DIR` を設定する（.htaccess の `SetEnv` 等）
4. `manage.php` を開いて Basic 認証が通ること、イベント名を設定して
   `enroll.php` → `booth.php` → `register.php` → `admin.php` の一連が通ることを確認

運用（キー発行・印刷・初期化・バックアップ）はユーザーマニュアルの主催者の節を参照。

## 9. 典型的な一日のデータの流れ

```
manage.php  : ekey 発行 ──→ enroll.php : booth 行を作成（token/key/enroll_key）
manage.php  : pass_new() で名札印刷（DBには何も残らない）
register.php→ admin.php?api=register : pass 行を作成（参加登録）
booth.php?scan=1 : pass_ok + pass_registered を確認して scan に insert（重複は insert しない）
index.html → status.php : scan を集計して進捗 JSON
admin.php   : needs() と draw の件数を比べ、条件を満たせば draw に insert（特典N）
export.php  : scan/draw を集計、CSV 出力
```
