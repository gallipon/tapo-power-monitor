<?php
/**
 * 電力モニター ダッシュボード（セッション認証必須）
 */
require_once __DIR__ . '/auth.php';

tapo_force_https();
tapo_require_auth(false);

$mysqli = getDbConnection();
$devices = [];
if ($mysqli) {
    $res = $mysqli->query('SELECT device_key, name FROM devices ORDER BY device_key');
    while ($row = $res->fetch_assoc()) {
        $devices[] = $row;
    }
    $mysqli->close();
}

// 推定電気代の単価(円/kWh)のフォールバック。Apache の SetEnv TAPO_YEN_PER_KWH で変更可。
// 検針票が billing_period に登録されていれば api/data.php が検針期間ごとの限界単価を
// 返すため、通常この値は使われない（下の YEN_PER_KWH_FALLBACK を参照）。
// 未設定時は東京(TEPCO従量電灯B 第2段階相当)の目安 36円/kWh。
$yen_per_kwh = (float)(getenv('TAPO_YEN_PER_KWH') ?: 36);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>電力モニター</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/hammerjs@2.0.8/hammer.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>
<style>
  :root {
    color-scheme: light;
    --page-plane: #f9f9f7;
    --surface-1: #fcfcfb;
    --text-primary: #0b0b0b;
    --text-secondary: #52514e;
    --text-muted: #898781;
    --gridline: #e1e0d9;
    --baseline: #c3c2b7;
    --border: rgba(11,11,11,0.10);
    --series-plug1: #2a78d6;
    --series-plug2: #008300;
    --series-plug3: #e87ba4;
    --status-good: #0ca30c;
  }
  @media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
      color-scheme: dark;
      --page-plane: #0d0d0d;
      --surface-1: #1a1a19;
      --text-primary: #ffffff;
      --text-secondary: #c3c2b7;
      --text-muted: #898781;
      --gridline: #2c2c2a;
      --baseline: #383835;
      --border: rgba(255,255,255,0.10);
      --series-plug1: #3987e5;
      --series-plug2: #008300;
      --series-plug3: #d55181;
      --status-good: #0ca30c;
    }
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    background: var(--page-plane);
    color: var(--text-primary);
    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
  }
  header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
  }
  header h1 {
    font-size: 1.1rem;
    font-weight: 600;
    margin: 0;
  }
  header a {
    color: var(--text-secondary);
    font-size: 0.85rem;
    text-decoration: none;
  }
  header a:hover { color: var(--text-primary); }

  main {
    max-width: 1080px;
    margin: 0 auto;
    padding: 20px 16px 48px;
  }

  section { margin-bottom: 32px; }
  section > h2 {
    font-size: 0.95rem;
    font-weight: 600;
    margin: 0 0 12px;
    color: var(--text-primary);
  }

  /* 現在の電力カード */
  .cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
  }
  .card {
    background: var(--surface-1);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px 18px;
  }
  .card .name {
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: 6px;
  }
  .card .value {
    font-size: 2rem;
    font-weight: 600;
    line-height: 1.1;
  }
  .card .value .unit {
    font-size: 1rem;
    font-weight: 400;
    color: var(--text-secondary);
    margin-left: 4px;
  }
  .card .sub {
    margin-top: 8px;
    font-size: 0.85rem;
    color: var(--text-secondary);
  }
  .card .status {
    margin-top: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.78rem;
    color: var(--text-muted);
  }
  .status .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--text-muted);
    flex: none;
  }
  .status.online .dot { background: var(--status-good); }
  .status.online .status-label { color: var(--text-secondary); }
  .card.offline .value { color: var(--text-muted); font-size: 1.4rem; }

  /* タブ（範囲切替） */
  .tabs {
    display: inline-flex;
    gap: 4px;
    background: var(--surface-1);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 3px;
    margin-bottom: 12px;
  }
  .tabs button {
    border: none;
    background: transparent;
    color: var(--text-secondary);
    font-size: 0.82rem;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-family: inherit;
  }
  .tabs button.active {
    background: var(--page-plane);
    color: var(--text-primary);
    font-weight: 600;
  }

  /* アーカイブ（粗い履歴）由来のデータが混ざっているときの注記 */
  .archive-note {
    margin-top: 8px;
    font-size: 0.75rem;
    color: var(--text-muted);
    line-height: 1.5;
  }
  .archive-note:empty { display: none; }

  .chart-card {
    background: var(--surface-1);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px 18px 12px;
  }
  .chart-wrap { position: relative; height: 320px; }
  .chart-wrap.short { height: 260px; }

  .cost-summary {
    font-size: 0.9rem;
    color: var(--text-secondary);
    margin: 0 0 10px;
  }

  details.table-toggle { margin-top: 10px; }
  details.table-toggle summary {
    cursor: pointer;
    font-size: 0.8rem;
    color: var(--text-secondary);
  }
  .data-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
    font-size: 0.78rem;
    font-variant-numeric: tabular-nums;
  }
  .data-table th, .data-table td {
    text-align: right;
    padding: 4px 8px;
    border-bottom: 1px solid var(--gridline);
    color: var(--text-secondary);
  }
  .data-table th:first-child, .data-table td:first-child { text-align: left; }
  .data-table-scroll { max-height: 220px; overflow: auto; }

  footer {
    text-align: center;
    color: var(--text-muted);
    font-size: 0.75rem;
    padding: 8px 0 24px;
  }
</style>
</head>
<body>
<header>
  <h1>電力モニター</h1>
  <a href="logout.php">ログアウト</a>
</header>

