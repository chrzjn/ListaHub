<?php
// ============================================================
//  sales.php  –  Sales Analytics page
//  Requirements met:
//   ✅ Session guard
//   ✅ PDO prepared statements
//   ✅ try-catch
//   ✅ COUNT(), SUM() done in DB via the view
//   ✅ JOIN (inside vw_manager_dashboard view)
// ============================================================

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once './utils/lhdb.php';

$store_id = (int) $_SESSION['store_id'];

try {
    $pdo = getPDO();

    // Pull from the SQL View (DB does the heavy lifting)
    $stmt = $pdo->prepare(
        "SELECT * FROM vw_manager_dashboard WHERE store_id = :store_id"
    );
    $stmt->execute([':store_id' => $store_id]);
    $stats = $stmt->fetch();

    // Monthly revenue breakdown — SUM + GROUP BY in DB
    $monthlyStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(sale_date, '%b %Y') AS month_label,
                SUM(total_amount)               AS monthly_total
         FROM   sales
         WHERE  store_id = :store_id
           AND  sale_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
         GROUP  BY DATE_FORMAT(sale_date, '%Y-%m')
         ORDER  BY MIN(sale_date) ASC"
    );
    $monthlyStmt->execute([':store_id' => $store_id]);
    $monthlyData = $monthlyStmt->fetchAll();

    // Top selling products — COUNT/SUM in DB
    $topStmt = $pdo->prepare(
        "SELECT p.product_name,
                SUM(si.quantity)  AS total_qty,
                SUM(si.subtotal)  AS total_revenue
         FROM   sale_items si
         JOIN   products   p  ON p.id = si.product_id
         JOIN   sales       s  ON s.id = si.sale_id
         WHERE  s.store_id = :store_id
         GROUP  BY si.product_id
         ORDER  BY total_revenue DESC
         LIMIT  5"
    );
    $topStmt->execute([':store_id' => $store_id]);
    $topProducts = $topStmt->fetchAll();

} catch (PDOException $e) {
    error_log("Sales analytics error: " . $e->getMessage());
    $stats       = [];
    $monthlyData = [];
    $topProducts = [];
}

