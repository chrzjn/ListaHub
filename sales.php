<?php
// ============================================================
//  sales.php  –  Sales Analytics
//  Requirements: Session guard, PDO prepared statements,
//                try-catch, COUNT/SUM in DB, SQL View
// ============================================================
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once './utils/lhdb.php';

$user_id = (int) $_SESSION['user_id'];

try {
    $pdo = getPDO();

    // Aggregate totals from the manager dashboard view
    $stmt = $pdo->prepare(
        "SELECT
            SUM(total_units_sold)             AS total_units_sold,
            SUM(total_revenue)                AS total_revenue,
            SUM(total_cogs)                   AS total_cogs,
            SUM(gross_profit)                 AS gross_profit,
            SUM(current_stock * cost_price)   AS total_cost_value,
            SUM(current_stock * retail_price) AS total_retail_value
         FROM vw_manager_dashboard
         WHERE user_id = :user_id"
    );
    $stmt->execute([':user_id' => $user_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Today's revenue
    $todayStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(s.total_amount), 0) AS todays_revenue
         FROM Sale s
         JOIN Sale_Item si ON si.sale_id   = s.sale_id
         JOIN Product   p  ON p.product_id = si.product_id
         WHERE p.user_id = :user_id
           AND DATE(s.sale_date) = CURDATE()"
    );
    $todayStmt->execute([':user_id' => $user_id]);
    $todayRow = $todayStmt->fetch(PDO::FETCH_ASSOC);

    // Sales this month
    $monthStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(s.total_amount), 0) AS month_revenue
         FROM Sale s
         JOIN Sale_Item si ON si.sale_id   = s.sale_id
         JOIN Product   p  ON p.product_id = si.product_id
         WHERE p.user_id = :user_id
           AND YEAR(s.sale_date)  = YEAR(CURDATE())
           AND MONTH(s.sale_date) = MONTH(CURDATE())"
    );
    $monthStmt->execute([':user_id' => $user_id]);
    $monthRow = $monthStmt->fetch(PDO::FETCH_ASSOC);

    // Cash vs credit (G-Cash/online) revenue
    $payStmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN s.payment_method = 'cash'   THEN s.total_amount ELSE 0 END), 0) AS cash_revenue,
            COALESCE(SUM(CASE WHEN s.payment_method = 'credit' THEN s.total_amount ELSE 0 END), 0) AS credit_revenue
         FROM Sale s
         JOIN Sale_Item si ON si.sale_id   = s.sale_id
         JOIN Product   p  ON p.product_id = si.product_id
         WHERE p.user_id = :user_id"
    );
    $payStmt->execute([':user_id' => $user_id]);
    $payRow = $payStmt->fetch(PDO::FETCH_ASSOC);

    // Total distinct transactions
    $txStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT s.sale_id) AS total_transactions
         FROM Sale s
         JOIN Sale_Item si ON si.sale_id   = s.sale_id
         JOIN Product   p  ON p.product_id = si.product_id
         WHERE p.user_id = :user_id"
    );
    $txStmt->execute([':user_id' => $user_id]);
    $txRow = $txStmt->fetch(PDO::FETCH_ASSOC);

    // Total distinct products sold
    $itemsStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT si.product_id) AS total_products
         FROM Sale_Item si
         JOIN Product p ON p.product_id = si.product_id
         WHERE p.user_id = :user_id"
    );
    $itemsStmt->execute([':user_id' => $user_id]);
    $itemsRow = $itemsStmt->fetch(PDO::FETCH_ASSOC);

    // Monthly revenue — last 6 months (SUM + GROUP BY in DB)
    $monthlyStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(s.sale_date, '%b %Y') AS month_label,
                DATE_FORMAT(s.sale_date, '%Y-%m')  AS sort_key,
                SUM(s.total_amount)                AS monthly_total
         FROM   Sale s
         JOIN   Sale_Item si ON si.sale_id   = s.sale_id
         JOIN   Product   p  ON p.product_id = si.product_id
         WHERE  p.user_id = :user_id
           AND  s.sale_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
         GROUP  BY DATE_FORMAT(s.sale_date, '%Y-%m')
         ORDER  BY MIN(s.sale_date) ASC"
    );
    $monthlyStmt->execute([':user_id' => $user_id]);
    $monthlyData = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Sales analytics error: " . $e->getMessage());
    $stats       = [];
    $monthlyData = [];
    $todayRow    = ['todays_revenue'  => 0];
    $monthRow    = ['month_revenue'   => 0];
    $payRow      = ['cash_revenue'    => 0, 'credit_revenue' => 0];
    $txRow       = ['total_transactions' => 0];
    $itemsRow    = ['total_products'  => 0];
}