<main>
  <section>
    <h2>現在の電力</h2>
    <div class="cards" id="cards">
      <?php foreach ($devices as $d): ?>
        <div class="card" data-device="<?php echo htmlspecialchars($d['device_key'], ENT_QUOTES, 'UTF-8'); ?>">
          <div class="name"><?php echo htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8'); ?></div>
          <div class="value">--<span class="unit">W</span></div>
          <div class="sub">今日: -- kWh</div>
          <div class="status"><span class="dot"></span><span class="status-label">読み込み中</span></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section>
    <h2>瞬時電力</h2>
    <div class="tabs" id="power-tabs">
      <button data-hours="1" class="active">1時間</button>
      <button data-hours="6">6時間</button>
      <button data-hours="24">24時間</button>
    </div>
    <div class="chart-card">
      <div class="chart-wrap"><canvas id="powerChart"></canvas></div>
      <details class="table-toggle">
        <summary>データを表で見る</summary>
        <div class="data-table-scroll">
          <table class="data-table" id="powerTable"></table>
        </div>
      </details>
    </div>
  </section>

  <section>
    <h2>時間別 電力量</h2>
    <div class="tabs" id="hourly-tabs">
      <button data-days="1" class="active">今日</button>
      <button data-days="7">7日</button>
    </div>
    <div class="chart-card">
      <div class="chart-wrap short"><canvas id="hourlyChart"></canvas></div>
      <details class="table-toggle">
        <summary>データを表で見る</summary>
        <div class="data-table-scroll">
          <table class="data-table" id="hourlyTable"></table>
        </div>
      </details>
    </div>
  </section>

  <section>
    <h2>日別 電力量</h2>
    <div class="tabs" id="daily-tabs">
      <button data-days="30" class="active">30日</button>
      <button data-days="90">90日</button>
      <button data-days="366">1年</button>
    </div>
    <div class="chart-card">
      <div id="dailyCostSummary" class="cost-summary"></div>
      <div class="chart-wrap short"><canvas id="dailyChart"></canvas></div>
      <div id="dailyArchiveNote" class="archive-note"></div>
      <details class="table-toggle">
        <summary>データを表で見る</summary>
        <div class="data-table-scroll">
          <table class="data-table" id="dailyTable"></table>
        </div>
      </details>
    </div>
  </section>

  <section>
    <h2>月別 電力量</h2>
    <div class="tabs" id="monthly-tabs">
      <button data-months="12" class="active">12ヶ月</button>
      <button data-months="24">24ヶ月</button>
    </div>
    <div class="chart-card">
      <div id="monthlyCostSummary" class="cost-summary"></div>
      <div class="chart-wrap short"><canvas id="monthlyChart"></canvas></div>
      <div id="monthlyArchiveNote" class="archive-note"></div>
      <details class="table-toggle">
        <summary>データを表で見る</summary>
        <div class="data-table-scroll">
          <table class="data-table" id="monthlyTable"></table>
        </div>
      </details>
    </div>
  </section>

  <section>
    <h2>電気代（検針期間ごと）</h2>
    <div class="chart-card">
      <div id="costSummary" class="cost-summary"></div>
      <div class="data-table-scroll">
        <table class="data-table" id="costTable"></table>
      </div>
      <div id="costNote" class="archive-note"></div>
    </div>
  </section>
</main>

<footer>Tapo P110M 電力モニター</footer>

<script>
const DEVICES = <?php echo json_encode($devices, JSON_UNESCAPED_UNICODE); ?>;
// 単価のフォールバック。検針票が1件も登録されていない場合にのみ使う。
// 通常は api/data.php が検針期間ごとに算出した限界単価（rate_ref / 各点の yen）を使う。
const YEN_PER_KWH_FALLBACK = <?php echo json_encode($yen_per_kwh); ?>;
const OFFLINE_THRESHOLD_MS = 15 * 60 * 1000; // collectorの送信間隔(10分)に余裕を見た閾値

// グラフの右軸(円)に使う代表単価。data.php の rate_ref（＝現在の検針期間の限界単価）で上書きされる。
// 右軸は左軸(kWh)の定数倍として描くため、点ごとに違う単価は使えず代表値が要る。
let YEN_PER_KWH = YEN_PER_KWH_FALLBACK;

// kWh を推定電気代(円)に換算。四捨五入した整数円で返す。
function yen(kwh) { return Math.round(kwh * YEN_PER_KWH); }
function fmtYen(kwh) { return '¥' + yen(kwh).toLocaleString('ja-JP'); }
// 円が確定している点はその値を、無ければ代表単価で換算する
function fmtYenPoint(p, kwh) {
  return p && p.yen !== null && p.yen !== undefined
    ? '¥' + Math.round(p.yen).toLocaleString('ja-JP')
    : fmtYen(kwh);
}

const PALETTE = {
  light: { plug1: '#2a78d6', plug2: '#008300', plug3: '#e87ba4' },
  dark:  { plug1: '#3987e5', plug2: '#008300', plug3: '#d55181' }
};

function currentTheme() {
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}
function seriesColor(deviceKey) {
  return (PALETTE[currentTheme()][deviceKey]) || '#898781';
}
function cssVar(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}
function deviceName(key) {
  const d = DEVICES.find(x => x.device_key === key);
  return d ? d.name : key;
}
/* "YYYY-MM-DD HH:mm:ss" / "YYYY-MM-DD" をローカル時刻のDateとしてパースする。
   Safari等はスペース区切りの日時文字列を解釈できないため 'T' 区切りに正規化し、
   日付のみの文字列はUTC解釈によるずれを避けるため T00:00:00 を明示的に付与する。 */
function toLocalDate(dateStr) {
  if (dateStr instanceof Date) return dateStr;
  const s = dateStr.length === 10 ? dateStr + 'T00:00:00' : dateStr.replace(' ', 'T');
  return new Date(s);
}
function fmtHM(dateVal) {
  return toLocalDate(dateVal).toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
}
function fmtMD(dateVal) {
  const d = toLocalDate(dateVal);
  return (d.getMonth() + 1) + '/' + d.getDate();
}

