<?php
/**
 * ダッシュボード認証ガード
 * index.php / api/data.php から require_once し、tapo_require_auth() を呼ぶ想定。
 * セッション + Remember Me トークン（tapo.remember_tokens）で認証を確認する。
 * パスワードそのものの検証は login.php 側で行う（ここではガードのみ）。
 */

require_once __DIR__ . '/api/db_config.php';

const TAPO_REMEMBER_COOKIE = 'tapo_remember';
const TAPO_REMEMBER_DAYS = 90;

/**
 * HTTPS強制。プレーンHTTPでのアクセスをHTTPSへリダイレクトする。
 */
function tapo_force_https(): void {
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        $redirectURL = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        header('Location: ' . $redirectURL);
        exit;
    }
}

/**
 * セッション開始（Secure/HttpOnly/SameSite=Lax、90日）。
 * 二重呼び出しでも安全。
 */
function tapo_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $lifetime = TAPO_REMEMBER_DAYS * 24 * 60 * 60;
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Remember Me トークンをCookieから検証し、有効なら $_SESSION を認証済みにする。
 */
function tapo_check_remember_token(): bool {
    if (empty($_COOKIE[TAPO_REMEMBER_COOKIE])) {
        return false;
    }
    $token_hash = hash('sha256', $_COOKIE[TAPO_REMEMBER_COOKIE]);

    $mysqli = getDbConnection();
    if (!$mysqli) {
        return false;
    }
    $stmt = $mysqli->prepare('SELECT 1 FROM remember_tokens WHERE token_hash = ? AND expires_at > NOW()');
    $stmt->bind_param('s', $token_hash);
    $stmt->execute();
    $stmt->store_result();
    $found = $stmt->num_rows > 0;
    $stmt->close();
    $mysqli->close();

    if ($found) {
        $_SESSION['authenticated'] = true;
    }
    return $found;
}

/**
 * 認証ガード本体。
 * 未認証時: $json=true なら 401 JSON、false なら login.php へリダイレクトして終了する。
 */
function tapo_require_auth(bool $json = false): void {
    tapo_session_start();

    if (empty($_SESSION['authenticated']) && !tapo_check_remember_token()) {
        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'unauthorized']);
        } else {
            header('Location: login.php');
        }
        exit;
    }
}
