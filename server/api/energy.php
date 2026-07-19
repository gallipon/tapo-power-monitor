<?php
/**
 * Tapo P110M 電力量(時間別Wh)受信API（認証付き）
 * raspi5からPOSTされたhourly電力量履歴を upsert (欠損補完) でDBに保存する
 */
require_once __DIR__ . '/db_config.php';

// API Key 認証
$api_key = getenv('TAPO_API_KEY');
$request_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!$api_key || !hash_equals($api_key, $request_key)) {
    http_response_code(401);
    exit;
}

// POSTメソッドのみ受け付ける
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// リクエストボディをJSONとしてパース
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data || !isset($data['records']) || !is_array($data['records']) || count($data['records']) === 0) {
    http_response_code(400);
    exit;
}
$records = $data['records'];

// 各レコードのバリデーション
$required = ['device_key', 'hour_start', 'wh'];
foreach ($records as $r) {
    if (!is_array($r) || array_diff($required, array_keys($r))) {
        http_response_code(400);
        exit;
    }
}

// DB接続
$mysqli = getDbConnection();
if (!$mysqli) {
    http_response_code(500);
    exit;
}

// バッチUPSERT: raspi側はデバイス内蔵のhourly履歴を毎回取り直して送ってくるため、
// 同じhour_startが再送されても ON DUPLICATE KEY UPDATE で上書きし欠損を補完する。
$placeholders = [];
$types = '';
$params = [];
foreach ($records as $r) {
    $placeholders[] = '(?, ?, ?)';
    $types .= 'ssi';
    $params[] = (string)$r['device_key'];
    $params[] = (string)$r['hour_start'];
    $params[] = (int)$r['wh'];
}

$sql = 'INSERT INTO energy_hourly (device_key, hour_start, wh) VALUES ' . implode(', ', $placeholders)
     . ' ON DUPLICATE KEY UPDATE wh = VALUES(wh)';
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    error_log('energy.php prepare error: ' . $mysqli->error);
    $mysqli->close();
    http_response_code(500);
    exit;
}

$bindParams = [];
$bindParams[] = &$types;
foreach ($params as $key => $value) {
    $bindParams[] = &$params[$key];
}
call_user_func_array([$stmt, 'bind_param'], $bindParams);

if (!$stmt->execute()) {
    error_log('energy.php upsert error: ' . $stmt->error);
    $stmt->close();
    $mysqli->close();
    http_response_code(500);
    exit;
}

$affected = $stmt->affected_rows;
$stmt->close();
$mysqli->close();

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['received' => count($records), 'affected' => $affected]);