/* 垂直クロスヘア（アクティブなツールチップ位置に縦線を描く） */
const crosshairPlugin = {
  id: 'crosshair',
  afterDraw(chart) {
    const active = chart.tooltip && chart.tooltip.getActiveElements ? chart.tooltip.getActiveElements() : [];
    if (!active || !active.length) return;
    const x = active[0].element.x;
    const { top, bottom } = chart.chartArea;
    const ctx = chart.ctx;
    ctx.save();
    ctx.beginPath();
    ctx.moveTo(x, top);
    ctx.lineTo(x, bottom);
    ctx.lineWidth = 1;
    ctx.strokeStyle = cssVar('--baseline');
    ctx.stroke();
    ctx.restore();
  }
};
Chart.register(crosshairPlugin);

// chartjs-plugin-zoom は <script> 読み込み時に自身を自動登録するが、
// 読み込み順の差異があっても確実に有効化されるよう明示的にも登録しておく。
if (window.ChartZoom) {
  Chart.register(window.ChartZoom);
}

function baseScales(extra) {
  const grid = cssVar('--gridline');
  const muted = cssVar('--text-muted');
  return Object.assign({
    x: {
      grid: { color: grid, drawTicks: false },
      ticks: { color: muted, maxRotation: 0, autoSkipPadding: 12 },
      border: { color: cssVar('--baseline') }
    },
    y: {
      beginAtZero: true,
      grid: { color: grid, drawTicks: false },
      ticks: { color: muted },
      border: { display: false }
    }
  }, extra || {});
}

function baseLegend() {
  return {
    position: 'top',
    align: 'start',
    labels: {
      color: cssVar('--text-secondary'),
      usePointStyle: true,
      pointStyle: 'line',
      boxWidth: 24,
      padding: 16,
      font: { size: 12 }
    }
  };
}

function baseTooltip() {
  return {
    mode: 'index',
    intersect: false,
    backgroundColor: cssVar('--surface-1'),
    titleColor: cssVar('--text-primary'),
    bodyColor: cssVar('--text-primary'),
    borderColor: cssVar('--border'),
    borderWidth: 1,
    padding: 10,
    boxPadding: 4,
    usePointStyle: true
  };
}

/* ---------- 現在の電力カード ---------- */
async function loadCurrent() {
  try {
    const res = await fetch('api/data.php?type=current');
    if (!res.ok) throw new Error('http ' + res.status);
    const data = await res.json();
    const now = Date.now();

    data.devices.forEach(d => {
      const card = document.querySelector('.card[data-device="' + CSS.escape(d.device_key) + '"]');
      if (!card) return;

      const valueEl = card.querySelector('.value');
      const subEl = card.querySelector('.sub');
      const statusEl = card.querySelector('.status');
      const labelEl = statusEl.querySelector('.status-label');

      const isFresh = d.ts && (now - toLocalDate(d.ts).getTime()) < OFFLINE_THRESHOLD_MS;

      if (isFresh) {
        card.classList.remove('offline');
        valueEl.innerHTML = Number(d.power_w).toFixed(1) + '<span class="unit">W</span>';
        subEl.textContent = '今日: ' + (d.today_wh / 1000).toFixed(2) + ' kWh';
        statusEl.classList.add('online');
        labelEl.textContent = 'オンライン（' + fmtHM(d.ts) + ' 更新）';
      } else {
        card.classList.add('offline');
        valueEl.textContent = 'オフライン';
        subEl.textContent = d.ts ? ('今日: ' + (d.today_wh / 1000).toFixed(2) + ' kWh') : 'データなし';
        statusEl.classList.remove('online');
        labelEl.textContent = d.ts ? ('最終更新 ' + fmtHM(d.ts)) : '未受信';
      }
    });
  } catch (e) {
    // 次の自動更新で回復を試みる（画面は直前の値を保持）
    console.error('loadCurrent failed', e);
  }
}

/* ---------- 瞬時電力（折れ線、pan/zoom + 過去への遅延ロード対応） ---------- */
let powerChart = null;
let currentPowerHours = 1;

// 内部状態: デバイスごとの時系列（{x:Date, y:number} 昇順）を保持し、
// パンで左端に近づいたら data.php?from=&to= で過去分を追加取得して先頭にprependする。
let powerDeviceKeys = [];      // 系列キーの表示順
let powerSeriesData = {};     // device_key -> [{x:Date, y:number}, ...]
let oldestLoadedTs = null;    // ロード済みの最古境界（ms epoch）。要求済み境界であり実データの有無は問わない
let isLoading = false;        // 多重ロード防止
let reachedStart = false;     // これ以上過去データが無いことが判明したらtrue
let powerLazyCheckTimer = null;

const LAZY_CHUNK_MS = 6 * 60 * 60 * 1000;   // 遅延ロード1回分のチャンク幅（6時間）
const PAN_LOAD_THRESHOLD_RATIO = 0.2;       // 可視範囲の左端が残り20%以内に入ったら追加ロード

/* Date -> "YYYY-MM-DD HH:MM:SS"（toLocalDateの逆変換。ローカル時刻=JST運用を前提とする） */
function toJstParam(date) {
  const pad = (n) => String(n).padStart(2, '0');
  return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) + ' ' +
         pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
}

function powerDatasetsFromState() {
  return powerDeviceKeys.map(key => ({
    label: deviceName(key),
    __deviceKey: key,
    data: powerSeriesData[key].map(p => ({ x: p.x, y: p.y })),
    borderColor: seriesColor(key),
    backgroundColor: seriesColor(key),
    borderWidth: 2,
    pointRadius: 0,
    pointHoverRadius: 4,
    pointHitRadius: 12,
    tension: 0.15
  }));
}

function powerSeriesForTable() {
  const out = {};
  powerDeviceKeys.forEach(key => {
    out[key] = powerSeriesData[key].map(p => ({ ts: toJstParam(p.x), power_w: p.y }));
  });
  return out;
}

