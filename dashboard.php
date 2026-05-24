<?php
// ============================================================
//  dashboard.php
//  Requirements met:
//   ✅ SQL View (vw_manager_dashboard) used for reporting
//   ✅ COUNT(), SUM() done in DB
//   ✅ PDO prepared statements
//   ✅ try-catch
//   ✅ Session-based auth guard
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

    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*)                                                                        AS total_products,
            SUM(current_stock)                                                              AS total_stock_units,
            SUM(CASE WHEN stock_status = 'Out of Stock' THEN 1 ELSE 0 END)                 AS out_of_stock_count,
            SUM(CASE WHEN stock_status = 'Low Stock'    THEN 1 ELSE 0 END)                 AS low_stock_count,
            SUM(CASE WHEN stock_status = 'Near Expiry'  THEN 1 ELSE 0 END)                 AS near_expiry_count,
            SUM(CASE WHEN stock_status = 'Expired'      THEN 1 ELSE 0 END)                 AS expired_count,
            SUM(total_units_sold)                                                           AS total_units_sold,
            SUM(total_revenue)                                                              AS total_revenue,
            SUM(current_stock * cost_price)                                                 AS total_cost_value,
            SUM(current_stock * retail_price)                                               AS total_retail_value,
            SUM(gross_profit)                                                               AS gross_profit
         FROM vw_manager_dashboard
         WHERE user_id = :user_id"
    );
    $stmt->execute([':user_id' => $user_id]);
    $stats = $stmt->fetch();

    // Today's revenue — use Sale directly, scope via subquery to avoid row multiplication
    $todayStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(s.total_amount), 0) AS todays_revenue
         FROM Sale s
         WHERE DATE(s.sale_date) = CURDATE()
           AND s.sale_id IN (
               SELECT DISTINCT si.sale_id
               FROM Sale_Item si
               JOIN Product p ON p.product_id = si.product_id
               WHERE p.user_id = :user_id
           )"
    );
    $todayStmt->execute([':user_id' => $user_id]);
    $todayRow = $todayStmt->fetch();

    // Total transactions
    $txStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT s.sale_id) AS total_transactions
         FROM Sale s
         WHERE s.sale_id IN (
             SELECT DISTINCT si.sale_id
             FROM Sale_Item si
             JOIN Product p ON p.product_id = si.product_id
             WHERE p.user_id = :user_id
         )"
    );
    $txStmt->execute([':user_id' => $user_id]);
    $txRow = $txStmt->fetch();

    // Total units sold — only from Sale_Item (sales only, excludes restocks)
    $soldStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(si.quantity_sold), 0) AS total_units_sold
         FROM Sale_Item si
         WHERE si.product_id IN (
             SELECT product_id FROM Product WHERE user_id = :user_id
         )"
    );
    $soldStmt->execute([':user_id' => $user_id]);
    $soldRow = $soldStmt->fetch();

    // Monthly revenue breakdown (last 6 months)
    $monthlyStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(s.sale_date, '%b %Y') AS month_label,
                SUM(s.total_amount)               AS monthly_total
         FROM Sale s
         WHERE s.sale_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
           AND s.sale_id IN (
               SELECT DISTINCT si.sale_id
               FROM Sale_Item si
               JOIN Product p ON p.product_id = si.product_id
               WHERE p.user_id = :user_id
           )
         GROUP BY DATE_FORMAT(s.sale_date, '%Y-%m')
         ORDER BY MIN(s.sale_date) ASC"
    );
    $monthlyStmt->execute([':user_id' => $user_id]);
    $monthlyData = $monthlyStmt->fetchAll();

    // Profit = gross_profit summed from view (revenue - COGS, not inventory value)
    $profit = (float)($stats['gross_profit'] ?? 0);

    // Customer credit stats
    // Customer table has no user_id — scope via Sale > Sale_Item > Product
    // For single-user systems, just read all customers directly
    // Scope customers to this user via Sale → Sale_Item → Product → user_id
    $custStmt = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT c.customer_id)                                      AS total_customers,
            COUNT(DISTINCT CASE WHEN d_agg.remaining > 0
                           THEN c.customer_id END)                             AS unsettled_count,
            COALESCE(SUM(d_agg.remaining), 0)                                  AS total_credit
         FROM Customer c
         LEFT JOIN (
             SELECT s.customer_id,
                    SUM(d.remaining_balance) AS remaining
             FROM Debt d
             JOIN Sale s ON s.sale_id = d.sale_id
             WHERE s.customer_id IS NOT NULL
             GROUP BY s.customer_id
         ) d_agg ON d_agg.customer_id = c.customer_id
         WHERE c.customer_id IN (
             SELECT DISTINCT s2.customer_id
             FROM Sale s2
             JOIN Sale_Item si ON si.sale_id = s2.sale_id
             JOIN Product p    ON p.product_id = si.product_id
             WHERE p.user_id = :user_id
               AND s2.customer_id IS NOT NULL
         )"
    );
    $custStmt->execute([':user_id' => $user_id]);
    $custRow = $custStmt->fetch();

    // Inventory status percentages for progress bars
    $totalProducts = max((int)($stats['total_products'] ?? 0), 1);
    $lowStockPct   = round(((int)($stats['low_stock_count']   ?? 0) / $totalProducts) * 100);
    $outStockPct   = round(((int)($stats['out_of_stock_count'] ?? 0) / $totalProducts) * 100);
    $nearExpiryPct = round(((int)($stats['near_expiry_count'] ?? 0) / $totalProducts) * 100);
    $expiredPct    = round(((int)($stats['expired_count']     ?? 0) / $totalProducts) * 100);

} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $stats        = [];
    $monthlyData  = [];
    $todayRow     = ['todays_revenue' => 0];
    $txRow        = ['total_transactions' => 0];
    $soldRow      = ['total_units_sold' => 0];
    $custRow      = ['total_customers' => 0, 'unsettled_count' => 0, 'total_credit' => 0];
    $profit       = 0;
    $lowStockPct  = $outStockPct = $nearExpiryPct = $expiredPct = 0;
}

