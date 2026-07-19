<?php
/**
 * Tapo P110M 電力(瞬時値)受信API（認証付き）
 * raspi5からPOSTされた1分間隔の瞬時電力バッチをDBに保存する
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
$required = ['device_key', 'ts', 'power_w'];
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

// バッチINSERT: 1クエリに複数VALUESをまとめてプリペアドステートメントで送る。
// raspi側は10分に1回、最大でも3台×10行=30行程度のバッチなので、
// 逐次INSERTよりラウンドトリップを減らせるこの方式にしている。
// 再送時の重複は PRIMARY KEY(device_key, ts) により INSERT IGNORE で無害化する。
$placeholders = [];
$types = '';
$params = [];
foreach ($records as $r) {
    $placeholders[] = '(?, ?, ?)';
    $types .= 'ssd';
    $params[] = (string)$r['device_key'];
    $params[] = (string)$r['ts'];
    $params[] = (float)$r['power_w'];
}

$sql = 'INSERT IGNORE INTO power_minute (device_key, ts, power_w) VALUES ' . implode(', ', $placeholders);
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    error_log('power.php prepare error: ' . $mysqli->error);
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
    error_log('power.php insert error: ' . $stmt->error);
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
echo json_encode(['received' => count($records), 'inserted' => $affected]);