function renderPowerTable(series) {
  const table = document.getElementById('powerTable');
  const keys = Object.keys(series);
  let thead = '<thead><tr><th>時刻</th>' + keys.map(k => '<th>' + deviceName(k) + ' (W)</th>').join('') + '</tr></thead>';

  const tsSet = new Set();
  keys.forEach(k => series[k].forEach(p => tsSet.add(p.ts)));
  const tsList = Array.from(tsSet).sort();

  const maps = {};
  keys.forEach(k => {
    maps[k] = new Map(series[k].map(p => [p.ts, p.power_w]));
  });

  let rows = tsList.map(ts => {
    const cells = keys.map(k => '<td>' + (maps[k].has(ts) ? maps[k].get(ts).toFixed(1) : '-') + '</td>');
    return '<tr><td>' + fmtHM(ts) + '</td>' + cells.join('') + '</tr>';
  }).join('');

  table.innerHTML = thead + '<tbody>' + rows + '</tbody>';
}

/* パン/ズーム操作の完了コールバック。連続発火するので軽くデバウンスする */
function schedulePowerLazyCheck(chart) {
  clearTimeout(powerLazyCheckTimer);
  powerLazyCheckTimer = setTimeout(() => { maybeLoadOlderPower(chart); }, 150);
}

async function maybeLoadOlderPower(chart) {
  if (isLoading || reachedStart || oldestLoadedTs === null) return;
  const xScale = chart.scales.x;
  if (!xScale) return;
  const visibleMin = xScale.min;
  const visibleMax = xScale.max;
  const span = visibleMax - visibleMin;
  if (!(span > 0)) return;

  // 可視範囲の左端がロード済み最古境界にどれだけ近いか（残りが20%未満なら追加ロード）
  const remaining = visibleMin - oldestLoadedTs;
  if (remaining <= span * PAN_LOAD_THRESHOLD_RATIO) {
    await loadOlderPower();
  }
}

async function loadOlderPower() {
  if (isLoading || reachedStart || oldestLoadedTs === null || !powerChart) return;
  isLoading = true;
  try {
    const toMs = oldestLoadedTs;
    const fromMs = toMs - LAZY_CHUNK_MS;
    const fromStr = toJstParam(new Date(fromMs));
    const toStr = toJstParam(new Date(toMs));

    const res = await fetch('api/data.php?type=power&device=all&from=' + encodeURIComponent(fromStr) + '&to=' + encodeURIComponent(toStr));
    if (!res.ok) throw new Error('http ' + res.status);
    const data = await res.json();

    let gotAny = false;
    powerDeviceKeys.forEach(key => {
      const newPoints = (data.series[key] || []).map(p => ({ x: toLocalDate(p.ts), y: p.power_w }));
      if (newPoints.length) gotAny = true;
      const existing = powerSeriesData[key];
      // 既存最古と重複する時刻は除外してから先頭にprepend（時刻昇順を維持）
      const existingOldestTime = existing.length ? existing[0].x.getTime() : Infinity;
      const dedup = newPoints.filter(p => p.x.getTime() < existingOldestTime);
      powerSeriesData[key] = dedup.concat(existing);
    });

    if (!gotAny) {
      reachedStart = true; // これより過去のデータは無い
    } else {
      oldestLoadedTs = fromMs;
    }

    // 現在の表示範囲（パン/ズーム位置）を保ったままデータだけ差し替える
    const xScale = powerChart.scales.x;
    const curMin = xScale.min;
    const curMax = xScale.max;
    powerChart.data.datasets.forEach(ds => {
      ds.data = powerSeriesData[ds.__deviceKey].map(p => ({ x: p.x, y: p.y }));
    });
    powerChart.options.scales.x.min = curMin;
    powerChart.options.scales.x.max = curMax;
    powerChart.update('none'); // アニメーション無しで即時反映し、表示位置のジャンプを防ぐ
    renderPowerTable(powerSeriesForTable());
  } catch (e) {
    console.error('loadOlderPower failed', e);
  } finally {
    isLoading = false;
  }
}

/* 1h/6h/24h タブ = 初期表示範囲プリセット。押下でデータ・oldestLoadedTs・パン位置を初期化する。
   なお瞬時電力チャートは pan/zoom で表示範囲をユーザーが自由に動かせるため、
   自動更新（setInterval）はここでは行わない。カード（現在の電力）側の30秒更新のみ既存どおり継続する。 */
async function loadPower(hours) {
  currentPowerHours = hours;
  try {
    const res = await fetch('api/data.php?type=power&hours=' + hours + '&device=all');
    if (!res.ok) throw new Error('http ' + res.status);
    const data = await res.json();

    const nowMs = Date.now();
    const sinceMs = nowMs - hours * 3600 * 1000;

    powerDeviceKeys = Object.keys(data.series);
    powerSeriesData = {};
    powerDeviceKeys.forEach(key => {
      powerSeriesData[key] = data.series[key].map(p => ({ x: toLocalDate(p.ts), y: p.power_w }));
    });
    oldestLoadedTs = sinceMs;
    reachedStart = false;
    isLoading = false;

    const datasets = powerDatasetsFromState();

    if (powerChart) {
      powerChart.data.datasets = datasets;
      powerChart.options.scales.x.time.unit = hours === 24 ? 'hour' : 'minute';
      // min/max を明示設定して update すれば新しい窓が反映される。
      // resetZoom() はプラグインが記憶した「初回レンダリング時の範囲(=最初の1h)」に
      // 戻してしまい、プリセット切替が効かなくなるため呼ばない。
      powerChart.options.scales.x.min = sinceMs;
      powerChart.options.scales.x.max = nowMs;
      powerChart.update();
    } else {
      const ctx = document.getElementById('powerChart').getContext('2d');
      powerChart = new Chart(ctx, {
        type: 'line',
        data: { datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: baseLegend(),
            tooltip: Object.assign(baseTooltip(), {
              callbacks: {
                title: (items) => items.length ? fmtHM(items[0].raw.x) : '',
                label: (item) => item.dataset.label + ': ' + item.formattedValue + ' W'
              }
            }),
            zoom: {
              pan: {
                enabled: true,
                mode: 'x',
                onPanComplete: ({ chart }) => schedulePowerLazyCheck(chart)
              },
              zoom: {
                wheel: { enabled: true },
                pinch: { enabled: true },
                mode: 'x',
                onZoomComplete: ({ chart }) => schedulePowerLazyCheck(chart)
              }
            }
          },
          scales: baseScales({
            x: {
              type: 'time',
              time: { unit: hours === 24 ? 'hour' : 'minute' },
              min: sinceMs,
              max: nowMs,
              grid: { color: cssVar('--gridline'), drawTicks: false },
              ticks: { color: cssVar('--text-muted'), maxRotation: 0, autoSkipPadding: 12 }
            },
            y: { title: { display: true, text: 'W', color: cssVar('--text-muted') } }
          })
        }
      });
      // ダブルクリックで現在選択中のプリセット窓(直近 currentPowerHours 時間)に戻す。
      // resetZoom() は初回レンダリング時の範囲に戻る癖があるため使わず、min/max を再適用する。
      document.getElementById('powerChart').addEventListener('dblclick', () => {
        if (!powerChart) return;
        const nowMs = Date.now();
        powerChart.options.scales.x.min = nowMs - currentPowerHours * 3600 * 1000;
        powerChart.options.scales.x.max = nowMs;
        powerChart.update();
      });
    }
    renderPowerTable(powerSeriesForTable());
  } catch (e) {
    console.error('loadPower failed', e);
  }
}

