-- Tapo P110M 電力データ収集システム - フェーズ2追加分（Webダッシュボード用）
-- 実行例: mysql -u root -p tapo < setup_dashboard.sql

USE tapo;

-- Remember Me トークン（ダッシュボードのセッション延長用）
-- token はCookieに平文で保存されるため、DBにはハッシュ値のみ保存する。
CREATE TABLE IF NOT EXISTS remember_tokens (
    token_hash  CHAR(64)   NOT NULL PRIMARY KEY,
    expires_at  DATETIME   NOT NULL,
    created_at  DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_remember_tokens_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 既存の tapo_app ユーザーは setup.sql で SELECT/INSERT/UPDATE のみ付与されているため、
-- ログイン時のトークン発行・ログアウト時のトークン削除に必要な DELETE 権限を追加する。
GRANT DELETE ON tapo.remember_tokens TO 'tapo_app'@'localhost';
FLUSH PRIVILEGES;
