<?php
/**
 * ダッシュボード用データAPI（セッション認証、X-API-Key不要）
 * GET type=current|power|hourly|daily
 *
 * SQLは全てプリペアドステートメント。power_minute への問い合わせは
 * 必ず device_key を等値条件に含め、PRIMARY KEY(device_key, ts) を
 * 活かした範囲スキャンにする（device=all の場合もデバイスごとに分割して問い合わせる）。
 * ts はJSTのまま扱い、タイムゾーン変換は行わない。
 */
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=utf-8');
tapo_require_auth(true);

$mysqli = getDbConnection();
if (!$mysqli) {
    http_response_code(500);
    echo json_encode(['error' => 'db connection failed']);
    exit;
}

/** 登録済みデバイスのキー一覧（不正な device パラメータの弾き用にも使う） */
function tapo_device_keys(mysqli $mysqli): array {
    $keys = [];
    $res = $mysqli->query('SELECT device_key FROM devices ORDER BY device_key');
    while ($row = $res->fetch_assoc()) {
        $keys[] = $row['device_key'];
    }
    return $keys;
}

/**
 * 当日(今日)の積算Wh。energy_hourly の当日確定分 + 未確定時間帯を
 * power_minute の平均電力から補完する。
 */
function tapo_today_wh(mysqli $mysqli, string $device_key): float {
    $stmt = $mysqli->prepare(
        'SELECT COALESCE(SUM(wh),0) AS wh, MAX(hour_start) AS max_hour
         FROM energy_hourly WHERE device_key = ? AND hour_start >= CURDATE()'
    );
    $stmt->bind_param('s', $device_key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $wh = (float)$row['wh'];
    $since = $row['max_hour']
        ? date('Y-m-d H:i:s', strtotime($row['max_hour']) + 3600)
        : date('Y-m-d 00:00:00');

    $stmt = $mysqli->prepare(
        'SELECT AVG(power_w) AS avg_w, COUNT(*) AS n
         FROM power_minute WHERE device_key = ? AND ts >= ?'
    );
    $stmt->bind_param('ss', $device_key, $since);
    $stmt->execute();
    $row2 = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row2 && (int)$row2['n'] > 0) {
        // 平均電力(W) × 分数 / 60 = Wh（未確定区間の推定補完）
        $wh += ((float)$row2['avg_w'] * (int)$row2['n']) / 60.0;
    }
    return $wh;
}

function handle_current(mysqli $mysqli): void {
    $out = [];
    $res = $mysqli->query('SELECT device_key, name FROM devices ORDER BY device_key');
    while ($d = $res->fetch_assoc()) {
        $key = $d['device_key'];

        $stmt = $mysqli->prepare(
            'SELECT ts, power_w FROM power_minute WHERE device_key = ? ORDER BY ts DESC LIMIT 1'
        );
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $latest = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $out[] = [
            'device_key' => $key,
            'name'       => $d['name'],
            'power_w'    => $latest ? (float)$latest['power_w'] : null,
            'ts'         => $latest ? $latest['ts'] : null,
            'today_wh'   => round(tapo_today_wh($mysqli, $key), 1),
        ];
    }
    echo json_encode(['devices' => $out]);
}

/** "YYYY-MM-DD HH:MM:SS" 形式かつ実在する日時かを検証する */
function tapo_valid_datetime(string $s): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) {
        return false;
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s);
    return $dt !== false && $dt->format('Y-m-d H:i:s') === $s;
}

/**
 * power_minute から1系列分の時系列を取得する。
 * $until が null の場合は従来どおり下限のみのオープンレンジ（後方互換）。
 * $downsample が true の場合は5分バケット平均（派生テーブル方式）。
 */