/* ---------- 時間別 電力量（棒・積み上げ） ---------- */
let hourlyChart = null;

function renderHourlyTable(series) {
  const table = document.getElementById('hourlyTable');
  const keys = Object.keys(series);
  let thead = '<thead><tr><th>時刻</th>' + keys.map(k => '<th>' + deviceName(k) + ' (Wh)</th>').join('') + '</tr></thead>';

  const tsSet = new Set();
  keys.forEach(k => series[k].forEach(p => tsSet.add(p.hour_start)));
  const tsList = Array.from(tsSet).sort();

  const maps = {};
  keys.forEach(k => { maps[k] = new Map(series[k].map(p => [p.hour_start, p.wh])); });

  let rows = tsList.map(ts => {
    const cells = keys.map(k => '<td>' + (maps[k].has(ts) ? maps[k].get(ts) : '-') + '</td>');
    return '<tr><td>' + fmtHM(ts) + '</td>' + cells.join('') + '</tr>';
  }).join('');

  table.innerHTML = thead + '<tbody>' + rows + '</tbody>';
}

async function loadHourly(days) {
  try {
    const res = await fetch('api/data.php?type=hourly&days=' + days);
    if (!res.ok) throw new Error('http ' + res.status);
    const data = await res.json();

    const datasets = Object.keys(data.series).map(key => ({
      label: deviceName(key),
      data: data.series[key].map(p => ({ x: toLocalDate(p.hour_start), y: p.wh })),
      backgroundColor: seriesColor(key),
      borderRadius: 4,
      borderSkipped: 'bottom',
      maxBarThickness: 24,
      stack: 'wh'
    }));

    if (hourlyChart) {
      hourlyChart.data.datasets = datasets;
      hourlyChart.options.scales.x.time.unit = days === 1 ? 'hour' : 'day';
      hourlyChart.update();
    } else {
      const ctx = document.getElementById('hourlyChart').getContext('2d');
      hourlyChart = new Chart(ctx, {
        type: 'bar',
        data: { datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: baseLegend(),
            tooltip: Object.assign(baseTooltip(), {
              callbacks: {
                title: (items) => items.length ? fmtHM(items[0].raw.x) : '',
                label: (item) => item.dataset.label + ': ' + item.formattedValue + ' Wh'
              }
            })
          },
          scales: baseScales({
            x: {
              type: 'time',
              time: { unit: days === 1 ? 'hour' : 'day' },
              stacked: true,
              grid: { color: cssVar('--gridline'), drawTicks: false },
              ticks: { color: cssVar('--text-muted'), maxRotation: 0, autoSkipPadding: 12 }
            },
            y: { stacked: true, title: { display: true, text: 'Wh', color: cssVar('--text-muted') } }
          })
        }
      });
    }
    renderHourlyTable(data.series);
  } catch (e) {
    console.error('loadHourly failed', e);
  }
}

/* ---------- 日別 電力量（積み上げ） ----------

   点ごとに src が付く:
     hourly  … energy_hourly（1分収集の実データを時間別に積んだもの）
     archive … energy_daily（収集開始前の期間。デバイス本体の日別統計や
               Tapoアプリのデータエクスポート由来で、粒度が粗い）
   アーカイブ由来のバーは半透明にして、由来が違うことが一目で分かるようにする。 */
let dailyChart = null;

/** 'rgba' 化して透過させる（アーカイブ由来のバー用） */
function fadeColor(hex, alpha) {
  const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
  if (!m) return hex;
  const [r, g, b] = [1, 2, 3].map(i => parseInt(m[i], 16));
  return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
}

/** アーカイブ由来の点数を数え、注記を出す（0なら空にして非表示） */
function renderArchiveNote(elementId, series, unitLabel) {
  let n = 0;
  Object.keys(series).forEach(k => series[k].forEach(p => { if (p.src !== 'hourly' && p.src !== 'daily') n++; }));
  document.getElementById(elementId).textContent = n === 0 ? ''
    : '半透明の' + n + unitLabel + 'は、収集開始前の期間をデバイス本体の統計やTapoアプリの'
      + 'データエクスポートから復元した値です（元データの粒度が粗く、実測との誤差が数%あります）。';
}

