<?php
/**
 * ダッシュボード用データAPI（セッション認証、X-API-Key不要）
 * GET type=current|power|hourly|daily|monthly|cost
 *
 * daily / monthly は energy_hourly（収集開始以降・精度高）を優先し、それより前の
 * 期間は energy_daily / energy_monthly のアーカイブ（デバイス本体の粗い統計や
 * Tapoアプリのエクスポート由来）にフォールバックして合成する。詳細は
 * server/sql/setup_history.sql を参照。
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

/**
 * 1デバイス分の日別Whを ['YYYY-MM-DD' => ['wh'=>int,'src'=>string]] で返す。
 *
 * energy_hourly（1時間刻みの実収集値）を日別に集計したものを正とし、その日の
 * データが無い場合だけ energy_daily のアーカイブ値を使う。収集開始前の期間は
 * アーカイブしか無く、収集開始後はアーカイブより energy_hourly の方が正確
 * （アーカイブはデバイスが保持する丸めた統計）なので、この優先順位でよい。
 */
function tapo_daily_composite(mysqli $mysqli, string $key, string $sinceDate): array {
    $out = [];

    $stmt = $mysqli->prepare(
        'SELECT `day` AS d, wh FROM energy_daily WHERE device_key = ? AND `day` >= ?'
    );
    $stmt->bind_param('ss', $key, $sinceDate);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $out[$row['d']] = ['wh' => (int)$row['wh'], 'src' => 'archive'];
    }
    $stmt->close();

    $sinceDt = $sinceDate . ' 00:00:00';
    $stmt = $mysqli->prepare(
        'SELECT DATE(hour_start) AS d, SUM(wh) AS wh
         FROM energy_hourly
         WHERE device_key = ? AND hour_start >= ?
         GROUP BY DATE(hour_start)'
    );
    $stmt->bind_param('ss', $key, $sinceDt);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $out[$row['d']] = ['wh' => (int)$row['wh'], 'src' => 'hourly'];
    }
    $stmt->close();

    ksort($out);
    return $out;
}

function handle_daily(mysqli $mysqli): void {
    // 過去アーカイブを遡って見られるよう上限を広げてある（1年分＝366日を許容）
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
    if ($days < 1 || $days > 400) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid days']);
        return;
    }

    $allKeys = tapo_device_keys($mysqli);
    $sinceDate = date('Y-m-d', time() - ($days - 1) * 24 * 60 * 60);

    // 金額はその日が属する検針期間の限界単価で換算する（一律の定数は使わない）
    $rates = tapo_rate_table($mysqli);

    $series = [];
    foreach ($allKeys as $key) {
        $points = [];
        foreach (tapo_daily_composite($mysqli, $key, $sinceDate) as $d => $v) {
            $rate = tapo_rate_for_date($rates, $d);
            $points[] = [
                'date' => $d,
                'wh'   => $v['wh'],
                'src'  => $v['src'],
                'yen'  => $rate === null ? null : round($v['wh'] / 1000 * $rate, 1),
            ];
        }
        $series[$key] = $points;
    }

    echo json_encode([
        'days'     => $days,
        'series'   => $series,
        'rate_ref' => $rates ? $rates[count($rates) - 1]['rate'] : null,
    ]);
}

/**
 * 月別電力量。日別合成でその月が丸ごと埋まっていればそれを合計し、
 * 埋まっていない（収集もアーカイブも無い日がある）月は energy_monthly の
 * 月次アーカイブ値を使う。進行中の月は途中でも実データの合計を返す。
 */
