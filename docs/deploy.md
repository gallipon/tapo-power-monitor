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
（P110M(JP)個体にローカルAPIで接続できない場合はここで顕在化する）。

`AuthenticationError: Device response did not match our challenge` が出る個体は
KLAP認証が壊れたファームウェア（FW1.4.3以降で確認）の可能性が高い。その場合は
Tapoアプリの **マイページ → 音声アシスタント → サードパーティ互換性 を OFF** にして
TPAPで接続させる（アカウント単位の設定なので全デバイスに影響する）。
判定材料として、対象デバイスが広告している暗号方式は次で確認できる:

```bash
python -c "import asyncio,json;from kasa import Discover;\
d=asyncio.run(Discover.discover_single('192.168.1.100'));\
print(json.dumps(d._discovery_info['result']['mgt_encrypt_schm']))"
```

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

---

# デプロイ手順（フェーズ3: 過去履歴アーカイブ）

収集開始より前の期間を、デバイス本体の粗い統計と Tapo アプリのデータエクスポートから
復元して `energy_daily` / `energy_monthly` に保管し、ダッシュボードの日別・月別グラフに
合成表示するための手順。**一度きりの作業**で、collector は関与しない。

## 1. テーブル追加

```bash
scp server/sql/setup_history.sql vps:~/
ssh vps 'sudo mysql --defaults-file=/etc/mysql/debian.cnf < ~/setup_history.sql'
```

`energy_daily` / `energy_monthly` が作られる。`tapo_app` の権限は `tapo.*` に対して
付与済みなので追加のGRANTは不要。

## 2. デバイス本体の統計を吸い出す

デバイスは日別を約3ヶ月・月別を約1年しか保持しない。**早く実行するほど多く残る。**

```bash
scp scripts/backfill_history.py raspi5:/tmp/
ssh raspi5 '~/tapo/.venv/bin/python /tmp/backfill_history.py' > backfill.sql
grep '^--' backfill.sql          # 取得できた行数・範囲を確認する
scp backfill.sql vps:~/
ssh vps 'sudo mysql --defaults-file=/etc/mysql/debian.cnf -D tapo < ~/backfill.sql'
```

## 3. Tapo アプリのデータエクスポートを取り込む（任意）

デバイスから削除・再登録すると本体の統計は消える。その前にアプリの
「データエクスポート」（メールで .xls が2通届く）を取っておけば、そこからも復元できる。

```bash
pip install xlrd
python3 scripts/import_tapo_export.py --device plug1 path/to/*.xls > import.sql
grep '^--' import.sql
scp import.sql vps:~/
ssh vps 'sudo mysql --defaults-file=/etc/mysql/debian.cnf -D tapo < ~/import.sql'
```

デバイス由来とエクスポート由来が同じ日付で衝突した場合は**後から流した方が勝つ**
（どちらも `ON DUPLICATE KEY UPDATE`）。エクスポートの方が古い期間をカバーするので、
デバイス由来 → エクスポート由来の順に流すと直近が実測値のまま残って都合がよい。

## 4. 確認

```bash
ssh vps 'sudo mysql --defaults-file=/etc/mysql/debian.cnf -D tapo -e "
  SELECT device_key, source, COUNT(*) n, MIN(\`day\`), MAX(\`day\`) FROM energy_daily GROUP BY device_key, source;
  SELECT device_key, source, COUNT(*) n, MIN(\`month\`), MAX(\`month\`) FROM energy_monthly GROUP BY device_key, source;"'
```

ダッシュボードの日別（30日/90日/1年）・月別（12ヶ月/24ヶ月）タブで、復元分が
半透明のバーとして表示されれば完了。

---

# デプロイ手順（フェーズ4: 電気料金モデル）

プラグの消費電力量を実際の電気代（増分コスト）に換算するための料金表を DB に入れる。

## 1. テーブル追加とシード

```bash
scp server/sql/setup_tariff.sql vps:~/
ssh vps 'sudo mysql --defaults-file=/etc/mysql/debian.cnf < ~/setup_tariff.sql'
```