// Helper: safe numeric fetch from $stats
function s(string $key, $default = 0) {
    global $stats;
    return $stats[$key] ?? $default;
}

// Build chart data for the JS line graph
$chartLabels = [];
$chartValues = [];
$chartMax    = 0;
foreach ($monthlyData as $row) {
    $chartLabels[] = htmlspecialchars($row['month_label']);
    $val           = (float) $row['monthly_total'];
    $chartValues[] = $val;
    if ($val > $chartMax) $chartMax = $val;
}
$chartMax = $chartMax > 0 ? $chartMax : 40000; // sensible fallback
$chartLabelsJson = json_encode($chartLabels);
$chartValuesJson = json_encode($chartValues);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Sales Analytics – ListaHub</title>

  <!-- Global CSS (variables, reset) -->
   <link rel="stylesheet" href="global_sidebar.css"/>
  <link rel="stylesheet" href="global_sales.css"/>

  <!-- Component CSS -->
  <link rel="stylesheet" href="sidebar.css"/>
  <link rel="stylesheet" href="sales.css"/>

  <!--
    Google Fonts – Inter
    TODO: You may host locally or keep this CDN link.
  -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
</head>
<body>

<div class="sales-page">

  <!-- ===================================================
       SIDEBAR
       =================================================== -->
  <?php $activePage = 'sales'; include 'sidebar.php'; ?>

  <!-- ===================================================
       MAIN BODY
       =================================================== -->
  <main class="main-body">

    <!-- ─── OVERVIEW PANEL ─── -->
    <section class="overview">
      <h1 class="sales-title">SALES</h1>

      <div class="info-section">

        <!-- Left: 4 stat cards in 2 rows -->
        <div class="cards-container">

          <!-- Row 1: Gross Sales | Profit -->
          <div class="cards-row">
            <!-- Gross Sales -->
            <div class="stat-card lavender">
              <div class="card-icon-wrap icon-blue">
                <!--
                  TODO: Replace src with your gross sales icon.
                  e.g. src="pics_icons/Group-494.svg"
                -->
                <img class="card-icon" alt="" src="pics_icons/profit.png"/>
              </div>
              <div class="card-text">
                <span class="card-label">Gross Sales</span>
                <h3 class="card-value">₱<?= number_format((float) s('total_revenue'), 0) ?></h3>
              </div>
            </div>

            <!-- Profit -->
            <div class="stat-card cream">
              <div class="card-icon-wrap icon-yellow">
                <!--
                  TODO: Replace src with your profit icon.
                  e.g. src="pics_icons/Group-494.svg"
                -->
                <img class="card-icon" alt="" src="pics_icons/usd-circle.png"/>
              </div>
              <div class="card-text">
                <span class="card-label">Profit</span>
                <h3 class="card-value">₱<?= number_format((float) s('gross_profit'), 0) ?></h3>
              </div>
            </div>
          </div>

          <!-- Row 2: Cash Sales | Online Sales (G-Cash / Credit) -->
          <div class="cards-row">
            <!-- Cash Sales -->
            <div class="stat-card green-card">
              <div class="card-icon-wrap icon-green">
                <!--
                  TODO: Replace src with your cash-sales icon.
                  e.g. src="pics_icons/Group-494.svg"
                -->
                <img class="card-icon" alt="" src="pics_icons/money (4).png"/>
              </div>
              <div class="card-text">
                <span class="card-label">Cash Sales</span>
                <h3 class="card-value">₱<?= number_format((float) ($payRow['cash_revenue'] ?? 0), 0) ?></h3>
              </div>
            </div>

            <!-- Online / Credit Sales -->
            <div class="stat-card cream">
              <div class="card-icon-wrap icon-yellow">
                <!--
                  TODO: Replace src with your online/gcash icon.
                  e.g. src="pics_icons/Group-494.svg"
                -->
                <img class="card-icon" alt="" src="pics_icons/digital-wallet.png"/>
              </div>
              <div class="card-text">
                <span class="card-label">Online Sales<br>(G-Cash)</span>
                <h3 class="card-value">₱<?= number_format((float) ($payRow['credit_revenue'] ?? 0), 0) ?></h3>
              </div>
            </div>
          </div>

        </div><!-- /cards-container -->

        <!-- Right: Status panel -->
        <div class="status-panel">
          <h3 class="status-title">Status</h3>

          <div class="status-cards-wrap">
            <div class="status-cards-inner">

              <!-- Row 1: Total Items Sold | Today's Sales -->
              <div class="status-row">
                <div class="status-card cream">
                  
                  <div class="card-text">
                    <span class="card-label">Total Items Sold</span>
                    <h3 class="card-value items">
                      <?= (int) ($itemsRow['total_products'] ?? 0) ?> Products
                    </h3>
                  </div>
                </div>

                <div class="status-card lavender">
                  
                  <div class="card-text">
                    <span class="card-label">Today's Sales</span>
                    <h3 class="card-value">₱<?= number_format((float) ($todayRow['todays_revenue'] ?? 0), 0) ?></h3>
                  </div>
                </div>
              </div>

              <!-- Row 2: Sales this Month (full width) -->
              <div class="status-bottom">
                <div class="status-card cream full-width">
                  
                  <div class="card-text">
                    <span class="card-label">Sales this Month</span>
                    <h3 class="card-value items">₱<?= number_format((float) ($monthRow['month_revenue'] ?? 0), 0) ?></h3>
                  </div>
                </div>
              </div>

            </div>
          </div>
        </div><!-- /status-panel -->

      </div><!-- /info-section -->
    </section><!-- /overview -->

    <!-- ─── REVENUE PANEL ─── -->
    <section class="revenue-section">
      <div class="revenue-cards">

        <!-- Gross Sales line chart -->
        <div class="chart-card">
          <div class="chart-header">
            <div class="chart-icon-wrap">
              <!--
                TODO: Replace src with your trend/arrow-up icon.
                e.g. src="pics_icons/Group.svg"
              -->
              <img alt="" src="pics_icons/profit.png"/>
            </div>
            <h3 class="chart-card-title">Gross Sales</h3>
          </div>

          <div class="chart-body">
            <?php if (!empty($monthlyData)): ?>
              <!-- Dynamic SVG line chart rendered via JS below -->
              <div class="line-graph" id="chart-wrapper">
                <div class="y-axis" id="y-axis">
                  <!-- populated by JS -->
                </div>
                <div class="graph-area" id="graph-area">
                  <div class="grid-lines" id="grid-lines">
                    <div class="grid-line"></div>
                    <div class="grid-line"></div>
                    <div class="grid-line"></div>
                    <div class="grid-line"></div>
                    <div class="grid-line"></div>
                  </div>
                  <svg class="chart-svg" id="chart-svg" viewBox="0 0 400 200"
                       preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
                  </svg>
                  <div class="x-axis" id="x-axis">
                    <!-- populated by JS -->
                  </div>
                </div>
              </div>
            <?php else: ?>
              <p style="color:#aaa;font-size:13px;margin:auto;">No sales data yet.</p>
            <?php endif; ?>
          </div>
        </div><!-- /chart-card -->

        <!-- Goods Info -->
        <div class="goods-card">
          <h3 class="goods-title">Goods Info</h3>

          <div class="goods-cards-wrap">
            <div class="goods-cards-inner">

              <!-- Row 1: Cost of Goods | Estimated Profit -->
              <div class="goods-row">
                <div class="goods-mini-card orange">
                  <div class="mini-text">
                    <span class="mini-label">Cost of Goods</span>
                    <h3 class="mini-value">₱<?= number_format((float) s('total_cogs'), 0) ?></h3>
                  </div>
                </div>

                <div class="goods-mini-card blue">
                  <div class="mini-text">
                    <span class="mini-label">Estimated Profit</span>
                    <h3 class="mini-value">₱<?= number_format((float) s('gross_profit'), 0) ?></h3>
                  </div>
                </div>
              </div>

              <!-- Row 2: Total Retail Value of Stock (full width) -->
              <div class="goods-bottom">
                <div class="goods-mini-card green-wide" style="flex:1;min-width:100%;">
                  
                  <div class="mini-text" style="align-items:center; width:100%; text-align:center;">
                    <span class="mini-label">Total Retail Value of Stock</span>
                    <h3 class="mini-value">₱<?= number_format((float) s('total_retail_value'), 0) ?></h3>
                  </div>
                </div>
              </div>

            </div>
          </div>
        </div><!-- /goods-card -->

      </div><!-- /revenue-cards -->
    </section><!-- /revenue-section -->

  </main><!-- /main-body -->
