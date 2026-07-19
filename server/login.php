<?php
/**
 * ダッシュボードログインページ（パスワードのみ、ユーザー名なし）
 */
require_once __DIR__ . '/auth.php';

tapo_force_https();
tapo_session_start();

// 既にログイン済みならダッシュボードへ
if (!empty($_SESSION['authenticated'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $error = '不正なリクエストです。';
    } else {
        $password = $_POST['password'] ?? '';
        $expected = getenv('TAPO_DASH_PASSWORD');

        if ($expected !== false && $expected !== '' && $password !== '' && hash_equals($expected, $password)) {
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;

            $remember_me = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
            if ($remember_me) {
                $token = bin2hex(random_bytes(32));
                $token_hash = hash('sha256', $token);
                $expires_at = date('Y-m-d H:i:s', time() + TAPO_REMEMBER_DAYS * 24 * 60 * 60);

                $mysqli = getDbConnection();
                if ($mysqli) {
                    $stmt = $mysqli->prepare('INSERT INTO remember_tokens (token_hash, expires_at) VALUES (?, ?)');
                    $stmt->bind_param('ss', $token_hash, $expires_at);
                    $stmt->execute();
                    $stmt->close();

                    // 期限切れトークンの掃除（ついでに実行）
                    $mysqli->query('DELETE FROM remember_tokens WHERE expires_at < NOW()');
                    $mysqli->close();

                    setcookie(TAPO_REMEMBER_COOKIE, $token, [
                        'expires'  => time() + TAPO_REMEMBER_DAYS * 24 * 60 * 60,
                        'path'     => '/',
                        'secure'   => true,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }
            }

            header('Location: index.php');
            exit;
        } else {
            $error = 'パスワードが正しくありません。';
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>ログイン - 電力モニター</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  :root {
    color-scheme: light;
    --page-plane: #f9f9f7;
    --surface-1: #fcfcfb;
    --text-primary: #0b0b0b;
    --text-secondary: #52514e;
    --text-muted: #898781;
    --border: rgba(11,11,11,0.10);
    --series-1: #2a78d6;
    --status-critical: #d03b3b;
  }
  @media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
      color-scheme: dark;
      --page-plane: #0d0d0d;
      --surface-1: #1a1a19;
      --text-primary: #ffffff;
      --text-secondary: #c3c2b7;
      --text-muted: #898781;
      --border: rgba(255,255,255,0.10);
      --series-1: #3987e5;
      --status-critical: #e66767;
    }
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--page-plane);
    color: var(--text-primary);
    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
  }
  .login-card {
    width: 100%;
    max-width: 360px;
    margin: 16px;
    padding: 28px 24px;
    background: var(--surface-1);
    border: 1px solid var(--border);
    border-radius: 12px;
  }
  h1 {
    font-size: 1.15rem;
    font-weight: 600;
    margin: 0 0 20px;
    text-align: center;
  }
  label {
    display: block;
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: 6px;
  }
  input[type="password"] {
    width: 100%;
    padding: 10px 12px;
    font-size: 1rem;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--page-plane);
    color: var(--text-primary);
    margin-bottom: 16px;
  }
  .remember-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 20px;
    font-size: 0.85rem;
    color: var(--text-secondary);
  }
  button {
    width: 100%;
    padding: 11px;
    font-size: 1rem;
    font-weight: 600;
    color: #fff;
    background: var(--series-1);
    border: none;
    border-radius: 8px;
    cursor: pointer;
  }
  button:hover { filter: brightness(1.05); }
  .error {
    background: color-mix(in srgb, var(--status-critical) 12%, transparent);
    color: var(--status-critical);
    border: 1px solid var(--status-critical);
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 0.85rem;
    margin-bottom: 16px;
  }
</style>
</head>
<body>
<div class="login-card">
  <h1>電力モニター</h1>
  <?php if ($error): ?>
    <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>
  <form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    <label for="password">パスワード</label>
    <input type="password" id="password" name="password" required autofocus>
    <div class="remember-row">
      <input type="checkbox" id="remember_me" name="remember_me" value="1" checked>
      <label for="remember_me" style="margin:0;">ログイン状態を保持する（90日間）</label>
    </div>
    <button type="submit">ログイン</button>
  </form>
</div>
</body>
</html>