function renderDailyTable(series) {
  const table = document.getElementById('dailyTable');
  const keys = Object.keys(series);
  let thead = '<thead><tr><th>日付</th>' + keys.map(k => '<th>' + deviceName(k) + ' (kWh)</th>').join('') + '</tr></thead>';

  const dateSet = new Set();
  keys.forEach(k => series[k].forEach(p => dateSet.add(p.date)));
  const dateList = Array.from(dateSet).sort();

  const maps = {};
  keys.forEach(k => { maps[k] = new Map(series[k].map(p => [p.date, p])); });

  let rows = dateList.map(d => {
    const cells = keys.map(k => {
      if (!maps[k].has(d)) return '<td>-</td>';
      const p = maps[k].get(d);
      // アーカイブ由来は * を付けて実測と区別できるようにする
      return '<td>' + (p.wh / 1000).toFixed(2) + (p.src === 'hourly' ? '' : ' *') + '</td>';
    });
    return '<tr><td>' + fmtMD(d) + '</td>' + cells.join('') + '</tr>';
  }).join('');

  table.innerHTML = thead + '<tbody>' + rows + '</tbody>';
}

async function loadDaily(days) {
  try {
    const res = await fetch('api/data.php?type=daily&days=' + days);
    if (!res.ok) throw new Error('http ' + res.status);
    const data = await res.json();
    if (data.rate_ref) YEN_PER_KWH = data.rate_ref;

    const datasets = Object.keys(data.series).map(key => {
      const color = seriesColor(key);
      return {
        label: deviceName(key),
        data: data.series[key].map(p => ({ x: toLocalDate(p.date), y: p.wh / 1000, yen: p.yen })),
        backgroundColor: data.series[key].map(p => p.src === 'hourly' ? color : fadeColor(color, 0.45)),
        borderRadius: 4,
        borderSkipped: 'bottom',
        maxBarThickness: 20,
        stack: 'kwh'
      };
    });

    // 期間合計の kWh と電気代。金額は各日が属する検針期間の限界単価で換算済みの値を積む。
    let totalWh = 0, totalYen = 0, exact = true;
    Object.keys(data.series).forEach(key => {
      data.series[key].forEach(p => {
        totalWh += p.wh;
        if (p.yen === null || p.yen === undefined) { exact = false; } else { totalYen += p.yen; }
      });
    });
    const totalKwh = totalWh / 1000;
    document.getElementById('dailyCostSummary').textContent =
      '期間合計: ' + totalKwh.toFixed(1) + ' kWh ／ 推定 '
      + (exact ? '¥' + Math.round(totalYen).toLocaleString('ja-JP') : fmtYen(totalKwh))
      + (exact ? '（検針期間ごとの限界単価で換算）' : '（' + YEN_PER_KWH + '円/kWh 換算）');
    renderArchiveNote('dailyArchiveNote', data.series, '日分');

    if (dailyChart) {
      dailyChart.data.datasets = datasets;
      dailyChart.update();
    } else {
      const ctx = document.getElementById('dailyChart').getContext('2d');
      dailyChart = new Chart(ctx, {
        type: 'bar',
        data: { datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: baseLegend(),
            tooltip: Object.assign(baseTooltip(), {
              callbacks: {
                title: (items) => items.length ? fmtMD(items[0].raw.x) : '',
                label: (item) => item.dataset.label + ': ' + item.formattedValue + ' kWh（'
                  + fmtYenPoint(item.raw, item.parsed.y) + '）',
                // その日の合計(積み上げ全系列)の推定電気代をフッターに出す
                footer: (items) => {
                  const totalKwh = items.reduce((s, it) => s + (it.parsed.y || 0), 0);
                  const known = items.every(it => it.raw.yen !== null && it.raw.yen !== undefined);
                  const totalYen = known
                    ? '¥' + Math.round(items.reduce((s, it) => s + it.raw.yen, 0)).toLocaleString('ja-JP')
                    : fmtYen(totalKwh);
                  return '合計: ' + totalKwh.toFixed(2) + ' kWh / ' + totalYen;
                }
              }
            })
          },
          scales: baseScales({
            x: {
              type: 'time',
              time: { unit: 'day' },
              stacked: true,
              grid: { color: cssVar('--gridline'), drawTicks: false },
              ticks: { color: cssVar('--text-muted'), maxRotation: 0, autoSkipPadding: 12 }
            },
            y: { stacked: true, title: { display: true, text: 'kWh', color: cssVar('--text-muted') } },
            // 右軸(円)。データは持たせず、左軸(y)の目盛り位置と範囲をそのままコピーして
            // 円に読み替える。電気代 = kWh × 定数 なので左軸の単純な定数倍で完全に揃う。
            yYen: {
              position: 'right',
              stacked: true,
              grid: { drawOnChartArea: false },
              title: { display: true, text: '円', color: cssVar('--text-muted') },
              afterBuildTicks: (axis) => {
                const y = axis.chart.scales.y;
                if (!y) return;
                axis.min = y.min;
                axis.max = y.max;
                axis.ticks = y.ticks.map(t => ({ value: t.value }));
              },
              ticks: {
                color: cssVar('--text-muted'),
                callback: (v) => '¥' + Math.round(v * YEN_PER_KWH).toLocaleString('ja-JP')
              }
            }
          })
        }
      });
    }
    renderDailyTable(data.series);
  } catch (e) {
    console.error('loadDaily failed', e);
  }
}

/* ---------- 月別 電力量（積み上げ） ----------

   点ごとの src:
     daily   … 日別データがその月を丸ごと埋めている（実測ベース。進行中の月も含む）
     archive … energy_monthly（デバイス本体の月別統計 / エクスポート由来）
     partial … 月の一部しかデータが無く、月次アーカイブも無い（過小評価） */
let monthlyChart = null;

function fmtYM(dateVal) {
  const d = toLocalDate(dateVal);
  return d.getFullYear() + '/' + String(d.getMonth() + 1).padStart(2, '0');
}