</div><!-- /sales-page -->

<!-- ============================================================
     INLINE CHART SCRIPT
     Draws a smooth line chart onto the SVG using PHP-injected data.
     ============================================================ -->
<script>
(function () {
  const labels = <?= $chartLabelsJson ?>;
  const values = <?= $chartValuesJson ?>;

  if (!labels.length) return;

  const maxVal   = Math.max(...values, 1);
  const niceMax  = Math.ceil(maxVal / 10000) * 10000;
  const steps    = 4; // number of y-axis intervals
  const svgW     = 400;
  const svgH     = 200;
  const padLeft  = 0;
  const padRight = 0;
  const padTop   = 10;
  const padBot   = 10;

  // ── Y-axis labels ──
  const yAxis = document.getElementById('y-axis');
  if (yAxis) {
    yAxis.innerHTML = '';
    for (let i = steps; i >= 0; i--) {
      const v    = (niceMax / steps) * i;
      const span = document.createElement('span');
      span.className = 'y-tick';
      span.textContent = '$' + (v >= 1000 ? (v / 1000).toFixed(0) + ',000' : v.toFixed(0));
      yAxis.appendChild(span);
    }
  }

  // ── X-axis labels ──
  const xAxis = document.getElementById('x-axis');
  if (xAxis) {
    xAxis.innerHTML = '';
    labels.forEach(function (lbl) {
      const span = document.createElement('span');
      span.className = 'x-tick';
      // Shorten to just year portion for brevity, matching the design
      span.textContent = lbl.split(' ')[1] || lbl;
      xAxis.appendChild(span);
    });
  }

  // ── SVG polyline ──
  const svg = document.getElementById('chart-svg');
  if (!svg || !values.length) return;

  const pts = values.map(function (v, i) {
    const x = padLeft + (i / (values.length - 1 || 1)) * (svgW - padLeft - padRight);
    const y = padTop  + (1 - v / niceMax) * (svgH - padTop - padBot);
    return x.toFixed(2) + ',' + y.toFixed(2);
  });

  const ptsStr = pts.join(' ');

  // Filled area under the line
  const firstX = padLeft;
  const lastX  = svgW - padRight;
  const baseY  = svgH - padBot;

  const areaPts = firstX + ',' + baseY + ' ' + ptsStr + ' ' + lastX + ',' + baseY;
  const polygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
  polygon.setAttribute('points', areaPts);
  polygon.setAttribute('fill', 'rgba(37,131,194,0.08)');
  svg.appendChild(polygon);

  // Line
  const polyline = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
  polyline.setAttribute('points', ptsStr);
  polyline.setAttribute('fill', 'none');
  polyline.setAttribute('stroke', '#2583c2');
  polyline.setAttribute('stroke-width', '2.5');
  polyline.setAttribute('stroke-linejoin', 'round');
  polyline.setAttribute('stroke-linecap', 'round');
  svg.appendChild(polyline);

  // Dots
  values.forEach(function (v, i) {
    const coords = pts[i].split(',');
    const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    circle.setAttribute('cx', coords[0]);
    circle.setAttribute('cy', coords[1]);
    circle.setAttribute('r',  '4');
    circle.setAttribute('fill', '#2583c2');
    svg.appendChild(circle);
  });
})();
</script>

</body>
</html>