function handle_monthly(mysqli $mysqli): void {
    $months = isset($_GET['months']) ? (int)$_GET['months'] : 12;
    if ($months < 1 || $months > 36) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid months']);
        return;
    }

    $allKeys = tapo_device_keys($mysqli);
    $firstMonth = date('Y-m-01', strtotime('-' . ($months - 1) . ' month', strtotime(date('Y-m-01'))));
    $currentMonth = date('Y-m-01');
    // 検針期間は暦月とずれるので、その月の中日（15日）が属する期間の単価を代表値とする
    $rates = tapo_rate_table($mysqli);

    $series = [];
    foreach ($allKeys as $key) {
        $archive = [];
        $stmt = $mysqli->prepare(
            'SELECT `month` AS m, wh FROM energy_monthly WHERE device_key = ? AND `month` >= ?'
        );
        $stmt->bind_param('ss', $key, $firstMonth);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $archive[$row['m']] = (int)$row['wh'];
        }
        $stmt->close();

        // 日別合成を月ごとに畳む（合計Whと、値が入っている日数）
        $sum = [];
        $covered = [];
        foreach (tapo_daily_composite($mysqli, $key, $firstMonth) as $d => $v) {
            $m = substr($d, 0, 7) . '-01';
            $sum[$m] = ($sum[$m] ?? 0) + $v['wh'];
            $covered[$m] = ($covered[$m] ?? 0) + 1;
        }

        $points = [];
        for ($i = 0; $i < $months; $i++) {
            $m = date('Y-m-01', strtotime('+' . $i . ' month', strtotime($firstMonth)));
            $daysInMonth = (int)date('t', strtotime($m));
            $hasFull = ($covered[$m] ?? 0) >= $daysInMonth;

            if ($m === $currentMonth || $hasFull) {
                // 進行中の月、または日別で丸ごと埋まっている月は実データを積む
                if (!isset($sum[$m])) { continue; }
                $points[] = ['month' => $m, 'wh' => $sum[$m], 'src' => 'daily'];
            } elseif (isset($archive[$m])) {
                $points[] = ['month' => $m, 'wh' => $archive[$m], 'src' => 'archive'];
            } elseif (isset($sum[$m])) {
                // 月の一部しか無く月次アーカイブも無い（過小評価になるので印を付ける）
                $points[] = ['month' => $m, 'wh' => $sum[$m], 'src' => 'partial'];
            } else {
                continue;
            }
            $last = count($points) - 1;
            $rate = tapo_rate_for_date($rates, date('Y-m-15', strtotime($m)));
            $points[$last]['yen'] = $rate === null ? null : round($points[$last]['wh'] / 1000 * $rate, 1);
        }
        $series[$key] = $points;
    }

    echo json_encode([
        'months'   => $months,
        'series'   => $series,
        'rate_ref' => $rates ? $rates[count($rates) - 1]['rate'] : null,
    ]);
}

/* ---------------------------------------------------------------------------
 * 電気料金（増分コスト）
 *
 * プラグの kWh に平均単価を掛けても実際の電気代にはならない。基本料金は定額だし、
 * 電力量料金は段階制なので「最後に足した kWh」ほど高い段階に乗る。求めたいのは
 * 「そのプラグが無かったら請求がいくら減るか」= 増分コストで、これは
 *   段階料金(家全体) − 段階料金(家全体 − プラグ分) + プラグ分 ×(燃調 + 再エネ)
 * で出る。基本料金は使用量に依存しないので増分には入らない。
 * ------------------------------------------------------------------------- */

/** 指定日時点で有効な段階単価を [{upto_kwh, yen_per_kwh}, ...] で返す（tier_no 昇順） */
function tapo_tiers(mysqli $mysqli, string $onDate): array {
    $stmt = $mysqli->prepare(
        'SELECT tier_no, upto_kwh, yen_per_kwh FROM tariff_tier
         WHERE valid_from = (SELECT MAX(valid_from) FROM tariff_tier WHERE valid_from <= ?)
         ORDER BY tier_no ASC'
    );
    $stmt->bind_param('s', $onDate);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'upto' => $row['upto_kwh'] === null ? INF : (float)$row['upto_kwh'],
            'yen'  => (float)$row['yen_per_kwh'],
        ];
    }
    $stmt->close();
    return $out;
}

/** 段階料金の電力量料金だけを積む（基本料金・燃調・再エネは含まない） */
function tapo_tier_charge(array $tiers, float $kwh): float {
    $sum = 0.0;
    $prev = 0.0;
    foreach ($tiers as $t) {
        $sum += max(0.0, min($kwh, $t['upto']) - $prev) * $t['yen'];
        if ($kwh <= $t['upto']) {
            break;
        }
        $prev = $t['upto'];
    }
    return $sum;
}

/**
 * 指定検針月の kWh 比例単価（燃調 + 再エネ）を返す。
 * その月の登録が無ければ直近の過去の月にフォールバックする（過去分は近似になる）。
 */
function tapo_variable_rate(mysqli $mysqli, string $ym): array {
    $stmt = $mysqli->prepare(
        'SELECT ym, fuel_adj_yen_per_kwh AS fuel, renewable_yen_per_kwh AS renew
         FROM tariff_monthly WHERE ym <= ? ORDER BY ym DESC LIMIT 1'
    );
    $stmt->bind_param('s', $ym);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        // 未来側しか無い場合は最も古い行を使う
        $res = $mysqli->query(
            'SELECT ym, fuel_adj_yen_per_kwh AS fuel, renewable_yen_per_kwh AS renew
             FROM tariff_monthly ORDER BY ym ASC LIMIT 1'
        );
        $row = $res->fetch_assoc();
    }
    if (!$row) {
        return ['fuel' => 0.0, 'renew' => 0.0, 'exact' => false, 'ym' => null];
    }
    return [
        'fuel'  => (float)$row['fuel'],
        'renew' => (float)$row['renew'],
        'exact' => $row['ym'] === $ym,
        'ym'    => $row['ym'],
    ];
}