function renderMonthlyTable(series) {
  const table = document.getElementById('monthlyTable');
  const keys = Object.keys(series);
  let thead = '<thead><tr><th>月</th>' + keys.map(k => '<th>' + deviceName(k) + ' (kWh)</th>').join('') + '</tr></thead>';

  const monthSet = new Set();
  keys.forEach(k => series[k].forEach(p => monthSet.add(p.month)));
  const monthList = Array.from(monthSet).sort();

  const maps = {};
  keys.forEach(k => { maps[k] = new Map(series[k].map(p => [p.month, p])); });

  const rows = monthList.map(m => {
    const cells = keys.map(k => {
      if (!maps[k].has(m)) return '<td>-</td>';
      const p = maps[k].get(m);
      return '<td>' + (p.wh / 1000).toFixed(2) + (p.src === 'daily' ? '' : ' *') + '</td>';
    });
    return '<tr><td>' + fmtYM(m) + '</td>' + cells.join('') + '</tr>';
  }).join('');

  table.innerHTML = thead + '<tbody>' + rows + '</tbody>';
}

async function loadMonthly(months) {
  try {
    const res = await fetch('api/data.php?type=monthly&months=' + months);
    if (!res.ok) throw new Error('http ' + res.status);
    const data = await res.json();
    if (data.rate_ref) YEN_PER_KWH = data.rate_ref;

    const datasets = Object.keys(data.series).map(key => {
      const color = seriesColor(key);
      return {
        label: deviceName(key),
        data: data.series[key].map(p => ({ x: toLocalDate(p.month), y: p.wh / 1000, yen: p.yen })),
        backgroundColor: data.series[key].map(p => p.src === 'daily' ? color : fadeColor(color, 0.45)),
        borderRadius: 4,
        borderSkipped: 'bottom',
        maxBarThickness: 28,
        stack: 'kwh'
      };
    });

    let totalWh = 0, totalYen = 0, exact = true;
    Object.keys(data.series).forEach(key => {
      data.series[key].forEach(p => {
        totalWh += p.wh;
        if (p.yen === null || p.yen === undefined) { exact = false; } else { totalYen += p.yen; }
      });
    });
    const totalKwh = totalWh / 1000;
    document.getElementById('monthlyCostSummary').textContent =
      '期間合計: ' + totalKwh.toFixed(1) + ' kWh ／ 推定 '
      + (exact ? '¥' + Math.round(totalYen).toLocaleString('ja-JP') : fmtYen(totalKwh))
      + (exact ? '（検針期間ごとの限界単価で換算）' : '（' + YEN_PER_KWH + '円/kWh 換算）');
    renderArchiveNote('monthlyArchiveNote', data.series, 'ヶ月分');

    if (monthlyChart) {
      monthlyChart.data.datasets = datasets;
      monthlyChart.update();
    } else {
      const ctx = document.getElementById('monthlyChart').getContext('2d');
      monthlyChart = new Chart(ctx, {
        type: 'bar',
        data: { datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: baseLegend(),
            tooltip: Object.assign(baseTooltip(), {
              callbacks: {
                title: (items) => items.length ? fmtYM(items[0].raw.x) : '',
                label: (item) => item.dataset.label + ': ' + item.formattedValue + ' kWh（'
                  + fmtYenPoint(item.raw, item.parsed.y) + '）',
                footer: (items) => {
                  const t = items.reduce((s, it) => s + (it.parsed.y || 0), 0);
                  const known = items.every(it => it.raw.yen !== null && it.raw.yen !== undefined);
                  const y = known
                    ? '¥' + Math.round(items.reduce((s, it) => s + it.raw.yen, 0)).toLocaleString('ja-JP')
                    : fmtYen(t);
                  return '合計: ' + t.toFixed(2) + ' kWh / ' + y;
                }
              }
            })
          },
          scales: baseScales({
            x: {
              type: 'time',
              time: { unit: 'month' },
              stacked: true,
              offset: true,
              grid: { color: cssVar('--gridline'), drawTicks: false },
              ticks: { color: cssVar('--text-muted'), maxRotation: 0, autoSkipPadding: 12 }
            },
            y: { stacked: true, title: { display: true, text: 'kWh', color: cssVar('--text-muted') } },
            // 日別グラフと同じく、左軸(kWh)の目盛りをそのまま円に読み替える右軸
            yYen: {
              position: 'right',
              stacked: true,
              grid: { drawOnChartArea: false },
              title: { display: true, text: '円', color: cssVar('--text-muted') },
              afterBuildTicks: (axis) => {
                const y = axis.chart.scales.y;
                if (!y) return;
                axis.min = y.min;
                axis.max = y.max;
                axis.ticks = y.ticks.map(t => ({ value: t.value }));
              },
              ticks: {
                color: cssVar('--text-muted'),
                callback: (v) => '¥' + Math.round(v * YEN_PER_KWH).toLocaleString('ja-JP')
              }
            }
          })
        }
      });
    }
    renderMonthlyTable(data.series);
  } catch (e) {
    console.error('loadMonthly failed', e);
  }
}

/* ---------- 電気代（検針期間ごと） ----------

   プラグの電気代は「そのプラグが無かったら請求がいくら減るか」= 増分コスト。
   段階料金なので単純な kWh × 単価にはならない（詳細は api/data.php を参照）。 */

function fmtYenInt(yen) { return '¥' + Math.round(yen).toLocaleString('ja-JP'); }

function fmtPeriod(p) {
  const s = toLocalDate(p.start), e = toLocalDate(p.end);
  return (s.getMonth() + 1) + '/' + s.getDate() + '〜' + (e.getMonth() + 1) + '/' + e.getDate();
}

