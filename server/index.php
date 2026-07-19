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

// 推定電気代の単価(円/kWh)。Apache の SetEnv TAPO_YEN_PER_KWH で変更可。
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
    <h2>日別 電力量（30日）</h2>
    <div class="chart-card">
      <div id="dailyCostSummary" class="cost-summary"></div>
      <div class="chart-wrap short"><canvas id="dailyChart"></canvas></div>
      <details class="table-toggle">
        <summary>データを表で見る</summary>
        <div class="data-table-scroll">
          <table class="data-table" id="dailyTable"></table>
        </div>
      </details>
    </div>
  </section>
</main>

<footer>Tapo P110M 電力モニター</footer>

<script>
const DEVICES = <?php echo json_encode($devices, JSON_UNESCAPED_UNICODE); ?>;
const YEN_PER_KWH = <?php echo json_encode($yen_per_kwh); ?>; // 推定電気代の単価(円/kWh)
const OFFLINE_THRESHOLD_MS = 15 * 60 * 1000; // collectorの送信間隔(10分)に余裕を見た閾値

// kWh を推定電気代(円)に換算。四捨五入した整数円で返す。
function yen(kwh) { return Math.round(kwh * YEN_PER_KWH); }
function fmtYen(kwh) { return '¥' + yen(kwh).toLocaleString('ja-JP'); }

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

/* ---------- 日別 電力量（積み上げ・30日） ---------- */
let dailyChart = null;

function renderDailyTable(series) {
  const table = document.getElementById('dailyTable');
  const keys = Object.keys(series);
  let thead = '<thead><tr><th>日付</th>' + keys.map(k => '<th>' + deviceName(k) + ' (kWh)</th>').join('') + '</tr></thead>';

  const dateSet = new Set();
  keys.forEach(k => series[k].forEach(p => dateSet.add(p.date)));
  const dateList = Array.from(dateSet).sort();

  const maps = {};
  keys.forEach(k => { maps[k] = new Map(series[k].map(p => [p.date, p.wh])); });

  let rows = dateList.map(d => {
    const cells = keys.map(k => '<td>' + (maps[k].has(d) ? (maps[k].get(d) / 1000).toFixed(2) : '-') + '</td>');
    return '<tr><td>' + fmtMD(d) + '</td>' + cells.join('') + '</tr>';
  }).join('');

  table.innerHTML = thead + '<tbody>' + rows + '</tbody>';
}

async function loadDaily() {
  try {
    const res = await fetch('api/data.php?type=daily&days=30');
    if (!res.ok) throw new Error('http ' + res.status);
    const data = await res.json();

    const datasets = Object.keys(data.series).map(key => ({
      label: deviceName(key),
      data: data.series[key].map(p => ({ x: toLocalDate(p.date), y: p.wh / 1000 })),
      backgroundColor: seriesColor(key),
      borderRadius: 4,
      borderSkipped: 'bottom',
      maxBarThickness: 20,
      stack: 'kwh'
    }));

    // 期間(30日)合計の kWh と推定電気代をサマリー表示する
    let totalWh = 0;
    Object.keys(data.series).forEach(key => {
      data.series[key].forEach(p => { totalWh += p.wh; });
    });
    const totalKwh = totalWh / 1000;
    document.getElementById('dailyCostSummary').textContent =
      '期間合計: ' + totalKwh.toFixed(1) + ' kWh ／ 推定 ' + fmtYen(totalKwh)
      + '（' + YEN_PER_KWH + '円/kWh 換算）';

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
                label: (item) => item.dataset.label + ': ' + item.formattedValue + ' kWh（' + fmtYen(item.parsed.y) + '）',
                // その日の合計(積み上げ全系列)の推定電気代をフッターに出す
                footer: (items) => {
                  const totalKwh = items.reduce((s, it) => s + (it.parsed.y || 0), 0);
                  return '合計: ' + totalKwh.toFixed(2) + ' kWh / ' + fmtYen(totalKwh);
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

/* ---------- 初期化 ---------- */
loadCurrent();
loadPower(1);
loadHourly(1);
loadDaily();

setInterval(loadCurrent, 30000);
</script>
</body>
</html>
