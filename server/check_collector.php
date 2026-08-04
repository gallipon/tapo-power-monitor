<?php
/**
 * collector 死活監視スクリプト
 *
 * cronで定期実行し、デバイスごとに計測データが一定時間届かなければ ntfy.sh にアラートを送る。
 * 連続しては通知せず、復帰時にも通知する。
 *
 * デバイス単位で判定するのが要点。collector プロセスが生きていても特定のプラグだけ
 * 認証に失敗して収集が止まることがあり、プロセスの死活監視では検知できない。
 *
 * 閾値について:
 *   collector は約10分ぶんをバッファして一括送信するため、DBの最新行は常に0〜10分古い。
 *   閾値はこのバッチ間隔を十分に上回る必要がある（ALERT_THRESHOLD_MINUTES 参照）。
 *
 * 設定:
 *   環境変数 TAPO_NTFY_TOPIC   ntfy.sh のトピック名
 *   環境変数 TAPO_DB_*         DB接続情報（api/db_config.php 参照）
 *   Apache の SetEnv は CLI には効かないため、cron 側で環境変数を定義すること。
 *
 * cron例（5分ごとに実行）:
 *   * /5 * * * * /usr/bin/php /var/www/html/tapo/check_collector.php >> /var/log/tapo_check.log 2>&1
 */

// Web経由での実行を禁止する（cron専用）
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/api/db_config.php';

// PHP CLI は date.timezone 未設定だとUTCになり、ログの時刻がずれる。
// 死活判定自体は MySQL の NOW() で行うため影響はないが、ログを読めるようにしておく。
date_default_timezone_set(getenv('TAPO_TZ') ?: 'Asia/Tokyo');

/** データが届かなければアラートを出す閾値（分）。バッチ間隔10分＋余裕。 */
const ALERT_THRESHOLD_MINUTES = 30;

/** デバイスの状態（ダウン中かどうか）を記録するファイル。 */
const STATE_FILE = '/tmp/tapo_collector_state.json';

$ntfy_topic = getenv('TAPO_NTFY_TOPIC');
if (!$ntfy_topic) {
    log_msg('ERROR: TAPO_NTFY_TOPIC is not set');
    exit(1);
}

$mysqli = getDbConnection();
if (!$mysqli) {
    log_msg('ERROR: DB connection failed');
    exit(1);
}

// デバイスごとの最終計測からの経過分を取得する
$sql = 'SELECT d.device_key,
               d.name,
               TIMESTAMPDIFF(MINUTE, MAX(p.ts), NOW()) AS diff_min
          FROM devices d
          LEFT JOIN power_minute p ON p.device_key = d.device_key
         GROUP BY d.device_key, d.name
         ORDER BY d.device_key';

$result = $mysqli->query($sql);
if (!$result) {
    log_msg('ERROR: query failed: ' . $mysqli->error);
    $mysqli->close();
    exit(1);
}

// 前回の状態をファイルから読み込む
$state = [];
if (file_exists(STATE_FILE)) {
    $state = json_decode(file_get_contents(STATE_FILE), true) ?? [];
}

$down_keys = [];
$rows      = [];

while ($row = $result->fetch_assoc()) {
    // 一度もデータが無いデバイスは判定対象外
    if ($row['diff_min'] === null) {
        continue;
    }
    $row['diff_min'] = (int)$row['diff_min'];
    $rows[]          = $row;

    if ($row['diff_min'] >= ALERT_THRESHOLD_MINUTES) {
        $down_keys[] = $row['device_key'];
    }
}

$mysqli->close();

// 全台停止は collector 自体の停止を意味するので優先度を上げる
$all_down = $rows && count($down_keys) === count($rows);

foreach ($rows as $row) {
    $key      = $row['device_key'];
    $label    = "{$row['name']}（{$key}）";
    $diff_min = $row['diff_min'];

    $is_down  = $diff_min >= ALERT_THRESHOLD_MINUTES;
    $was_down = $state[$key]['is_down'] ?? false;

    if ($is_down && !$was_down) {
        log_msg("ALERT: {$label} のデータが {$diff_min} 分間届いていません");
        send_ntfy(
            $ntfy_topic,
            $all_down ? 'Tapo アラート（全台停止）' : 'Tapo アラート',
            "{$label} のデータが {$diff_min} 分間届いていません",
            $all_down ? 'urgent' : 'high',
            'zap,warning'
        );
        $state[$key]['is_down'] = true;
    } elseif (!$is_down && $was_down) {
        log_msg("RECOVERY: {$label} が復帰しました");
        send_ntfy(
            $ntfy_topic,
            'Tapo 復帰',
            "{$label} のデータが再び届き始めました",
            'default',
            'zap,white_check_mark'
        );
        $state[$key]['is_down'] = false;
    }
}

file_put_contents(STATE_FILE, json_encode($state));


/**
 * ntfy.sh に通知を送る
 *
 * @param string $topic    ntfy.sh のトピック名
 * @param string $title    通知タイトル
 * @param string $body     通知本文
 * @param string $priority 優先度（urgent/high/default/low/min）
 * @param string $tags     タグ（カンマ区切り、絵文字コード）
 */
function send_ntfy(string $topic, string $title, string $body, string $priority, string $tags): void
{
    $ch = curl_init("https://ntfy.sh/{$topic}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            "Title: {$title}",
            "Priority: {$priority}",
            "Tags: {$tags}",
            'Content-Type: text/plain; charset=utf-8',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);

    curl_exec($ch);

    if (curl_errno($ch)) {
        log_msg('ERROR: ntfy send error: ' . curl_error($ch));
    }

    curl_close($ch);
}

/**
 * タイムスタンプ付きでログを出力する
 *
 * @param string $message ログメッセージ
 */
function log_msg(string $message): void
{
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$message}" . PHP_EOL;
}