`tariff_base` / `tariff_tier` / `tariff_monthly` / `billing_period` / `house_usage_daily` が作られ、
東京電力エナジーパートナー 従量電灯B の単価がシードされる。**自分の契約に合わせて必ず確認・修正すること。**

## 2. 検針票が届いたら

毎月2箇所（`tariff_monthly` / `billing_period`）を更新する。ダッシュボードの「電気代」
セクションが検算結果を表示するので、モデル計算値と実請求額が一致していれば単価が正しい。

### 2-a. 請求書PDFから自動生成する（推奨）

くらしTEPCO web からダウンロードできる「電気料金等請求書」PDF を渡すと SQL を吐く。

```bash
# 読み取り結果の確認だけ
python scripts/import_meisai_pdf.py --check ~/Downloads/meisai_*.pdf

# SQL を生成して流す
python scripts/import_meisai_pdf.py ~/Downloads/meisai_*.pdf > /tmp/billing.sql
scp /tmp/billing.sql vps:~/ && ssh vps 'sudo mysql --defaults-file=/etc/mysql/debian.cnf tapo < ~/billing.sql && rm ~/billing.sql'
```

`pdftotext`（poppler-utils）が要る。複数のPDFをまとめて渡してよい。

**読み取りに失敗したら SQL を出さずに終了する。** 内訳の合計が請求金額と一致することを
確認してから出力するため、書式が変わって取り違えた場合は黙って通らない。

なお燃料費調整は検針票に「当月分」「翌月分」の2つが載るので、1枚から2ヶ月ぶん埋まる。
前月の検針票の「翌月分」と今月の「当月分」は一致するはずで、
複数枚まとめて渡すとこのクロスチェックも兼ねられる。

### 2-b. 手で書く場合

```sql
-- 燃料費調整額は検針票に「当月分」「翌月分」が載るので2行ぶん入る。
-- 再エネ賦課金の単価は年度（5月検針分〜翌年4月検針分）で変わる。
INSERT INTO tariff_monthly (ym, fuel_adj_yen_per_kwh, renewable_yen_per_kwh) VALUES
    ('2026-09-01', -12.34, 4.18)
ON DUPLICATE KEY UPDATE fuel_adj_yen_per_kwh = VALUES(fuel_adj_yen_per_kwh),
                        renewable_yen_per_kwh = VALUES(renewable_yen_per_kwh);

-- 使用期間・使用量・請求予定金額・次回検針予定日を検針票から転記する（値は書式の例）
INSERT INTO billing_period (ym, period_start, period_end, kwh, amount_yen, ampere, next_reading_date) VALUES
    ('2026-09-01', '2026-08-11', '2026-09-09', 400, 13000, 40, '2026-10-10')
ON DUPLICATE KEY UPDATE period_start = VALUES(period_start), period_end = VALUES(period_end),
                        kwh = VALUES(kwh), amount_yen = VALUES(amount_yen), ampere = VALUES(ampere),
                        next_reading_date = VALUES(next_reading_date);
```

## 3. 確認

```bash
curl -b cookies.txt "https://example.com/tapo/api/data.php?type=cost"
```

`bill_yen`（モデル計算）と `actual_yen`（検針票）が一致すればOK。ずれる場合は
`tariff_tier` の段階単価か `tariff_monthly` の燃調・再エネ単価を疑う。

## 4. 家全体の日別使用量（任意・精度向上）

`billing_period` だけでも検針期間単位の増分コストは出せるが、進行中の期間は
「前期の家全体 − プラグ3台」を1日あたりのベースラインとした推定になる。
くらしTEPCO web の一括ダウンロードCSVを `house_usage_daily` に入れると実測に置き換わる。

---

# デプロイ手順（フェーズ5: 収集停止のアラート）

collector が止まったこと、あるいは特定のプラグだけ収集できなくなったことを
ntfy.sh のプッシュ通知で検知する。

## 0. 設計上の前提

