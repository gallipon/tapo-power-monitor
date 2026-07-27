-- Tapo P110M 電力データ収集システム - 過去履歴アーカイブ用テーブル
-- 実行例: mysql -u root -p < setup_history.sql
--
-- energy_hourly は「収集を始めてから」のデータしか持てない。それより前の期間は
-- デバイス本体が保持している粗い統計（日別 約3ヶ月 / 月別 約1年）と、Tapoアプリの
-- データエクスポートからしか復元できない。それを保管するのがこの2テーブル。
--
-- collector はこれらのテーブルを一切触らない。投入は scripts/backfill_history.py と
-- scripts/import_tapo_export.py が生成するSQLを手動で流す運用（履歴は静的なため）。
-- ダッシュボードは energy_hourly を優先し、無い期間だけこちらにフォールバックする。

USE tapo;

-- 日別電力量（デバイス本体の日別統計 / アプリエクスポート由来）
CREATE TABLE IF NOT EXISTS energy_daily (
    device_key  VARCHAR(32)   NOT NULL,
    `day`       DATE          NOT NULL,
    wh          INT UNSIGNED  NOT NULL,
    source      VARCHAR(16)   NOT NULL DEFAULT 'device',  -- device | export
    PRIMARY KEY (device_key, `day`),
    CONSTRAINT fk_energy_daily_device FOREIGN KEY (device_key)
        REFERENCES devices (device_key) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 月別電力量（デバイス本体の月別統計 / アプリエクスポート由来）
-- month は当該月の1日（DATE型で持つことで期間比較をそのまま書ける）
CREATE TABLE IF NOT EXISTS energy_monthly (
    device_key  VARCHAR(32)   NOT NULL,
    `month`     DATE          NOT NULL,
    wh          INT UNSIGNED  NOT NULL,
    source      VARCHAR(16)   NOT NULL DEFAULT 'device',  -- device | export
    PRIMARY KEY (device_key, `month`),
    CONSTRAINT fk_energy_monthly_device FOREIGN KEY (device_key)
        REFERENCES devices (device_key) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- tapo_app には setup.sql で `GRANT SELECT, INSERT, UPDATE ON tapo.*` を与えてあるため
-- 追加の権限付与は不要。