function tapo_fetch_power_series(mysqli $mysqli, string $key, string $since, ?string $until, bool $downsample): array {
    if ($downsample) {
        // 5分平均にダウンサンプル。UNIX_TIMESTAMPのバケット化は
        // タイムゾーンのオフセットが整時間である限りJST壁時計時刻でも
        // 5分境界がずれないため、ts をJSTのまま扱ってよい。
        // 派生テーブルで5分バケット(bucket)を先に作り、外側はbucketで
        // GROUP/SELECTする。こうすると SELECT の ts由来式が GROUP BY列(bucket)に
        // 関数従属する形になり、MySQL8 の only_full_group_by を確実に満たす
        // (SELECTとGROUP BYに同一の複合式を直接書くと従属を認識せず拒否される)。
        if ($until !== null) {
            $stmt = $mysqli->prepare(
                'SELECT FROM_UNIXTIME(bucket * 300) AS ts, AVG(power_w) AS power_w
                 FROM (
                     SELECT FLOOR(UNIX_TIMESTAMP(ts) / 300) AS bucket, power_w
                     FROM power_minute
                     WHERE device_key = ? AND ts >= ? AND ts < ?
                 ) t
                 GROUP BY bucket
                 ORDER BY bucket ASC'
            );
            $stmt->bind_param('sss', $key, $since, $until);
        } else {
            $stmt = $mysqli->prepare(
                'SELECT FROM_UNIXTIME(bucket * 300) AS ts, AVG(power_w) AS power_w
                 FROM (
                     SELECT FLOOR(UNIX_TIMESTAMP(ts) / 300) AS bucket, power_w
                     FROM power_minute
                     WHERE device_key = ? AND ts >= ?
                 ) t
                 GROUP BY bucket
                 ORDER BY bucket ASC'
            );
            $stmt->bind_param('ss', $key, $since);
        }
    } else {
        if ($until !== null) {
            $stmt = $mysqli->prepare(
                'SELECT ts, power_w FROM power_minute
                 WHERE device_key = ? AND ts >= ? AND ts < ?
                 ORDER BY ts ASC'
            );
            $stmt->bind_param('sss', $key, $since, $until);
        } else {
            $stmt = $mysqli->prepare(
                'SELECT ts, power_w FROM power_minute
                 WHERE device_key = ? AND ts >= ?
                 ORDER BY ts ASC'
            );
            $stmt->bind_param('ss', $key, $since);
        }
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $points = [];
    while ($row = $res->fetch_assoc()) {
        $points[] = ['ts' => $row['ts'], 'power_w' => round((float)$row['power_w'], 1)];
    }
    $stmt->close();
    return $points;
}

function handle_power(mysqli $mysqli): void {
    $deviceParam = $_GET['device'] ?? 'all';
    $allKeys = tapo_device_keys($mysqli);
    $targetKeys = $deviceParam === 'all' ? $allKeys : array_intersect([$deviceParam], $allKeys);
    if (empty($targetKeys)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid device']);
        return;
    }

    $fromParam = $_GET['from'] ?? null;
    $toParam = $_GET['to'] ?? null;
    $useRange = $fromParam !== null || $toParam !== null;

    if ($useRange) {
        // from/to は両方揃って初めて有効。どちらかのみ、または不正書式は400。
        if ($fromParam === null || $toParam === null
            || !tapo_valid_datetime($fromParam) || !tapo_valid_datetime($toParam)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid from/to']);
            return;
        }
        $fromTs = strtotime($fromParam);
        $toTs = strtotime($toParam);
        if ($toTs <= $fromTs) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid from/to range']);
            return;
        }
        $since = $fromParam;
        $until = $toParam;
        // 明示的な期間指定は「窓の長さ」で共通のダウンサンプル判定を行う。
        $downsample = ($toTs - $fromTs) > 7200; // 2時間超なら5分バケット
        $hours = null;
    } else {
        $hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 1;
        if (!in_array($hours, [1, 6, 24], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid hours']);
            return;
        }
        $since = date('Y-m-d H:i:s', time() - $hours * 3600);
        $until = null; // 従来どおり上限なしのオープンレンジ（後方互換）
        // hours=1/6/24 は enum なので、既存どおり 24h のみダウンサンプルする
        // （hours*3600 をそのまま7200秒しきい値に通すと6hも対象になり、
        //  既存の「1h/6hは生データ」という挙動を壊してしまうため踏襲しない）。
        $downsample = ($hours === 24);
    }

    $series = [];
    foreach ($targetKeys as $key) {
        $series[$key] = tapo_fetch_power_series($mysqli, $key, $since, $until, $downsample);
    }

    $out = ['series' => $series];
    if ($useRange) {
        $out['from'] = $since;
        $out['to'] = $until;
    } else {
        $out['hours'] = $hours;
    }
    echo json_encode($out);
}

function handle_hourly(mysqli $mysqli): void {
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 1;
    if (!in_array($days, [1, 7, 30], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid days']);
        return;
    }

    $allKeys = tapo_device_keys($mysqli);
    // days=1(今日) は当日0時起点、それ以外は「直近N日」のローリング窓
    $since = $days === 1
        ? date('Y-m-d 00:00:00')
        : date('Y-m-d H:i:s', time() - $days * 24 * 60 * 60);

    $series = [];
    foreach ($allKeys as $key) {
        $stmt = $mysqli->prepare(
            'SELECT hour_start, wh FROM energy_hourly
             WHERE device_key = ? AND hour_start >= ?
             ORDER BY hour_start ASC'
        );
        $stmt->bind_param('ss', $key, $since);
        $stmt->execute();
        $res = $stmt->get_result();
        $points = [];
        while ($row = $res->fetch_assoc()) {
            $points[] = ['hour_start' => $row['hour_start'], 'wh' => (int)$row['wh']];
        }
        $stmt->close();
        $series[$key] = $points;
    }

    echo json_encode(['days' => $days, 'series' => $series]);
}

function handle_daily(mysqli $mysqli): void {
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
    if ($days < 1 || $days > 90) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid days']);
        return;
    }

    $allKeys = tapo_device_keys($mysqli);
    $since = date('Y-m-d 00:00:00', time() - ($days - 1) * 24 * 60 * 60);

    $series = [];
    foreach ($allKeys as $key) {
        $stmt = $mysqli->prepare(
            "SELECT DATE(hour_start) AS d, SUM(wh) AS wh
             FROM energy_hourly
             WHERE device_key = ? AND hour_start >= ?
             GROUP BY DATE(hour_start)
             ORDER BY d ASC"
        );
        $stmt->bind_param('ss', $key, $since);
        $stmt->execute();
        $res = $stmt->get_result();
        $points = [];
        while ($row = $res->fetch_assoc()) {
            $points[] = ['date' => $row['d'], 'wh' => (int)$row['wh']];
        }
        $stmt->close();
        $series[$key] = $points;
    }

    echo json_encode(['days' => $days, 'series' => $series]);
}

$type = $_GET['type'] ?? '';
switch ($type) {
    case 'current':
        handle_current($mysqli);
        break;
    case 'power':
        handle_power($mysqli);
        break;
    case 'hourly':
        handle_hourly($mysqli);
        break;
    case 'daily':
        handle_daily($mysqli);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'invalid type']);
}

$mysqli->close();