/** 期間内のプラグ消費量を device_key => kWh で返す（日別合成の合計） */
function tapo_plug_kwh_in_period(mysqli $mysqli, array $keys, string $from, string $to): array {
    $out = [];
    foreach ($keys as $key) {
        $wh = 0;
        foreach (tapo_daily_composite($mysqli, $key, $from) as $d => $v) {
            if ($d <= $to) {
                $wh += $v['wh'];
            }
        }
        $out[$key] = $wh / 1000.0;
    }
    return $out;
}

/** 指定日時点の基本料金（契約アンペア） */
function tapo_base_charge(mysqli $mysqli, ?int $ampere, string $onDate): float {
    if ($ampere === null) {
        return 0.0;
    }
    $stmt = $mysqli->prepare(
        'SELECT yen FROM tariff_base WHERE ampere = ? AND valid_from <= ?
         ORDER BY valid_from DESC LIMIT 1'
    );
    $stmt->bind_param('is', $ampere, $onDate);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (float)$row['yen'] : 0.0;
}

/**
 * 家全体 $houseKwh のときの請求額と、そのうちプラグ $plugKwh 分の増分コストを返す。
 * 再エネ発電賦課金は検針票にならって円未満切り捨て、請求額も最後に切り捨てる。
 */
function tapo_calc_cost(array $tiers, array $rate, float $base, float $houseKwh, float $plugKwh): array {
    $tierCharge = tapo_tier_charge($tiers, $houseKwh);
    $fuel = $houseKwh * $rate['fuel'];
    $renew = floor($houseKwh * $rate['renew']);

    $tierDelta = $tierCharge - tapo_tier_charge($tiers, max(0.0, $houseKwh - $plugKwh));
    $incremental = $tierDelta + $plugKwh * ($rate['fuel'] + $rate['renew']);

    return [
        'bill_yen'      => (int)floor($base + $tierCharge + $fuel + $renew),
        'plug_yen'      => (int)round($incremental),
        'marginal_rate' => $plugKwh > 0 ? round($incremental / $plugKwh, 2) : null,
    ];
}

/**
 * 検針期間ごとの集計を返す。1リクエスト内で複数回呼ばれるのでメモ化する
 * （日別・月別の金額換算からも参照するため）。
 */
