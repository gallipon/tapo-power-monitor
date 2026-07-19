# tapo — Tapo P110M 電力データ収集システム

Tapo P110M スマートプラグ3台の電力データを収集・蓄積するシステム。
フェーズ1（本リポジトリの現状）は収集+蓄積のみで、可視化（ダッシュボード）は含まない。

## 構成図

```
[P110M ×3 (192.168.1.100/101/102)]
        │ LAN内API (tapo/python-kasa)
        ▼
[raspi5: Python常駐サービス (collector.py)]
  ├─ 60秒ごと  : 瞬時電力(W)取得 → SQLiteバッファ (buffer.db)
  ├─ 600秒ごと : 未送信バッファをまとめてバッチPOST
  └─ 3600秒ごと: デバイス内蔵hourly電力量履歴(Wh)を取得しupsert
        │ HTTPS POST (X-API-Key)
        ▼
[VPS: Apache + PHP 8.3  /var/www/html/tapo/api/]
  ├─ power.php  : 瞬時電力バッチ受信 (INSERT IGNORE)
  └─ energy.php : hourly電力量受信 (INSERT ... ON DUPLICATE KEY UPDATE)
        │
        ▼
[MySQL 8.0: DB tapo]
  ├─ devices        : デバイス台帳
  ├─ power_minute    : 分単位の瞬時電力
  └─ energy_hourly   : 時間別電力量(Wh)
```

## ディレクトリ構成

```
raspi/                    raspi5に配置するPython収集サービス
  collector.py             常駐サービス本体（asyncio、3系統の周期タスク）
  test_devices.py          デバイス導通テストスクリプト
  requirements.txt         Python依存パッケージ
  .env.example             環境変数サンプル（実際の.envは秘密情報のためコミットしない）
  tapo-collector.service   systemd user unit

server/                   VPSに配置するPHP API + SQL
  api/
    db_config.php          DB接続共通関数
    power.php               瞬時電力受信API
    energy.php               電力量(hourly)受信API
  sql/
    setup.sql               DB・テーブル・初期データ・アプリ用ユーザー作成

docs/
  deploy.md                デプロイ手順書（raspi5側・VPS側・導通確認）
```

## 設計の要点

- 収集本体（raspi）とAPI（server）はHTTPS + `X-API-Key` ヘッダーで認証する
  （housemonitorプロジェクトの認証パターンを踏襲）
- デバイスアクセスは `TapoDevice` クラスに薄くラップしてあり、`tapo` ライブラリが
  P110M(JP)個体（discovery上 Encrypt Type "TPAP"）に接続できない場合は
  python-kasa実装への差し替えが可能な構造になっている
- ネットワーク不通時はraspi5側SQLiteにバッファし、復旧後に再送する
  （`power_minute` の主キーが `(device_key, ts)` のため再送しても重複しない）
- systemdはroot権限を使わず **user unit** として運用する
  （housemonitorのroot cron運用の反省を踏まえた設計）

## 今後の予定（フェーズ2以降）

- ダッシュボード（電力・電力量のグラフ表示）の追加
- アラート・異常検知（消し忘れ検知等）の検討

## セットアップ

`docs/deploy.md` を参照。
