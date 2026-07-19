<?php
// データベース接続設定（環境変数から読み込み）
// Apache の SetEnv（.htaccess または vhost 設定）でセットする想定。
// housemonitor と同一VPS/DBサーバーを想定しつつ、tapo用に独立した変数名にしている。
define('DB_HOST', getenv('TAPO_DB_HOST') ?: 'localhost');
define('DB_USER', getenv('TAPO_DB_USER') ?: 'tapo_app');
define('DB_PASS', getenv('TAPO_DB_PASS') ?: '');
define('DB_NAME', getenv('TAPO_DB_NAME') ?: 'tapo');

function getDbConnection() {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_errno) {
        error_log("Database connection error: " . $mysqli->connect_error);
        return null;
    }
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}
