<?php
// これを secret.php にコピーして値を書き換える。secret.php は git に入れない。
// ハッシュの作り方: php -r "echo password_hash('あなたのパスワード', PASSWORD_BCRYPT);"
const MANAGE_USER = 'admin';
const MANAGE_PASS_HASH = '$2y$10$ここにpassword_hashの出力を貼る';
