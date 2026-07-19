# デプロイ手順（フェーズ1: 収集+蓄積）

対象: raspi5（収集サービス）+ VPS（受信API・DB）

## 0. 前提

- raspi5: Debian 12, Python 3.11, 実行ユーザー `youruser`
- VPS: Apache + PHP 8.3, MySQL 8.0（housemonitorと同居想定）
- P110M ×3台が LAN内 192.168.1.100 / .101 / .102 で固定IP運用されていること

---

## 1. VPS側

### 1-1. DBセットアップ

```bash
mysql -u root -p < server/sql/setup.sql
```

`server/sql/setup.sql` 内の `CHANGE_ME_PASSWORD` は実際のパスワードに書き換えてから実行するか、
実行後に別途 `ALTER USER 'tapo_app'@'localhost' IDENTIFIED BY '実パスワード';` で変更する。

### 1-2. アプリ配置

`server/` の中身を `/var/www/html/tapo/` 以下に配置する。

```
/var/www/html/tapo/api/db_config.php
/var/www/html/tapo/api/power.php
/var/www/html/tapo/api/energy.php
```

（`sql/` はDBセットアップ用なのでドキュメントルード配下に置く必要はない）

### 1-3. 環境変数の設定（Apache SetEnv）

housemonitor構築時の教訓: **SetEnv の値に `$` や `!` などの記号を含めると正しく渡らないことがある。
API_KEY・DBパスワードは英数字のみで生成すること。**

vhost設定（または `/tapo/.htaccess`、AllowOverride が有効な場合）に追記する例:

```apache
SetEnv TAPO_DB_HOST localhost
SetEnv TAPO_DB_USER tapo_app
SetEnv TAPO_DB_PASS your_db_password_here
SetEnv TAPO_DB_NAME tapo
SetEnv TAPO_API_KEY your_random_api_key_here
```

`TAPO_API_KEY` は raspi5側 `.env` の `API_KEY` と同じ値にする。

設定後、Apacheを再読み込みする:

```bash
sudo apache2ctl configtest
sudo systemctl reload apache2
```

---

## 2. raspi5側

### 2-1. ファイル転送

```bash
scp -r raspi/ youruser@raspi5:/home/youruser/tapo
```

（`raspi/` 直下のファイルが `/home/youruser/tapo/` 直下に来るように、
　中身だけ転送するか、転送後に `mv` で調整する）

### 2-2. venv作成・依存インストール

```bash
ssh youruser@raspi5
cd /home/youruser/tapo
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

### 2-3. .env作成

```bash
cp .env.example .env
chmod 600 .env
nano .env   # TAPO_EMAIL / TAPO_PASSWORD / API_URL / API_KEY / DEVICES を設定
```

`API_KEY` はVPS側 `TAPO_API_KEY` と同じ値、英数字のみ。

### 2-4. systemd user unit配置

```bash
mkdir -p ~/.config/systemd/user
cp tapo-collector.service ~/.config/systemd/user/
```

user unitはログイン中しか動かないのがデフォルトなので、
再起動後も自動起動させるために linger を有効化する（rootで1回のみ）:

```bash
sudo loginctl enable-linger youruser
```

サービス有効化・起動:

```bash
systemctl --user daemon-reload
systemctl --user enable --now tapo-collector.service
```

状態確認:

```bash
systemctl --user status tapo-collector.service
journalctl --user -u tapo-collector.service -f
```

---

## 3. 導通確認手順

### 3-1. デバイス疎通テスト

```bash
cd /home/youruser/tapo
source .venv/bin/activate
python test_devices.py
```

3台それぞれについて model / fw_ver / current_power(生値・換算値) / today_energy が
表示されればOK。失敗した場合はそのデバイスのエラー内容を確認する
（tapoライブラリがP110M(JP)個体に接続できない場合はここで顕在化する）。

### 3-2. collector手動起動での動作確認

```bash
python collector.py
```

コンソールに `[main] 収集開始 ...` が出て、60秒おきに各デバイスの取得が
（エラーが出ていなければ無言で）進むことを確認する。Ctrl+Cで停止。

初回は600秒待たないと送信されないので、急ぐ場合は一時的に
`SEND_INTERVAL_SEC` を短い値に変更して確認し、確認後に戻す。

### 3-3. API疎通確認（curl）

```bash
curl -i -X POST "https://example.com/tapo/api/power.php" \
  -H "X-API-Key: your_random_api_key_here" \
  -H "Content-Type: application/json" \
  -d '{"records":[{"device_key":"plug1","ts":"2026-07-17 12:00:00","power_w":12.3}]}'
