#!/bin/bash
# VPS デプロイ準備スクリプト(sudo 不要パート)
# 実行: Git Bash でプロジェクトルートから bash scripts/deploy_vps_prep.sh
# やること:
#   1. server/ 一式を vps:~/tapo-deploy/ へ転送
#   2. raspi5 の .env から API キーを取り出して VPS へ受け渡し(画面には表示しない)
#   3. VPS 上で DB パスワードを生成し、setup.sql と Apache 設定(tapo.conf)に埋め込む
set -euo pipefail

PROJ="C:/Users/youruser/Documents/Obsidian Vault/claude-code/projects/tapo"
cd "$PROJ"

echo "[1/3] server/ を VPS へ転送..."
ssh vps 'rm -rf ~/tapo-deploy'
scp -r server vps:tapo-deploy

echo "[2/3] API キーを raspi5 から VPS へ受け渡し..."
ssh raspi5 'grep "^API_KEY=" ~/tapo/.env | cut -d= -f2' | ssh vps 'umask 077; cat > ~/tapo-deploy/.apikey'

echo "[3/3] VPS 上で DB パスワード生成と設定ファイル作成..."
ssh vps '
set -e
DBP=$(openssl rand -hex 16)
sed -i "s/CHANGE_ME_PASSWORD/$DBP/" ~/tapo-deploy/sql/setup.sql
K=$(tr -d "[:space:]" < ~/tapo-deploy/.apikey)
umask 077
cat > ~/tapo-deploy/tapo.conf <<EOF
<Directory /var/www/html/tapo/api>
  SetEnv TAPO_API_KEY $K
  SetEnv TAPO_DB_HOST localhost
  SetEnv TAPO_DB_USER tapo_app
  SetEnv TAPO_DB_PASS $DBP
  SetEnv TAPO_DB_NAME tapo
</Directory>
EOF
chmod 600 ~/tapo-deploy/sql/setup.sql ~/tapo-deploy/tapo.conf
echo "生成完了:"
ls -la ~/tapo-deploy/
'
echo "完了。次は VPS 上で sudo デプロイ手順を実行してください(チャット参照)。"
