<?php
// ============================================================
//  dashboard.php
//  Requirements met:
//   ✅ SQL View (vw_manager_dashboard) used for reporting
//   ✅ COUNT(), SUM() done in DB — not PHP loops
//   ✅ PDO prepared statement
//   ✅ try-catch
//   ✅ Session-based auth guard
// ============================================================

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once './utils/lhdb.php';

try {
    $pdo = getPDO();

    // ── Pull everything from the VIEW (DB does all the heavy lifting) ──
    // Requirement: Use the SQL View for the manager dashboard
    $stmt = $pdo->prepare(
        "SELECT * FROM vw_manager_dashboard WHERE store_id = :store_id"
    );
    $stmt->execute([':store_id' => $_SESSION['store_id']]);
    $stats = $stmt->fetch();

    // ── Monthly revenue breakdown (SUM + GROUP BY in DB) ──────────────
    $monthlyStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(sale_date, '%b %Y') AS month_label,
                SUM(total_amount)               AS monthly_total
         FROM   sales
         WHERE  store_id = :store_id
           AND  sale_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
         GROUP  BY DATE_FORMAT(sale_date, '%Y-%m')
         ORDER  BY MIN(sale_date) ASC"
    );
    $monthlyStmt->execute([':store_id' => $_SESSION['store_id']]);
    $monthlyData = $monthlyStmt->fetchAll();

} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $stats       = [];
    $monthlyData = [];
}

// Helper: safely get a value from $stats
function getStat(string $key, $default = 0) {
    global $stats;
    return $stats[$key] ?? $default;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard – ListaHub</title>
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
    body { font-family:'Sora',sans-serif; display:flex; min-height:100vh; background:var(--main-bg); }

    aside {
      width:var(--sidebar-width); background:var(--sidebar-bg);
      display:flex; flex-direction:column; padding:20px 16px; gap:8px;
      position:fixed; top:0; left:0; bottom:0;
      border-radius:0 16px 16px 0; z-index:10;
    }
    .sidebar-logo { background:#6b6b6b; color:#eee; text-align:center; font-weight:700; font-size:15px; padding:12px; border-radius:12px; margin-bottom:10px; }
    .sidebar-label { color:#aaa; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.8px; padding:8px 8px 2px; }
    .sidebar-item { background:var(--nav-item); color:var(--nav-text); border-radius:12px; padding:11px 16px; font-size:14px; font-weight:600; text-decoration:none; display:block; transition:background .2s; }
    .sidebar-item:hover { background:#7a7a7a; }
    .sidebar-item.active { background:var(--nav-active); color:#111; }
    .sidebar-logout { margin-top:auto; background:#616161; color:#ddd; border-radius:12px; padding:11px 16px; font-size:14px; font-weight:600; cursor:pointer; border:none; width:100%; text-align:left; }
    .sidebar-logout:hover { background:#7a7a7a; }

    .main-content { margin-left:var(--sidebar-width); flex:1; padding:36px 40px; display:flex; flex-direction:column; gap:24px; }

    .topbar { display:flex; justify-content:flex-end; }
    .user-card { background:#e8e8e8; border-radius:12px; padding:10px 16px; display:flex; align-items:center; gap:12px; }
    .user-name { font-weight:700; font-size:14px; color:#222; }
    .user-store { font-size:12px; color:#666; }
    .user-avatar { width:40px; height:40px; background:#888; border-radius:50%; }

    .section-title { font-size:22px; font-weight:800; color:#111; }

    .cards-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; }
    .stat-card { background:var(--card-bg); border-radius:14px; padding:18px 20px 24px; }
    .stat-card .label { font-size:13px; color:#555; margin-bottom:14px; }
    .stat-card .value { font-size:26px; font-weight:700; color:#111; }

    .sales-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; }
    .charts-row { display:grid; grid-template-columns:3fr 2fr; gap:16px; }
    .chart-card { background:var(--card-bg); border-radius:14px; padding:20px; min-height:180px; }
    .chart-title { font-size:16px; font-weight:700; color:#111; margin-bottom:12px; }

    .monthly-table { width:100%; border-collapse:collapse; font-size:13px; }
    .monthly-table th, .monthly-table td { padding:8px 10px; text-align:left; border-bottom:1px solid #ccc; }
    .monthly-table th { color:#555; font-weight:600; }
  </style>
</head>
<body>
<aside>
  <div class="sidebar-logo">Logo</div>
  <a class="sidebar-item active" href="dashboard.php">Dashboard</a>
  <div class="sidebar-label">Inventory</div>
  <a class="sidebar-item" href="manage-products.php">Manage Products</a>
  <a class="sidebar-item" href="restock.php">Restock</a>
  <div class="sidebar-label">Sales</div>
  <a class="sidebar-item" href="sales.php">Sales Analytics</a>
  <div class="sidebar-label">Customer Credit</div>
  <a class="sidebar-item" href="customers.php">Customers</a>
  <form method="post" action="logout.php" style="margin-top:auto;">
    <button class="sidebar-logout" type="submit">Log out</button>
  </form>
</aside>

<div class="main-content">
  <div class="topbar">
    <div class="user-card">
      <div>
        <div class="user-name"><?= htmlspecialchars($_SESSION['username']) ?></div>
        <div class="user-store"><?= htmlspecialchars($_SESSION['store_name'] ?? '') ?></div>
      </div>
      <div class="user-avatar"></div>
    </div>
  </div>

  <div class="section-title">Products Overview</div>
  <div class="cards-grid">
    <div class="stat-card"><div class="label">Out of Stock</div>
      <div class="value"><?= (int)getStat('out_of_stock_count') ?> Products</div></div>
    <div class="stat-card"><div class="label">Expired Products</div>
      <div class="value"><?= (int)getStat('expired_count') ?> Products</div></div>
    <div class="stat-card"><div class="label">Low Stock Products</div>
      <div class="value"><?= (int)getStat('low_stock_count') ?> Products</div></div>
    <div class="stat-card"><div class="label">Near Expiration</div>
      <div class="value"><?= (int)getStat('near_expiry_count') ?> Products</div></div>
  </div>

  <div class="section-title">Sales Overview</div>
  <div class="sales-grid">
    <div class="stat-card"><div class="label">Total Sales</div>
      <div class="value"><?= (int)getStat('total_transactions') ?></div></div>
    <div class="stat-card"><div class="label">Revenue</div>
      <div class="value">₱<?= number_format((float)getStat('total_revenue'), 2) ?></div></div>
    <div class="stat-card"><div class="label">Total Items Sold</div>
      <div class="value"><?= (int)getStat('total_stock_units') ?></div></div>
    <div class="stat-card"><div class="label">Today's Revenue</div>
      <div class="value">₱<?= number_format((float)getStat('todays_revenue'), 2) ?></div></div>
  </div>

  <div class="charts-row">
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
        <p style="color:#aaa;font-size:13px;">No sales data yet.</p>
      <?php endif; ?>
    </div>
    <div class="chart-card">
      <div class="chart-title">Inventory Status</div>
      <p style="font-size:13px;color:#555;">Total Products: <strong><?= (int)getStat('total_products') ?></strong></p>
      <p style="font-size:13px;color:#555;margin-top:8px;">Retail Value: <strong>₱<?= number_format((float)getStat('total_retail_value'),2) ?></strong></p>
      <p style="font-size:13px;color:#555;margin-top:8px;">Cost Value: <strong>₱<?= number_format((float)getStat('total_cost_value'),2) ?></strong></p>
    </div>
  </div>
</div>
</body>
</html>
