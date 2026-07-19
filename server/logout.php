<?php
/**
 * ログアウト処理: セッション破棄 + Remember Meトークン削除
 */
require_once __DIR__ . '/auth.php';

tapo_force_https();
tapo_session_start();

if (isset($_COOKIE[TAPO_REMEMBER_COOKIE])) {
    $token_hash = hash('sha256', $_COOKIE[TAPO_REMEMBER_COOKIE]);
    $mysqli = getDbConnection();
    if ($mysqli) {
        $stmt = $mysqli->prepare('DELETE FROM remember_tokens WHERE token_hash = ?');
        $stmt->bind_param('s', $token_hash);
        $stmt->execute();
        $stmt->close();
        $mysqli->close();
    }
    setcookie(TAPO_REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']
    );
}
session_destroy();

header('Location: login.php');
exit;
