-- Tapo P110M 電力データ収集システム - DBセットアップ
-- 実行例: mysql -u root -p < setup.sql

CREATE DATABASE IF NOT EXISTS tapo DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tapo;

-- デバイス台帳
CREATE TABLE IF NOT EXISTS devices (
    device_key  VARCHAR(32)  NOT NULL PRIMARY KEY,
    name        VARCHAR(64)  NOT NULL,
    ip          VARCHAR(45)  NOT NULL,
    mac         VARCHAR(17)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 瞬時電力(分単位、raspiが1分ごとに取得したもの)
CREATE TABLE IF NOT EXISTS power_minute (
    device_key  VARCHAR(32)    NOT NULL,
    ts          DATETIME       NOT NULL,
    power_w     DECIMAL(8,1)   NOT NULL,
    PRIMARY KEY (device_key, ts),
    CONSTRAINT fk_power_minute_device FOREIGN KEY (device_key)
        REFERENCES devices (device_key) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 電力量(時間別、デバイス内蔵のhourly履歴からの取得値・upsert)
CREATE TABLE IF NOT EXISTS energy_hourly (
    device_key  VARCHAR(32)         NOT NULL,
    hour_start  DATETIME            NOT NULL,
    wh          INT UNSIGNED        NOT NULL,
    PRIMARY KEY (device_key, hour_start),
    CONSTRAINT fk_energy_hourly_device FOREIGN KEY (device_key)
        REFERENCES devices (device_key) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3台分のデバイス登録（name は仮。運用開始後に変更してよい）
INSERT INTO devices (device_key, name, ip, mac) VALUES
    ('plug1', 'プラグ1', '192.168.1.100', 'AA-BB-CC-00-00-01'),
    ('plug2', 'プラグ2', '192.168.1.101', 'AA-BB-CC-00-00-02'),
    ('plug3', 'プラグ3', '192.168.1.102', 'AA-BB-CC-00-00-03')
ON DUPLICATE KEY UPDATE name = VALUES(name), ip = VALUES(ip), mac = VALUES(mac);

-- アプリ用DBユーザー作成（パスワードは必ず変更すること。実際の値は
-- サーバー側 .env や Apache SetEnv の TAPO_DB_PASS と一致させる）
CREATE USER IF NOT EXISTS 'tapo_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_PASSWORD';
GRANT SELECT, INSERT, UPDATE ON tapo.* TO 'tapo_app'@'localhost';
FLUSH PRIVILEGES;