function tapo_cost_periods(mysqli $mysqli): array {
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $keys = tapo_device_keys($mysqli);
    $today = date('Y-m-d');

    $periods = [];
    $res = $mysqli->query(
        'SELECT ym, period_start, period_end, kwh, amount_yen, ampere, next_reading_date
         FROM billing_period ORDER BY ym ASC'
    );
    while ($row = $res->fetch_assoc()) {
        $periods[] = [
            'ym'        => $row['ym'],
            'start'     => $row['period_start'],
            'end'       => $row['period_end'],
            'house_kwh' => (float)$row['kwh'],
            'actual'    => (int)$row['amount_yen'],
            'ampere'    => $row['ampere'] === null ? null : (int)$row['ampere'],
            'progress'  => false,
            'next'      => $row['next_reading_date'],
        ];
    }

    // 確定した最終期間の翌日から始まる「進行中の期間」を組み立てる。
    // 家全体の使用量はまだ分からないので、前期の「家全体 − プラグ3台」を1日あたりの
    // ベースラインとみなし、そこに進行中期間のプラグ実測を足して推定する。
    // （house_usage_daily が入れば、この推定はCSVの実測に差し替えられる）
    if ($periods) {
        $last = $periods[count($periods) - 1];
        $curStart = date('Y-m-d', strtotime($last['end'] . ' +1 day'));
        if ($curStart <= $today) {
            $lastDays = (int)round((strtotime($last['end']) - strtotime($last['start'])) / 86400) + 1;
            $lastPlug = array_sum(tapo_plug_kwh_in_period($mysqli, $keys, $last['start'], $last['end']));
            $baseline = $lastDays > 0 ? max(0.0, $last['house_kwh'] - $lastPlug) / $lastDays : 0.0;

            $curEnd = $last['next'] ? date('Y-m-d', strtotime($last['next'] . ' -1 day')) : $today;
            $periods[] = [
                'ym'        => date('Y-m-01', strtotime($curEnd)),
                'start'     => $curStart,
                'end'       => $curEnd,
                'house_kwh' => null,   // 下で推定する
                'actual'    => null,
                'ampere'    => $last['ampere'],
                'progress'  => true,
                'baseline'  => $baseline,
            ];
        }
    }

    $out = [];
    foreach ($periods as $p) {
        $to = min($p['end'], $today);
        $plug = tapo_plug_kwh_in_period($mysqli, $keys, $p['start'], $to);
        $plugTotal = array_sum($plug);

        $days = (int)round((strtotime($p['end']) - strtotime($p['start'])) / 86400) + 1;
        $elapsed = (int)round((strtotime($to) - strtotime($p['start'])) / 86400) + 1;

        $tiers = tapo_tiers($mysqli, $p['end']);
        $rate = tapo_variable_rate($mysqli, $p['ym']);
        $base = tapo_base_charge($mysqli, $p['ampere'], $p['end']);

        $houseKwh = $p['progress'] ? $p['baseline'] * $elapsed + $plugTotal : $p['house_kwh'];
        $now = tapo_calc_cost($tiers, $rate, $base, $houseKwh, $plugTotal);

        // 増分コストのデバイス按分。段階跨ぎを個別に切り分ける自然な方法が無いため
        // 消費量比で割り振る（合計は増分コストと一致する）。
        $perDevice = [];
        foreach ($plug as $k => $kwh) {
            $perDevice[$k] = [
                'kwh' => round($kwh, 2),
                'yen' => $plugTotal > 0 ? (int)round($now['plug_yen'] * $kwh / $plugTotal) : 0,
            ];
        }

        $row = [
            'ym'            => $p['ym'],
            'start'         => $p['start'],
            'end'           => $p['end'],
            'days'          => $days,
            'elapsed_days'  => $elapsed,
            'in_progress'   => $p['progress'],
            'house_kwh'     => round($houseKwh, 1),
            'plug_kwh'      => round($plugTotal, 2),
            'plug_share'    => $houseKwh > 0 ? round($plugTotal / $houseKwh * 100, 1) : null,
            'plug_yen'      => $now['plug_yen'],
            'marginal_rate' => $now['marginal_rate'],
            'bill_yen'      => $now['bill_yen'],
            'actual_yen'    => $p['actual'],
            'rate_exact'    => $rate['exact'],
            'devices'       => $perDevice,
            'projection'    => null,
        ];

        // 進行中の期間は期末まで日割りで伸ばした見込みも返す。
        // 途中経過だけを見ると段階の位置が確定しておらず限界単価が実態より低く出るため。
        if ($p['progress'] && $elapsed > 0 && $days > $elapsed) {
            $plugProj = $plugTotal / $elapsed * $days;
            $houseProj = $p['baseline'] * $days + $plugProj;
            $proj = tapo_calc_cost($tiers, $rate, $base, $houseProj, $plugProj);
            $row['projection'] = [
                'house_kwh'     => round($houseProj, 1),
                'plug_kwh'      => round($plugProj, 2),
                'plug_yen'      => $proj['plug_yen'],
                'marginal_rate' => $proj['marginal_rate'],
                'bill_yen'      => $proj['bill_yen'],
            ];
        }

        $out[] = $row;
    }

    $memo = $out;
    return $out;
}

function handle_cost(mysqli $mysqli): void {
    echo json_encode(['periods' => tapo_cost_periods($mysqli)]);
}

/**
 * 日付 → 限界単価(円/kWh) の対応表を [{start, end, rate}, ...] で返す。
 *
 * 日別・月別グラフの金額換算に使う。一律の定数を掛けるのは実態と合わない:
 *   - 段階制なので、家全体の使用量が多い期間ほどプラグ分は高い段階に乗る
 *   - 燃料費調整額が月ごとに大きく動く（2026年は -7.19 → -10.27 で3円以上振れた）
 * 進行中の期間は期末見込みの単価を使う（途中経過だと段階の位置が確定しないため）。
 */
function tapo_rate_table(mysqli $mysqli): array {
    $table = [];
    foreach (tapo_cost_periods($mysqli) as $p) {
        $rate = $p['projection']['marginal_rate'] ?? $p['marginal_rate'];
        if ($rate !== null) {
            $table[] = ['start' => $p['start'], 'end' => $p['end'], 'rate' => (float)$rate];
        }
    }
    return $table;
}

/**
 * 指定日に適用する限界単価を返す。検針期間が登録されていない過去・未来は
 * 最も近い期間の単価で外挿する（期間が1つも無ければ null）。
 */
function tapo_rate_for_date(array $table, string $date): ?float {
    if (!$table) {
        return null;
    }
    foreach ($table as $t) {
        if ($date >= $t['start'] && $date <= $t['end']) {
            return $t['rate'];
        }
    }
    if ($date < $table[0]['start']) {
        return $table[0]['rate'];
    }
    return $table[count($table) - 1]['rate'];
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
    case 'monthly':
        handle_monthly($mysqli);
        break;
    case 'cost':
        handle_cost($mysqli);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'invalid type']);
}

$mysqli->close();