```

`HTTP/1.1 200 OK` と `{"received":1,"inserted":1}` 相当のJSONが返ればOK。
X-API-Keyが違う場合は401、必須フィールド欠落は400になることも確認しておく。

### 3-4. MySQLでのデータ確認

```sql
USE tapo;
SELECT * FROM power_minute ORDER BY ts DESC LIMIT 10;
SELECT * FROM energy_hourly ORDER BY hour_start DESC LIMIT 10;
```

---

## 4. 運用メモ

- collectorが異常終了しても `Restart=always` (RestartSec=30) により自動再起動される
- SQLiteバッファ（`~/tapo/buffer.db`）はVPS送信済み(sent=1)から7日経過した行を自動削除する
- サービス停止: `systemctl --user stop tapo-collector.service`
- ログ確認: `journalctl --user -u tapo-collector.service --since "1 hour ago"`

---

# デプロイ手順（フェーズ2: Webダッシュボード）

対象: VPS（Apache + PHP 8.3 + MySQL 8.0）のみ。raspi5側の変更は無し。

## 1. DBセットアップ（remember_tokens 追加）

```bash
mysql -u root -p tapo < server/sql/setup_dashboard.sql
```

`tapo.remember_tokens` テーブルの作成と、既存 `tapo_app` ユーザーへの `DELETE` 権限追加
（フェーズ1の `setup.sql` では SELECT/INSERT/UPDATE のみ付与していたため、
ログイン時のトークン発行・失効・ログアウト時の削除に必要な分を追加する）。

## 2. アプリ配置

`server/` の中身を `/var/www/html/tapo/` 以下に配置する（フェーズ1の `api/` 3ファイルに加えて以下を追加）。

```
/var/www/html/tapo/auth.php
/var/www/html/tapo/login.php
/var/www/html/tapo/logout.php
/var/www/html/tapo/index.php
/var/www/html/tapo/api/db_config.php   （既存・変更なし）
/var/www/html/tapo/api/power.php       （既存・変更なし）
/var/www/html/tapo/api/energy.php      （既存・変更なし）
/var/www/html/tapo/api/data.php        （新規）
```

（`sql/` はドキュメントルート配下に置く必要はない）

## 3. Apache設定変更（SetEnvの適用範囲拡大 + TAPO_DASH_PASSWORD追加）

フェーズ1では `<Directory /var/www/html/tapo/api>` にのみ `SetEnv` が適用されていた想定だが、
`index.php` / `auth.php` など `api/` の外にあるファイルもDB接続情報を必要とするため、
`SetEnv` の適用範囲を `/var/www/html/tapo` 全体に広げる。あわせてダッシュボードの
ログインパスワード用に `TAPO_DASH_PASSWORD` を追加する。

vhost設定の該当箇所を以下のように変更する:

```apache
# 変更前:
# <Directory /var/www/html/tapo/api>
#     SetEnv TAPO_DB_HOST localhost
#     SetEnv TAPO_DB_USER tapo_app
#     SetEnv TAPO_DB_PASS your_db_password_here
#     SetEnv TAPO_DB_NAME tapo
#     SetEnv TAPO_API_KEY your_random_api_key_here
# </Directory>

# 変更後:
<Directory /var/www/html/tapo>
    SetEnv TAPO_DB_HOST localhost
    SetEnv TAPO_DB_USER tapo_app
    SetEnv TAPO_DB_PASS your_db_password_here
    SetEnv TAPO_DB_NAME tapo
    SetEnv TAPO_API_KEY your_random_api_key_here
    SetEnv TAPO_DASH_PASSWORD your_dashboard_password_here
</Directory>
```

`TAPO_DASH_PASSWORD` は英数字のみで生成する（フェーズ1と同じ教訓: `$` や `!` を含めると
SetEnvの値が正しく渡らないことがある）。`TAPO_API_KEY` は収集用API（power.php/energy.php）専用の
ままでよく、ダッシュボードのログインパスワードとは別の値にする。

設定後、Apacheを再読み込みする:

```bash
sudo apache2ctl configtest
sudo systemctl reload apache2
```

## 4. 動作確認手順

### 4-1. ログイン

1. `https://example.com/tapo/` にアクセス（未ログイン時は `login.php` へ自動リダイレクトされる）
2. `TAPO_DASH_PASSWORD` に設定した値でログインできることを確認
3. 「ログイン状態を保持する」を有効にして再ログインし、ブラウザを再起動しても
   セッションが保持される（Remember Meトークンが効いている）ことを確認
4. ヘッダーの「ログアウト」でログアウトし、`login.php` に戻ることを確認

### 4-2. ダッシュボード表示

- 上部カードに3台分（plug1/plug2/plug3）の現在の電力が表示され、
  plug1のようにデータが無い/古いデバイスは「オフライン」表示になることを確認
- 瞬時電力の折れ線チャートで 1時間/6時間/24時間 タブが切り替わることを確認
- 時間別kWh棒グラフで 今日/7日 タブが切り替わることを確認
- 日別kWh積み上げ棒グラフ（30日）が表示されることを確認
- 30秒待って上部カードの値が自動更新されることを確認
- ブラウザの配色設定（ダーク/ライト）を切り替えて表示が追従することを確認

### 4-3. API疎通確認（curl・要ログイン済みCookie）

```bash
# ログインしてCookieを保存
curl -c cookies.txt -i -X POST "https://example.com/tapo/login.php" \
  --data-urlencode "csrf_token=$(curl -c cookies.txt -s https://example.com/tapo/login.php | grep -oP 'csrf_token" value="\K[^"]+')" \
  --data-urlencode "password=your_dashboard_password_here"

# 認証済みCookieでデータAPIを叩く
curl -b cookies.txt "https://example.com/tapo/api/data.php?type=current"
```

`{"devices":[...]}` 相当のJSONが返ればOK。Cookie無しでアクセスすると
`{"error":"unauthorized"}` とHTTP 401が返ることも確認しておく。

## 5. 運用メモ（フェーズ2）

- `remember_tokens` の期限切れ行は、ログイン成功時に副作用的に掃除される
  （明示的なcronは用意していない）
- 収集用API（`api/power.php` / `api/energy.php`）の `X-API-Key` 認証はフェーズ1のまま変更していない
- `data.php` はセッション認証のみで `X-API-Key` は不要（ブラウザのAJAX用）