function renderCostTable(periods) {
  const head = '<thead><tr><th>期間</th><th>日数</th><th>家全体<br>(kWh)</th>'
    + '<th>プラグ3台<br>(kWh)</th><th>占有率</th><th>プラグの<br>電気代</th>'
    + '<th>限界単価<br>(円/kWh)</th><th>請求額</th></tr></thead>';

  const rows = [];
  periods.forEach(p => {
    const label = p.in_progress ? fmtPeriod(p) + '<br><small>進行中 ' + p.elapsed_days + '/' + p.days + '日</small>'
                                : fmtPeriod(p);
    // 実請求額があればそれを、無ければモデル計算値を出す（推定は括弧付き）
    const bill = p.actual_yen !== null ? fmtYenInt(p.actual_yen) : '(' + fmtYenInt(p.bill_yen) + ')';
    rows.push('<tr><td>' + label + '</td><td>' + p.days + '</td>'
      + '<td>' + p.house_kwh.toFixed(1) + (p.in_progress ? ' *' : '') + '</td>'
      + '<td>' + p.plug_kwh.toFixed(2) + '</td>'
      + '<td>' + (p.plug_share === null ? '-' : p.plug_share.toFixed(1) + '%') + '</td>'
      + '<td>' + fmtYenInt(p.plug_yen) + '</td>'
      + '<td>' + (p.marginal_rate === null ? '-' : p.marginal_rate.toFixed(2)) + '</td>'
      + '<td>' + bill + '</td></tr>');

    if (p.projection) {
      const j = p.projection;
      rows.push('<tr><td><small>↑期末見込み</small></td><td>' + p.days + '</td>'
        + '<td>' + j.house_kwh.toFixed(1) + ' *</td>'
        + '<td>' + j.plug_kwh.toFixed(2) + '</td>'
        + '<td>' + (j.house_kwh > 0 ? (j.plug_kwh / j.house_kwh * 100).toFixed(1) + '%' : '-') + '</td>'
        + '<td>' + fmtYenInt(j.plug_yen) + '</td>'
        + '<td>' + (j.marginal_rate === null ? '-' : j.marginal_rate.toFixed(2)) + '</td>'
        + '<td>(' + fmtYenInt(j.bill_yen) + ')</td></tr>');
    }
  });

  document.getElementById('costTable').innerHTML = head + '<tbody>' + rows.join('') + '</tbody>';
}

async function loadCost() {
  try {
    const res = await fetch('api/data.php?type=cost');
    if (!res.ok) throw new Error('http ' + res.status);
    const data = await res.json();
    const periods = data.periods || [];
    if (!periods.length) {
      document.getElementById('costSummary').textContent = '検針票の実績が未登録です（billing_period）。';
      return;
    }
    renderCostTable(periods);

    // 最新期間（進行中があればそれ）のサマリー。進行中は期末見込みを主役にする。
    const cur = periods[periods.length - 1];
    const v = cur.projection || cur;
    const label = cur.in_progress ? '今期の見込み' : '直近の期間';
    const parts = Object.keys(cur.devices)
      .map(k => deviceName(k) + ' ' + fmtYenInt(cur.devices[k].yen));
    document.getElementById('costSummary').innerHTML =
      '<strong>' + label + '（' + fmtPeriod(cur) + '）</strong>　'
      + '家全体 ' + v.house_kwh.toFixed(1) + ' kWh ／ 請求 ' + fmtYenInt(v.bill_yen) + '<br>'
      + 'うちプラグ3台 ' + v.plug_kwh.toFixed(1) + ' kWh = <strong>' + fmtYenInt(v.plug_yen) + '</strong>'
      + '（限界単価 ' + (v.marginal_rate === null ? '-' : v.marginal_rate.toFixed(2)) + ' 円/kWh）'
      + (cur.in_progress ? '' : '　内訳: ' + parts.join(' / '));

    // 検針票の実績がある期間について、料金モデルの計算値と一致しているかを出す
    const checks = periods.filter(p => p.actual_yen !== null)
      .map(p => fmtPeriod(p) + ': モデル ' + fmtYenInt(p.bill_yen) + ' / 実請求 ' + fmtYenInt(p.actual_yen)
        + (p.bill_yen === p.actual_yen ? ' → 一致' : ' → 差 ' + fmtYenInt(p.bill_yen - p.actual_yen)));
    const approx = periods.some(p => !p.rate_exact);
    document.getElementById('costNote').innerHTML =
      '* は推定値（家全体の使用量は前期の「家全体 − プラグ3台」を1日あたりのベースラインとして算出）。'
      + '請求額の括弧付きは料金モデルによる試算。'
      + (checks.length ? '<br>検算 — ' + checks.join(' ／ ') : '')
      + (approx ? '<br>燃料費調整・再エネ賦課金の単価が未登録の期間があり、直近の登録値で近似しています。' : '');
  } catch (e) {
    console.error('loadCost failed', e);
  }
}

/* ---------- タブ切替 ---------- */
document.getElementById('power-tabs').addEventListener('click', (e) => {
  const btn = e.target.closest('button');
  if (!btn) return;
  document.querySelectorAll('#power-tabs button').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  loadPower(Number(btn.dataset.hours));
});

document.getElementById('hourly-tabs').addEventListener('click', (e) => {
  const btn = e.target.closest('button');
  if (!btn) return;
  document.querySelectorAll('#hourly-tabs button').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  loadHourly(Number(btn.dataset.days));
});

document.getElementById('daily-tabs').addEventListener('click', (e) => {
  const btn = e.target.closest('button');
  if (!btn) return;
  document.querySelectorAll('#daily-tabs button').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  loadDaily(Number(btn.dataset.days));
});

document.getElementById('monthly-tabs').addEventListener('click', (e) => {
  const btn = e.target.closest('button');
  if (!btn) return;
  document.querySelectorAll('#monthly-tabs button').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  loadMonthly(Number(btn.dataset.months));
});

/* ---------- 初期化 ---------- */
loadCurrent();
loadPower(1);
loadHourly(1);
loadDaily(30);
loadMonthly(12);
loadCost();

setInterval(loadCurrent, 30000);
</script>
</body>
</html>
