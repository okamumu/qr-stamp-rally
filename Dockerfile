# スタンプラリー（PHP 7.4 + SQLite）
#
#   docker build -t rentai-stamp .
#   docker run -d -p 8080:80 \
#     -v "$PWD/web/lib/secret.php:/var/www/html/lib/secret.php:ro" \
#     -v stamp-data:/data rentai-stamp
#
# secret.php（manage.php の Basic 認証）はイメージに入れない。
# 実行時にマウントする（secret.example.php からの作り方はそちらを参照）。
# DB は /data（ボリューム）に置く＝公開領域の外。
FROM php:7.4-apache

COPY web/ /var/www/html/

ENV STAMP_DB_DIR=/data
RUN mkdir /data && chown www-data:www-data /data
VOLUME /data