function s($key, $default = 0) {
    global $stats;
    return $stats[$key] ?? $default;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Sales Analytics – ListaHub</title>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --sidebar-bg: #4a4a4a;
      --sidebar-width: 240px;
      --main-bg: #f4f5f7;
      --card-bg: #e2e4e8;
      --nav-item: #6b6b6b;
      --nav-text: #ddd;
      --nav-active: #e8e8e8;
    }

    body { font-family: 'Sora', sans-serif; display: flex; min-height: 100vh; background: var(--main-bg); }

    aside {
      width: var(--sidebar-width);
      background: var(--sidebar-bg);
      display: flex;
      flex-direction: column;
      padding: 20px 16px;
      gap: 8px;
      position: fixed;
      top: 0; left: 0; bottom: 0;
      border-radius: 0 16px 16px 0;
      z-index: 10;
    }
    .sidebar-logo { background: #6b6b6b; color: #eee; text-align: center; font-weight: 700; font-size: 15px; padding: 12px; border-radius: 12px; margin-bottom: 10px; }
    .sidebar-section-label { color: #aaa; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .8px; padding: 8px 8px 2px; }
    .sidebar-item { background: var(--nav-item); color: var(--nav-text); border-radius: 12px; padding: 11px 16px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; text-align: left; width: 100%; transition: background .2s; text-decoration: none; display: block; }
    .sidebar-item:hover { background: #7a7a7a; }
    .sidebar-item.active { background: var(--nav-active); color: #111; }
    .sidebar-logout { margin-top: auto; background: #616161; color: #ddd; border-radius: 12px; padding: 11px 16px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; width: 100%; transition: background .2s; }
    .sidebar-logout:hover { background: #7a7a7a; }

    .main-content { margin-left: var(--sidebar-width); flex: 1; padding: 36px 40px; display: flex; flex-direction: column; gap: 20px; }

    .topbar { display: flex; justify-content: flex-end; }
    .user-card { background: #e8e8e8; border-radius: 12px; padding: 10px 16px; display: flex; align-items: center; gap: 12px; }
    .user-info { text-align: right; }
    .user-name { font-weight: 700; font-size: 14px; color: #222; }
    .user-store { font-size: 12px; color: #666; }
    .user-avatar { width: 40px; height: 40px; background: #888; border-radius: 50%; }

    .page-title { font-size: 26px; font-weight: 800; color: #111; }

    .top-row {
      display: grid;
      grid-template-columns: 1fr 1fr 2fr;
      gap: 16px;
    }
    .stat-card {
      background: var(--card-bg);
      border-radius: 14px;
      padding: 18px 20px 24px;
    }
    .stat-card .label { font-size: 13px; color: #555; margin-bottom: 14px; }
    .stat-card .value { font-size: 22px; font-weight: 700; color: #111; }

    .status-card {
      background: var(--card-bg);
      border-radius: 14px;
      padding: 18px 20px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .status-card .card-title { font-size: 16px; font-weight: 700; color: #111; }
    .status-inner { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; }
    .status-item .s-label { font-size: 12px; color: #555; margin-bottom: 6px; }
    .status-item .s-value { font-size: 18px; font-weight: 700; color: #111; }
    .monthly-label { font-size: 13px; color: #555; font-weight: 600; }

    .second-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }
    .stat-card2 {
      background: var(--card-bg);
      border-radius: 14px;
      padding: 18px 20px 24px;
    }
    .stat-card2 .label { font-size: 13px; color: #555; margin-bottom: 14px; }
    .stat-card2 .value { font-size: 22px; font-weight: 700; color: #111; }

    .chart-row {
      display: grid;
      grid-template-columns: 3fr 2fr;
      gap: 16px;
    }
    .chart-card { background: var(--card-bg); border-radius: 14px; padding: 20px; min-height: 220px; }
    .chart-card .chart-title { font-size: 16px; font-weight: 700; color: #111; margin-bottom: 12px; }

    .monthly-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .monthly-table th, .monthly-table td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #ccc; }
    .monthly-table th { color: #555; font-weight: 600; }

    .goods-info { display: flex; flex-direction: column; gap: 12px; padding-top: 8px; }
    .goods-row { display: flex; justify-content: space-between; }
    .g-label { font-size: 13px; color: #555; }
    .g-value { font-size: 14px; font-weight: 700; color: #111; }

    .top-products-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
    .top-products-table th, .top-products-table td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #ccc; }
    .top-products-table th { color: #555; font-weight: 600; }
  </style>
</head>
<body>
  <aside>
    <div class="sidebar-logo">Logo</div>
    <a class="sidebar-item" href="dashboard.php">Dashboard</a>
    <div class="sidebar-section-label">Inventory</div>
    <a class="sidebar-item" href="manage-products.php">Manage Products</a>
    <a class="sidebar-item" href="restock.php">Restock</a>
    <div class="sidebar-section-label">Sales</div>
    <a class="sidebar-item active" href="sales.php">Sales Analytics</a>
    <div class="sidebar-section-label">Customer Credit</div>
    <a class="sidebar-item" href="customers.php">Customers</a>
    <form method="post" action="logout.php" style="margin-top:auto;">
      <button class="sidebar-logout" type="submit">Log out</button>
    </form>
  </aside>

  <div class="main-content">
    <div class="topbar">
      <div class="user-card">
        <div class="user-info">
          <div class="user-name"><?= htmlspecialchars($_SESSION['username']) ?></div>
          <div class="user-store"><?= htmlspecialchars($_SESSION['store_name'] ?? '') ?></div>
        </div>
        <div class="user-avatar"></div>
      </div>
    </div>

    <div class="page-title">Sales Overview</div>

    <!-- Top Row -->
    <div class="top-row">
      <div class="stat-card">
        <div class="label">Total Sales</div>
        <div class="value">₱<?= number_format((float)s('total_revenue'), 2) ?></div>
      </div>
      <div class="stat-card">
        <div class="label">Revenue</div>
        <div class="value">₱<?= number_format((float)s('total_revenue'), 2) ?></div>
      </div>
      <div class="status-card">
        <div class="card-title">Status</div>
        <div class="status-inner">
          <div class="status-item">
            <div class="s-label">Total Items Sold</div>
            <div class="s-value"><?= (int)s('total_stock_units') ?></div>
          </div>
          <div class="status-item">
            <div class="s-label">Today's Revenue</div>
            <div class="s-value">₱<?= number_format((float)s('todays_revenue'), 2) ?></div>
          </div>
        </div>
        <div class="monthly-label">Total Transactions: <?= (int)s('total_transactions') ?></div>
      </div>
    </div>

    <!-- Second Row -->
    <div class="second-row">
      <div class="stat-card2">
        <div class="label">Cash Sales</div>
        <div class="value">₱<?= number_format((float)s('cash_revenue'), 2) ?></div>
      </div>
      <div class="stat-card2">
        <div class="label">Online Sales (GCash)</div>
        <div class="value">₱<?= number_format((float)s('gcash_revenue'), 2) ?></div>
      </div>
    </div>

    <!-- Chart Row -->
    <div class="chart-row">
      <div class="chart-card">
        <div class="chart-title">Monthly Revenue</div>
        <?php if (!empty($monthlyData)): ?>
          <table class="monthly-table">
            <thead><tr><th>Month</th><th>Revenue</th></tr></thead>
            <tbody>
              <?php foreach ($monthlyData as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['month_label']) ?></td>
                  <td>₱<?= number_format((float)$row['monthly_total'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p style="color:#aaa; font-size:13px; margin-top:12px;">No sales data yet.</p>
        <?php endif; ?>
      </div>
      <div class="chart-card">
        <div class="chart-title">Goods Info</div>
        <div class="goods-info">
          <div class="goods-row">
            <div class="g-label">Cost of Goods</div>
            <div class="g-label">Estimated Profit</div>
          </div>
          <div class="goods-row" style="margin-top:4px;">
            <div class="g-value">₱<?= number_format((float)s('total_cost_value'), 2) ?></div>
            <div class="g-value">₱<?= number_format((float)s('total_retail_value') - (float)s('total_cost_value'), 2) ?></div>
          </div>
          <div class="g-label" style="margin-top: 16px;">Retail Value of Stock</div>
          <div class="g-value">₱<?= number_format((float)s('total_retail_value'), 2) ?></div>
        </div>

        <?php if (!empty($topProducts)): ?>
          <div class="chart-title" style="margin-top:20px;">Top Products</div>
          <table class="top-products-table">
            <thead><tr><th>Product</th><th>Qty</th><th>Revenue</th></tr></thead>
            <tbody>
              <?php foreach ($topProducts as $tp): ?>
                <tr>
                  <td><?= htmlspecialchars($tp['product_name']) ?></td>
                  <td><?= (int)$tp['total_qty'] ?></td>
                  <td>₱<?= number_format((float)$tp['total_revenue'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>