- **デバイス単位で判定する。** collector プロセスが生きたまま特定のプラグだけ
  認証に失敗するケースがあり、プロセスの死活監視では検知できない
- **閾値はバッチ間隔を十分に上回らせる。** collector は約10分ぶんをバッファして
  一括送信するため、DB の最新行は正常時でも 0〜10分古い。
  既定の閾値 30分 はこれに1回ぶんの取りこぼしを足した値
- **通知は状態遷移時のみ。** 「正常→異常」「異常→復旧」で1回ずつ。
  閾値超過のたびに投げると停止中は延々と通知が飛ぶ

## 1. 配置

```bash
scp server/check_collector.php vps:~/tapo-deploy/
ssh vps
php -l ~/tapo-deploy/check_collector.php
sudo cp ~/tapo-deploy/check_collector.php /var/www/html/tapo/
```

スクリプトは CLI 専用（Web からのアクセスは 403 を返す）。

## 2. 環境変数

Apache の `SetEnv` は CLI には効かないため、cron 用に root only の env ファイルを用意する。

```bash
sudo tee /etc/tapo-check.env >/dev/null <<'EOF'
TAPO_DB_HOST="localhost"
TAPO_DB_USER="tapo_app"
TAPO_DB_PASS="your_db_password_here"
TAPO_DB_NAME="tapo"
TAPO_NTFY_TOPIC="your-unguessable-topic"
EOF
sudo chmod 600 /etc/tapo-check.env
```

`TAPO_NTFY_TOPIC` は **トピック名そのものが認証情報**（ntfy.sh 無料プランのトピックは公開で、
名前を知っていれば誰でも購読・投稿できる）。UUID 等の推測されない文字列を使い、
リポジトリにも公開する場所にも書かない。

## 3. cron 登録

```bash
sudo crontab -e
```

```
*/5 * * * * set -a; . /etc/tapo-check.env; set +a; /usr/bin/php /var/www/html/tapo/check_collector.php >> /var/log/tapo_check.log 2>&1
```

## 4. 動作確認

異常が無ければ何も出力しない（ログが空なのが正常）。

```bash
# 手動実行（出力なし・終了コード0が正常）
sudo sh -c 'set -a; . /etc/tapo-check.env; set +a; php /var/www/html/tapo/check_collector.php'
```

通知経路まで含めて確かめる場合は、**使い捨てのトピックを使って本番の通知先を汚さない**。
コピーを作って閾値と状態ファイルだけ差し替え、`__DIR__` が壊れないよう
同じディレクトリに置いて実行する。

```bash
sudo bash -c '
SCRATCH="selftest-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d " \n")"
T=/var/www/html/tapo/.cc_test.php
trap "rm -f $T /tmp/cc_test_state.json" EXIT
sed -e "s|ALERT_THRESHOLD_MINUTES = 30|ALERT_THRESHOLD_MINUTES = 1|" \
    -e "s|/tmp/tapo_collector_state.json|/tmp/cc_test_state.json|" \
    /var/www/html/tapo/check_collector.php > $T
set -a; . /etc/tapo-check.env; set +a
export TAPO_NTFY_TOPIC="$SCRATCH"
php $T            # 1回目: 全台ダウン判定
php $T            # 2回目: 再通知しない（出力なしが正）
sed -i "s|ALERT_THRESHOLD_MINUTES = 1|ALERT_THRESHOLD_MINUTES = 1440|" $T
php $T            # 3回目: 復帰通知
sleep 2
curl -s "https://ntfy.sh/${SCRATCH}/json?poll=1"
'
```

## 5. 運用メモ

- 状態ファイルは `/tmp/tapo_collector_state.json`。再起動で消えるが、
  消えた場合は「異常が続いていれば次回実行で改めて通知される」だけで実害はない
- 全台同時にダウンした場合はタイトルが「全台停止」になり優先度が `urgent` に上がる
- ログの時刻は `TAPO_TZ`（既定 `Asia/Tokyo`）で表示する。
  死活判定自体は MySQL の `NOW()` で行うのでタイムゾーン設定の影響を受けない
