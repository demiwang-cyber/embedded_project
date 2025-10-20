<?php
/****************************************************
 * DATABASE SETUP (Switch table here)
 ****************************************************/
$host  = 'localhost';
$db    = 'sensordatabase';
$user  = 'your_mysql_user';       // ← Your MySQL username
$pass  = 'your_mysql_password';   // ← Your MySQL password
$table = 'weather';               // ← Target table: 'weather' or 'weather_fake'
$dsn   = "mysql:host=$host;dbname=$db;charset=utf8mb4";

/****************************************************
 * OPEN PDO CONNECTION
 ****************************************************/
try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("DB Connection failed: " . htmlspecialchars($e->getMessage()));
}

/****************************************************
 * DATA QUERIES (Today for cards; Today/All for charts)
 ****************************************************/

/* Latest record of TODAY */
$latest = $pdo->query("
    SELECT time, temperature, humidity, pressure
    FROM `$table`
    WHERE time >= CURDATE() AND time < (CURDATE() + INTERVAL 1 DAY)
    ORDER BY time DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

/* All TODAY rows ASC and DESC */
$todayRowsAsc = $pdo->query("
    SELECT time, temperature, humidity, pressure
    FROM `$table`
    WHERE time >= CURDATE() AND time < (CURDATE() + INTERVAL 1 DAY)
    ORDER BY time ASC
")->fetchAll(PDO::FETCH_ASSOC);
$todayRowsDesc = array_reverse($todayRowsAsc);

/* Charts: Today & All (ASC) */
$chartTodayAsc = $pdo->query("
    SELECT time, temperature, humidity
    FROM `$table`
    WHERE time >= CURDATE() AND time < (CURDATE() + INTERVAL 1 DAY)
    ORDER BY time ASC
")->fetchAll(PDO::FETCH_ASSOC);

$chartAllAsc = $pdo->query("
    SELECT time, temperature, humidity
    FROM `$table`
    ORDER BY time ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* Popup: ALL rows of current table (DESC) */
$allRowsCurrent = $pdo->query("
    SELECT time, temperature, humidity, pressure
    FROM `$table`
    ORDER BY time DESC
")->fetchAll(PDO::FETCH_ASSOC);

/****************************************************
 * TODAY STATISTICS
 ****************************************************/
$tToday   = array_map('floatval', array_column($todayRowsAsc, 'temperature'));
$avgToday = $tToday ? array_sum($tToday)/count($tToday) : null;
$hiToday  = $tToday ? max($tToday) : null;
$loToday  = $tToday ? min($tToday) : null;

/****************************************************
 * IMPROVED 24h PRESSURE-BASED FORECAST
 * ΔP = pressure_now - pressure_24h_ago (robust trend)
 * Thresholds: > +1.0 → Clear; < -1.0 → Possible Rain; else → Stable
 ****************************************************/
$pressureNow = $latest ? (float)$latest['pressure'] : null;

/* Try regression-based trend over last 24h */
$rows24 = $pdo->query("
  SELECT UNIX_TIMESTAMP(time) AS ts, pressure
  FROM `$table`
  WHERE time >= NOW() - INTERVAL 24 HOUR
    AND pressure IS NOT NULL
  ORDER BY time ASC
")->fetchAll(PDO::FETCH_ASSOC);

$delta24h     = null;
$forecastText = '—';

if ($pressureNow !== null && $rows24 && count($rows24) >= 6) {
    // (A) Simple linear regression: pressure ~ time
    $n = count($rows24);
    $sumx = 0.0; $sumy = 0.0; $sumxy = 0.0; $sumx2 = 0.0;
    foreach ($rows24 as $r) {
        $x = (float)$r['ts'];       // seconds
        $y = (float)$r['pressure']; // hPa
        $sumx  += $x;
        $sumy  += $y;
        $sumxy += $x * $y;
        $sumx2 += $x * $x;
    }
    $den = $n * $sumx2 - $sumx * $sumx;

    if ($den != 0.0) {
        $beta      = ($n * $sumxy - $sumx * $sumy) / $den; // slope hPa/sec
        $slope_hr  = $beta * 3600.0;                       // hPa/hr
        $delta_est = $slope_hr * 24.0;                     // hPa/24h

        // R² fit quality
        $meanY = $sumy / $n;
        $ss_tot = 0.0; $ss_res = 0.0;
        $alpha  = ($sumy - $beta * $sumx) / $n;            // intercept
        foreach ($rows24 as $r) {
            $x = (float)$r['ts']; $y = (float)$r['pressure'];
            $yhat = $alpha + $beta * $x;
            $ss_tot += ($y - $meanY) * ($y - $meanY);
            $ss_res += ($y - $yhat)  * ($y - $yhat);
        }
        $r2 = ($ss_tot > 0.0) ? max(0.0, min(1.0, 1.0 - $ss_res / $ss_tot)) : 0.0;

        // (B) Robust fallback if fit is weak or delta unrealistic
        if ($r2 < 0.15 || abs($delta_est) > 15.0) {
            $ts_now = time();

            $median = function(array $arr) {
                $vals = array_values(array_map(function($r){ return (float)$r['pressure']; }, $arr));
                $n = count($vals);
                if ($n === 0) return null;
                sort($vals, SORT_NUMERIC);
                $m = (int) floor($n / 2);
                return ($n % 2) ? $vals[$m] : ($vals[$m-1] + $vals[$m]) / 2.0;
            };

            // last 60 min window
            $lastHour = array_filter($rows24, function($r) use ($ts_now) {
                return $r['ts'] >= $ts_now - 3600;
            });
            // around (now-24h) ±45 min
            $oldWindow = array_filter($rows24, function($r) use ($ts_now) {
                return $r['ts'] >= $ts_now - 24*3600 - 2700 && $r['ts'] <= $ts_now - 24*3600 + 2700;
            });

            $m_now = $median($lastHour);
            $m_old = $median($oldWindow);

            if ($m_now !== null && $m_old !== null) {
                $delta_est = $m_now - $m_old;
            } else {
                // Final safety fallback: nearest point at <= now-24h
                $pressure24Ago = $pdo->query("
                    SELECT pressure
                    FROM `$table`
                    WHERE time <= NOW() - INTERVAL 24 HOUR
                    ORDER BY time DESC
                    LIMIT 1
                ")->fetchColumn();

                if ($pressure24Ago !== false && $pressure24Ago !== null) {
                    $delta_est = $pressureNow - (float)$pressure24Ago;
                } else {
                    $delta_est = null;
                }
            }
        }

        $delta24h = $delta_est;
    }
}

/* Absolute last fallback if regression didn’t produce a delta */
if ($delta24h === null && $pressureNow !== null) {
    $pressure24Ago = $pdo->query("
        SELECT pressure
        FROM `$table`
        WHERE time <= NOW() - INTERVAL 24 HOUR
        ORDER BY time DESC
        LIMIT 1
    ")->fetchColumn();

    if ($pressure24Ago !== false && $pressure24Ago !== null) {
        $delta24h = $pressureNow - (float)$pressure24Ago;
    }
}

/* Map ΔP to forecast string */
if ($delta24h !== null) {
    if ($delta24h > 1.0)      $forecastText = 'Clear';
    elseif ($delta24h < -1.0) $forecastText = 'Possible Rain';
    else                      $forecastText = 'Stable';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Weather Dashboard</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
  :root{
    --bg-grad-1:#e9f0ff;
    --bg-grad-2:#f7fbff;
    --card-bg:#ffffff;
    --card-bd:#e6ecf7;
    --text:#1e2a3a;
    --muted:#6a7a92;
    --accent:#1b6fd6;
    --accent-2:#0d47a1;
    --shadow:0 10px 30px rgba(24,39,75,.08), 0 1px 2px rgba(0,0,0,.04);
    --radius:16px;
  }
  *{box-sizing:border-box}
  body{
    font-family: Inter, Segoe UI, Roboto, Arial, "Noto Sans TC", "PingFang TC", "Microsoft JhengHei", sans-serif;
    color:var(--text);
    margin:0;
    background: radial-gradient(1200px 600px at 20% -10%, #d8e6ff 0%, transparent 60%) no-repeat,
                linear-gradient(180deg, var(--bg-grad-1), var(--bg-grad-2));
  }
  header{
    max-width:1400px;margin:24px auto 0;padding:0 20px;
    display:flex;align-items:center;gap:14px;
  }
  .brand{
    display:flex;align-items:center;gap:10px;
    font-weight:800;font-size:1.4rem;color:var(--accent);
  }
  .brand .dot{width:10px;height:10px;border-radius:50%;background:linear-gradient(135deg,#67a7ff,#2a6edc);box-shadow:0 0 0 3px rgba(26,111,214,.12)}
  .sub{ margin-left:auto;color:var(--muted);font-weight:600 }

  .container{
    display:grid;grid-template-columns:340px 1fr 320px;
    gap:22px;max-width:1400px;margin:16px auto 40px;padding:0 20px 10px;
  }

  .card{
    background:var(--card-bg);
    border:1px solid var(--card-bd);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    overflow:hidden;
  }
  .card-header{
    display:flex;align-items:center;gap:10px;
    padding:14px 16px;border-bottom:1px solid var(--card-bd);
    background:linear-gradient(180deg,#fcfdff,#f5f9ff);
    font-weight:800;
  }
  .badge{ padding:3px 8px;border-radius:999px;background:#e9f2ff;color:var(--accent);font-weight:700;font-size:.8rem;border:1px solid #d8e8ff }
  .section{padding:16px}
  .sep{height:1px;background:var(--card-bd)}
  .stack{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
  .now{font-size:3rem;font-weight:900;letter-spacing:-.02em;margin:8px 0 6px}
  .muted{color:var(--muted);font-weight:700}

  .hilo{display:flex;flex-direction:column;gap:12px;align-items:flex-end;margin-top:2px}
  .hilo-row{display:flex;align-items:center;gap:8px}
  .hilo-val{font-size:1.15rem;font-weight:900;color:#2b3d55}
  .icon-therm{width:20px;height:20px}

  .kv{padding:4px 16px 16px}
  .kv-row{display:grid;grid-template-columns:28px 1fr auto;align-items:center;gap:10px;padding:12px 0;border-bottom:1px dashed var(--card-bd)}
  .kv-row:last-child{border-bottom:none}
  .kv-ico{color:#97a9c2}
  .kv-ico svg{width:20px;height:20px;display:block}
  .kv-label{color:#4b627f;font-weight:700}
  .kv-val{font-weight:900;text-align:right}

  .mini-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
  .mini{
    background:linear-gradient(180deg,#f7faff,#f0f6ff);
    border:1px solid var(--card-bd);border-radius:12px;text-align:center;padding:14px 10px
  }
  .mini .k{color:var(--muted);font-weight:700}
  .mini h3{margin:6px 0 2px;color:var(--accent);font-size:1.3rem}

  canvas{width:100%;height:330px}

  .btn{
    appearance:none;border:none;cursor:pointer;font-weight:800;
    padding:10px 14px;border-radius:10px;
    background:linear-gradient(180deg,#1f78ec,#145dbe);color:#fff;
    box-shadow:0 6px 16px rgba(30,101,214,.25);transition:transform .06s ease, box-shadow .2s ease;
  }
  .btn:hover{transform:translateY(-1px);box-shadow:0 10px 24px rgba(30,101,214,.28)}
  .btn-outline{ background:#fff;color:var(--accent);border:1px solid #cfe2ff;box-shadow:none }
  .btn-sm{padding:7px 12px;font-size:.9rem;border-radius:9px}

  .toolbar{display:flex;gap:10px;padding:12px 16px;background:linear-gradient(180deg,#fcfdff,#f5f9ff);border-top:1px solid var(--card-bd)}
  .grow{flex:1}

  .right-list{list-style:none;margin:0;padding:2px 16px 16px}
  .right-list li{
    display:flex;justify-content:space-between;align-items:center;gap:12px;
    padding:10px 0;border-bottom:1px dashed var(--card-bd);
    font-weight:700;color:#2c3e55
  }
  .right-list li:last-child{border-bottom:none}
  .chip{padding:2px 8px;border-radius:999px;background:#eef4ff;color:#3a5fa8;border:1px solid #dae8ff;font-weight:800}

  #popup{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(8,15,30,.35);backdrop-filter: blur(3px)}
  #popup .box{
    background:#fff;border:1px solid var(--card-bd);border-radius:16px;box-shadow:var(--shadow);
    max-width:95vw;width:1100px;max-height:85vh;overflow:auto
  }
  #popup .box h2{padding:16px 18px;margin:0;border-bottom:1px solid var(--card-bd)}
  #popup .box .inner{padding:14px 18px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid #edf1f8;text-align:center;white-space:nowrap}
  th{background:#f7fbff;color:#2a4d86;position:sticky;top:0}
  tr:hover td{background:#fbfdff}
  .tbl-foot{display:flex;justify-content:flex-end;padding:12px 0}

  @media (max-width:1200px){
    .container{grid-template-columns:1fr;gap:18px}
  }
</style>
</head>
<body>

<header>
  <div class="brand"><span class="dot"></span>Weather Dashboard</div>
  <div class="sub">DB: <strong><?php echo htmlspecialchars($db); ?></strong> · Table: <strong><?php echo htmlspecialchars($table); ?></strong></div>
</header>

<div class="container">

  <!-- Left Card -->
  <div class="card">
    <div class="card-header">
      <span>Current Conditions</span>
      <span class="badge">Today</span>
    </div>

    <div class="section">
      <div class="stack">
        <div>
          <div class="now">
            <?php echo $latest ? number_format($latest['temperature'],1).'°' : '—'; ?>
          </div>
          <div class="muted">
            Feels like <?php echo $latest ? number_format($latest['temperature']-0.5,1).'°' : '—'; ?>
          </div>
        </div>

        <div class="hilo">
          <div class="hilo-row">
            <!-- High -->
            <svg class="icon-therm" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M10 3a2 2 0 0 1 4 0v8.09a5.5 5.5 0 1 1-4 0V3z" fill="#ef5350"/>
              <rect x="11" y="3" width="2" height="13" rx="1" fill="#b71c1c"/>
              <circle cx="12" cy="18" r="3.5" fill="#ef5350" stroke="#b71c1c" stroke-width="1"/>
            </svg>
            <span class="hilo-val"><?php echo $hiToday!==null ? number_format($hiToday,0).'°' : '—'; ?></span>
          </div>
          <div class="hilo-row">
            <!-- Low -->
            <svg class="icon-therm" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M10 3a2 2 0 0 1 4 0v8.09a5.5 5.5 0 1 1-4 0V3z" fill="#42a5f5"/>
              <rect x="11" y="3" width="2" height="13" rx="1" fill="#0d47a1"/>
              <circle cx="12" cy="18" r="3.5" fill="#42a5f5" stroke="#0d47a1" stroke-width="1"/>
            </svg>
            <span class="hilo-val"><?php echo $loToday!==null ? number_format($loToday,0).'°' : '—'; ?></span>
          </div>
        </div>
      </div>
    </div>

    <div class="sep"></div>

    <div class="kv">
      <!-- Humidity (today latest) -->
      <div class="kv-row">
        <div class="kv-ico">💧</div>
        <div class="kv-label">Humidity</div>
        <div class="kv-val"><?php echo $latest ? (int)$latest['humidity'].'%' : '—'; ?></div>
      </div>

      <!-- Pressure (today latest) -->
      <div class="kv-row">
        <div class="kv-ico">
          <!-- Gauge icon -->
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <defs><linearGradient id="g" x1="0" x2="0" y1="0" y2="1">
              <stop offset="0" stop-color="#90caf9"/><stop offset="1" stop-color="#1e88e5"/></linearGradient>
            </defs>
            <circle cx="12" cy="12" r="9" fill="url(#g)" stroke="#0d47a1" stroke-width="1.2"/>
            <path d="M12 6 A6 6 0 0 1 18 12" fill="none" stroke="#0d47a1" stroke-width="1"/>
            <path d="M12 6 A6 6 0 0 0 6 12" fill="none" stroke="#0d47a1" stroke-width="1"/>
            <line x1="12" y1="12" x2="17" y2="10" stroke="#b71c1c" stroke-width="1.6" />
            <circle cx="12" cy="12" r="1.6" fill="#b71c1c"/>
          </svg>
        </div>
        <div class="kv-label">Pressure</div>
        <div class="kv-val">
          <?php echo $latest ? $latest['pressure'].' hPa' : '—'; ?>
        </div>
      </div>

      <!-- Forecast (independent row, uses improved ΔP rule) -->
      <div class="kv-row">
        <div class="kv-ico">🌤️</div>
        <div class="kv-label">Forecast</div>
        <div class="kv-val">
          <?php echo ($delta24h !== null) ? htmlspecialchars($forecastText) : '—'; ?>
          <?php if ($delta24h !== null): ?>
            <div class="muted" style="margin-top:4px">
              ΔP <?php echo ($delta24h>0?'+':'').number_format($delta24h,1); ?> hPa / 24h
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="toolbar">
      <button class="btn btn-outline" onclick="refreshData()">Refresh Data</button>
      <div class="grow"></div>
      <button class="btn" onclick="document.getElementById('popup').style.display='flex'">View All Data</button>
    </div>
  </div>

  <!-- Middle Card -->
  <div class="card">
    <div class="card-header">
      <span>Today's Readings</span>
      <span class="badge">Summary</span>
    </div>
    <div class="section">
      <div class="mini-cards">
        <div class="mini"><div class="k">Latest</div><h3><?php echo $latest ? number_format($latest['temperature'],1).'°C' : '--.-°C'; ?></h3></div>
        <div class="mini"><div class="k">Average</div><h3><?php echo $avgToday!==null ? number_format($avgToday,1).'°C' : '--.-°C'; ?></h3></div>
        <div class="mini"><div class="k">High</div><h3><?php echo $hiToday!==null ? number_format($hiToday,1).'°C' : '--.-°C'; ?></h3></div>
        <div class="mini"><div class="k">Low</div><h3><?php echo $loToday!==null ? number_format($loToday,1).'°C' : '--.-°C'; ?></h3></div>
      </div>
    </div>

    <div class="sep"></div>

    <div class="section" style="display:flex;align-items:center;gap:10px">
      <h2 id="tempTitle" style="margin:0;flex:1">Temperature History (Today)</h2>
      <button id="toggleBtn" class="btn btn-sm" onclick="toggleCharts()">Show: All</button>
    </div>
    <div class="section"><canvas id="tempChart"></canvas></div>

    <div class="sep"></div>

    <div class="section">
      <h2 id="humTitle" style="margin:0 0 10px 0">Humidity History (Today)</h2>
      <canvas id="humChart"></canvas>
    </div>
  </div>

  <!-- Right Card -->
  <div class="card">
    <div class="card-header">
      <span>Today (Summary)</span>
      <span class="badge">Latest 10</span>
    </div>
    <ul class="right-list">
      <?php foreach(array_slice($todayRowsDesc,0,10) as $r): ?>
        <li>
          <span class="chip"><?php echo date('H:i', strtotime($r['time'])); ?></span>
          <span><?php echo number_format($r['temperature'],1); ?>°C</span>
          <span><?php echo (int)$r['humidity']; ?>%</span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

</div>

<!-- Popup: all rows from current table -->
<div id="popup">
  <div class="box">
    <h2>All Data (table: <?php echo htmlspecialchars($table); ?>)</h2>
    <div class="inner">
      <div style="overflow:auto;border:1px solid var(--card-bd);border-radius:12px">
        <table>
          <tr><th>Time</th><th>Temp (°C)</th><th>Humidity (%)</th><th>Pressure (hPa)</th></tr>
          <?php foreach ($allRowsCurrent as $r): ?>
            <tr>
              <td><?php echo htmlspecialchars($r['time']); ?></td>
              <td><?php echo number_format($r['temperature'],1); ?></td>
              <td><?php echo (int)$r['humidity']; ?></td>
              <td><?php echo isset($r['pressure']) ? htmlspecialchars($r['pressure']) : ''; ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <div class="tbl-foot">
        <button class="btn btn-outline" onclick="document.getElementById('popup').style.display='none'">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Charts + Toggle + Modal UX -->
<script>
function refreshData(){ window.location.reload(); }

/* PHP → JS datasets */
const labelsToday = <?php echo json_encode(array_column($chartTodayAsc,'time')); ?>;
const tempsToday  = <?php echo json_encode(array_map('floatval', array_column($chartTodayAsc,'temperature'))); ?>;
const humsToday   = <?php echo json_encode(array_map('floatval', array_column($chartTodayAsc,'humidity'))); ?>;
const labelsAll   = <?php echo json_encode(array_column($chartAllAsc,'time')); ?>;
const tempsAll    = <?php echo json_encode(array_map('floatval', array_column($chartAllAsc,'temperature'))); ?>;
const humsAll     = <?php echo json_encode(array_map('floatval', array_column($chartAllAsc,'humidity'))); ?>;

/* Init: show Today */
let mode = 'today';

const tempCtx = document.getElementById('tempChart');
const humCtx  = document.getElementById('humChart');

const tempChart = new Chart(tempCtx, {
  type: 'line',
  data: { labels: labelsToday, datasets: [{
    label: 'Temperature (°C)',
    data: tempsToday,
    borderColor: 'red',
    backgroundColor: 'rgba(255,0,0,.10)',
    fill:true, tension:.3, pointRadius:1
  }]},
  options:{ animation:false, plugins:{legend:{display:false}}, scales:{x:{display:false},y:{beginAtZero:false}} }
});

const humChart = new Chart(humCtx, {
  type: 'line',
  data: { labels: labelsToday, datasets: [{
    label: 'Humidity (%)',
    data: humsToday,
    borderColor: 'blue',
    backgroundColor: 'rgba(0,0,255,.10)',
    fill:true, tension:.3, pointRadius:1
  }]},
  options:{ animation:false, plugins:{legend:{display:false}}, scales:{x:{display:false},y:{beginAtZero:false}} }
});

function setChartsTo(modeWanted){
  const isToday = modeWanted === 'today';
  tempChart.data.labels = isToday ? labelsToday : labelsAll;
  tempChart.data.datasets[0].data = isToday ? tempsToday : tempsAll;
  tempChart.update();

  humChart.data.labels = isToday ? labelsToday : labelsAll;
  humChart.data.datasets[0].data = isToday ? humsToday : humsAll;
  humChart.update();

  document.getElementById('tempTitle').textContent = `Temperature History (${isToday ? 'Today' : 'All'})`;
  document.getElementById('humTitle').textContent  = `Humidity History (${isToday ? 'Today' : 'All'})`;
  document.getElementById('toggleBtn').textContent = `Show: ${isToday ? 'All' : 'Today'}`;

  mode = modeWanted;
}
function toggleCharts(){ setChartsTo(mode === 'today' ? 'all' : 'today'); }

/* Modal: click outside or press ESC to close */
(function () {
  const popup = document.getElementById('popup');
  if (!popup) return;
  popup.addEventListener('click', function (e) {
    if (e.target === popup) { popup.style.display = 'none'; }
  });
  document.addEventListener('keydown', function (e) {
    if ((e.key === 'Escape' || e.key === 'Esc') && popup.style.display === 'flex') {
      popup.style.display = 'none';
    }
  });
})();
</script>

</body>
</html>