function getStat(string $key, $default = 0) {
    global $stats;
    return $stats[$key] ?? $default;
}

$activePage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard – ListaHub</title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Roboto:wght@600&display=swap" rel="stylesheet"/>

  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"/>

  <!-- Global CSS (variables, reset) -->
  <link rel="stylesheet" href="global_sidebar.css"/>
  <link rel="stylesheet" href="global_dashboard.css"/>

  <!-- Component CSS -->
  <link rel="stylesheet" href="sidebar.css"/>
  <link rel="stylesheet" href="dashboard.css"/>
  <link rel="stylesheet" href="main-body.css"/>
</head>
<body>

<div class="page-wrapper">

  <!-- ============================================================
       SIDEBAR
       sidebar.php sets $activePage and renders the aside.
       Make sure sidebar.php, sidebar.css, and global_sidebar.css
       are in the same directory as this file.
       ============================================================ -->
  <?php
    $activePage = 'dashboard';
    include 'sidebar.php';
  ?>

  <!-- ============================================================
       MAIN BODY
       ============================================================ -->
  <div class="main-body">

    <!-- ── OVERVIEW ROW: Inventory + Sales ── -->
    <div class="overview-row">

      <!-- ── Inventory Overview ── -->
      <div class="section-container">
        <h2 class="section-title">Inventory Overview</h2>
        <div class="cards-grid">

          <div class="cards-row">
            <!-- Out of Stock -->
            <div class="stat-card red-bg">
              <div class="stat-icon icon-red">
              
                  <img src="pics_icons/out-of-stock (1).png" width="33" alt="Out of Stock"/>
                
              </div>
              <div class="stat-info">
                <span class="stat-label">Out of Stock</span>
                <span class="stat-value red"><?= (int)getStat('out_of_stock_count') ?> Products</span>
              </div>
            </div>

            <!-- Expired Products -->
            <div class="stat-card linen-bg">
              <div class="stat-icon icon-orange">

                  <img src="pics_icons/expired.png" width="33" alt="Expired"/>
            
              </div>
              <div class="stat-info">
                <span class="stat-label">Expired Products</span>
                <span class="stat-value orange"><?= (int)getStat('expired_count') ?> Products</span>
              </div>
            </div>
          </div>

          <div class="cards-row">
            <!-- Low on Stock -->
            <div class="stat-card cream-bg">
              <div class="stat-icon icon-gray">
                
                  <img src="pics_icons/arrow-trend-down.png" width="33" alt="Low Stock"/>
                
              </div>
              <div class="stat-info">
                <span class="stat-label">Low on Stock</span>
                <span class="stat-value gray"><?= (int)getStat('low_stock_count') ?> Products</span>
              </div>
            </div>

            <!-- Near Expiration -->
            <div class="stat-card lavender-bg">
              <div class="stat-icon icon-blue">

                  <img src="pics_icons/duration-alt.png" width="33" alt="Near Expiry"/>

              </div>
              <div class="stat-info">
                <span class="stat-label">Near Expiration</span>
                <span class="stat-value gray"><?= (int)getStat('near_expiry_count') ?> Products</span>
              </div>
            </div>
          </div>

        </div>
      </div><!-- /Inventory Overview -->

      <!-- ── Sales Overview ── -->
      <div class="section-container">
        <h2 class="section-title">Sales Overview</h2>
        <div class="cards-grid">

          <div class="cards-row">
            <!-- Gross Sales -->
            <div class="stat-card lavender-bg">
              <div class="stat-icon icon-blue">
                
                  <img src="pics_icons/money (4).png" width="33" alt="Gross Sales"/>
                  
              </div>
              <div class="stat-info">
                <span class="stat-label">Gross Sales</span>
                <span class="stat-value blue">₱<?= number_format((float)getStat('total_revenue'), 0) ?></span>
              </div>
            </div>

            <!-- Total Items Sold -->
            <div class="stat-card cream-bg">
              <div class="stat-icon icon-yellow">
                
                  <img src="pics_icons/bags-shopping.png" width="33" alt="Items Sold"/>
                  
              </div>
              <div class="stat-info">
                <span class="stat-label">Total Items Sold</span>
                <span class="stat-value gray"><?= (int)($soldRow['total_units_sold'] ?? 0) ?> Products</span>
              </div>
            </div>
          </div>

          <div class="cards-row">
            <!-- Profit -->
            <div class="stat-card green-bg">
              <div class="stat-icon icon-green">
                
                  <img src="pics_icons/usd-circle.png" width="33" alt="Profit"/>
                 
              </div>
              <div class="stat-info">
                <span class="stat-label">Profit</span>
                <span class="stat-value green">₱<?= number_format($profit, 0) ?></span>
              </div>
            </div>

            <!-- Today's Sales -->
            <div class="stat-card cream-bg">
              <div class="stat-icon icon-yellow">
                
                  <img src="pics_icons/growth-chart-invest.png" width="33" alt="Today's Sales"/>
                  
              </div>
              <div class="stat-info">
                <span class="stat-label">Today's Sales</span>
                <span class="stat-value gray">₱<?= number_format((float)($todayRow['todays_revenue'] ?? 0), 0) ?></span>
              </div>
            </div>
          </div>

        </div>
      </div><!-- /Sales Overview -->

    </div><!-- /overview-row -->

    <!-- ── MIDDLE ROW: Inventory Status + Monthly Sales ── -->
    <div class="middle-row">

      <!-- Inventory Status -->
      <div class="chart-card">
        <div class="chart-header">
          <div class="chart-icon-wrap">
            <div class="chart-icon-bg yellow"></div>
            <span class="chart-icon" style="color: var(--1-brown);">
             
                <img src="pics_icons/inbox-full.png" width="22" height="22" alt="Inventory"/>
              
            </span>
          </div>
          <div class="chart-title-block">
            <span class="chart-title">Inventory Status</span>
            <span class="chart-subtitle">Information about stock levels</span>
          </div>
        </div>

        <div class="progress-list">
          <!-- Low stock -->
          <div class="progress-item">
            <span class="progress-label">Low stock</span>
            <div class="progress-track">
              <div class="progress-fill fill-yellow" style="width: <?= $lowStockPct ?>%;"></div>
            </div>
            <span class="progress-pct"><?= $lowStockPct ?>%</span>
          </div>

          <!-- Out of Stock -->
          <div class="progress-item">
            <span class="progress-label">Out of Stock</span>
            <div class="progress-track">
              <div class="progress-fill fill-red" style="width: <?= $outStockPct ?>%;"></div>
            </div>
            <span class="progress-pct"><?= $outStockPct ?>%</span>
          </div>

          <!-- Near Expiry -->
          <div class="progress-item">
            <span class="progress-label">Near Expiry</span>
            <div class="progress-track">
              <div class="progress-fill fill-orange" style="width: <?= $nearExpiryPct ?>%;"></div>
            </div>
            <span class="progress-pct"><?= $nearExpiryPct ?>%</span>
          </div>

          <!-- Expired -->
          <div class="progress-item">
            <span class="progress-label">Expired</span>
            <div class="progress-track">
              <div class="progress-fill fill-red" style="width: <?= $expiredPct ?>%;"></div>
            </div>
            <span class="progress-pct"><?= $expiredPct ?>%</span>
          </div>
        </div>
      </div><!-- /Inventory Status -->

      <!-- Monthly Sales -->
      <div class="chart-card">
        <div class="chart-header">
          <div class="chart-icon-wrap">
            <div class="chart-icon-bg blue"></div>
            <span class="chart-icon" style="color: #fff;">
              
                <img src="pics_icons/calendar.png" width="22" height="22" alt="Monthly Sales"/>
                
            </span>
          </div>
          <div class="chart-title-block">
            <span class="chart-title">Monthly Sales</span>
          </div>
        </div>

        <?php
          // Prepare chart data
          $labels  = [];
          $amounts = [];
          foreach ($monthlyData as $row) {
              $labels[]  = htmlspecialchars($row['month_label']);
              $amounts[] = (float)$row['monthly_total'];
          }
          $ptCount = count($amounts);
         $maxAmount = !empty($amounts) ? max($amounts) : 1000;
          $niceMax   = max((int)(ceil($maxAmount / 1000) * 1000), 1000);
          $step      = (int)($niceMax / 4);
          $yTicks    = [
              $niceMax,
              $niceMax - $step,
              $niceMax - $step * 2,
              $niceMax - $step * 3,
              0
          ];
          $maxY    = $niceMax;
          $chartH  = 180;           // px height of plot area
        ?>

        <div class="sales-chart-area">
          <div class="chart-graph">

            <!-- Y-axis labels -->
            <div class="y-axis">
              <?php foreach ($yTicks as $t): ?>
            <span>
              <?php
                if ($t >= 1000000)      echo '₱' . number_format($t / 1000000, 1) . 'M';
                elseif ($t >= 1000)     echo '₱' . number_format($t / 1000, 0) . 'k';
                else                    echo '₱' . number_format($t, 0);
              ?>
            </span>
              <?php endforeach; ?>
            </div>

            <!-- Plot area -->
            <div class="chart-plot">
              <!-- Grid lines (5 lines matching 5 Y-ticks) -->
              <div class="grid-lines">
                <?php for ($i = 0; $i < 5; $i++): ?>
                  <div class="grid-line"></div>
                <?php endfor; ?>
              </div>

              <!-- SVG line chart overlay -->
              <?php if ($ptCount >= 2): ?>
              <svg class="chart-svg" viewBox="0 0 400 <?= $chartH ?>" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                  <linearGradient id="lineGrad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="rgba(37,131,194,0.25)"/>
                    <stop offset="100%" stop-color="rgba(37,131,194,0)"/>
                  </linearGradient>
                </defs>
                <?php
                  $pts = [];
                  for ($i = 0; $i < $ptCount; $i++) {
                      $x     = ($i / ($ptCount - 1)) * 400;
                      $y     = $chartH - ($amounts[$i] / $maxY) * $chartH;
                      $pts[] = [$x, $y];
                  }
                  $polyline = implode(' ', array_map(fn($p) => "{$p[0]},{$p[1]}", $pts));
                  $areaPath = "M {$pts[0][0]},{$chartH} "
                            . implode(' ', array_map(fn($p) => "L {$p[0]},{$p[1]}", $pts))
                            . " L {$pts[$ptCount-1][0]},{$chartH} Z";
                ?>
                <path d="<?= $areaPath ?>" fill="url(#lineGrad)"/>
                <polyline points="<?= $polyline ?>" fill="none" stroke="#2583c2" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
                <?php foreach ($pts as $p): ?>
                  <circle cx="<?= $p[0] ?>" cy="<?= $p[1] ?>" r="5" fill="#fff" stroke="#2583c2" stroke-width="2.5"/>
                <?php endforeach; ?>
              </svg>

              <?php elseif ($ptCount === 1): ?>
              <svg class="chart-svg" viewBox="0 0 400 <?= $chartH ?>" xmlns="http://www.w3.org/2000/svg">
                <?php $y = $chartH - ($amounts[0] / $maxY) * $chartH; ?>
                <circle cx="200" cy="<?= $y ?>" r="5" fill="#fff" stroke="#2583c2" stroke-width="2.5"/>
              </svg>
              <?php endif; ?>

            </div><!-- /chart-plot -->
          </div><!-- /chart-graph -->

          <!-- X-axis labels -->
          <div class="x-labels">
            <?php if (!empty($labels)): ?>
              <?php foreach ($labels as $lbl): ?>
                <span><?= $lbl ?></span>
              <?php endforeach; ?>
            <?php else: ?>
              <span style="color: var(--color-gray-200); font-style: italic;">No data yet</span>
            <?php endif; ?>
          </div>
        </div><!-- /sales-chart-area -->
      </div><!-- /Monthly Sales -->

    </div><!-- /middle-row -->

    <!-- ── CUSTOMERS CREDIT ── -->
    <div class="section-container">
      <h2 class="section-title">Customers Credit</h2>
      <div class="customer-cards-row">

        <!-- Total Customers -->
        <div class="cust-card cream-bg">
          <div class="cust-header">
            <div class="cust-icon-wrap">
              <div class="cust-icon-bg" style="background-color: rgba(62,44,35,0.8);"></div>
              <span class="cust-icon">
                <!--
                  NOTE: Replace with your customers icon image, e.g.:
                  <img src="./public/Vector1.svg" width="22" height="22" alt="Customers"/>
                  Fallback Bootstrap Icon shown below:
                -->
                <i class="bi bi-people-fill"></i>
              </span>
            </div>
            <span class="cust-label">Total Customers</span>
          </div>
          <div class="cust-value-wrap">
            <p class="cust-value"><?= (int)($custRow['total_customers'] ?? 0) ?> customers</p>
          </div>
        </div>

        <!-- Unsettled Accounts -->
        <div class="cust-card red-bg">
          <div class="cust-header">
            <div class="cust-icon-wrap">
              <div class="cust-icon-bg" style="background-color: var(--color-tomato);"></div>
              <span class="cust-icon">
                <!--
                  NOTE: Replace with your unsettled accounts icon image, e.g.:
                  <img src="./public/Group.svg" width="22" height="22" alt="Unsettled"/>
                  Fallback Bootstrap Icon shown below:
                -->
                <i class="bi bi-clock-history"></i>
              </span>
            </div>
            <span class="cust-label">Unsettled Customers</span>
          </div>
          <div class="cust-value-wrap">
            <p class="cust-value red"><?= (int)($custRow['unsettled_count'] ?? 0) ?> Customers</p>
          </div>
        </div>

        <!-- Total Amount on Credit -->
        <div class="cust-card cream-bg">
          <div class="cust-header">
            <div class="cust-icon-wrap">
              <div class="cust-icon-bg" style="background-color: rgba(62,44,35,0.8);"></div>
              <span class="cust-icon">
                <!--
                  NOTE: Replace with your credit card icon image, e.g.:
                  <img src="./public/Vector2.svg" width="22" height="22" alt="Credit"/>
                  Fallback Bootstrap Icon shown below:
                -->
                <i class="bi bi-credit-card-fill"></i>
              </span>
            </div>
            <span class="cust-label">Total Amount on Credit</span>
          </div>
          <div class="cust-value-wrap">
            <p class="cust-value">₱<?= number_format((float)($custRow['total_credit'] ?? 0), 0) ?></p>
          </div>
        </div>

      </div>
    </div><!-- /Customers Credit -->

  </div><!-- /main-body -->
</div><!-- /page-wrapper -->

</body>
</html>