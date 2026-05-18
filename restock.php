<?php
// ============================================================
//  restock.php
//  Requirements: Prepared statements, try-catch, session guard
//  Uses INDEX on product_name/sku for search performance
// ============================================================
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once './utils/lhdb.php';

$store_id = (int) $_SESSION['store_id'];
$message  = '';
$error    = '';

// ── Handle restock POST ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id    = (int)   ($_POST['product_id']    ?? 0);
    $qty_added     = (int)   ($_POST['qty_added']     ?? 0);
    $notes         = trim($_POST['notes'] ?? '');

    if ($product_id > 0 && $qty_added > 0) {
        try {
            $pdo = getPDO();
            $pdo->beginTransaction();

            // Update stock (Prepared Statement)
            $updStmt = $pdo->prepare(
                "UPDATE products
                 SET stock_quantity = stock_quantity + :qty
                 WHERE id = :id AND store_id = :store_id"
            );
            $updStmt->execute([
                ':qty'      => $qty_added,
                ':id'       => $product_id,
                ':store_id' => $store_id,
            ]);

            // Log the restock
            $logStmt = $pdo->prepare(
                "INSERT INTO restock_log (product_id, quantity_added, restock_date, notes)
                 VALUES (:product_id, :qty, NOW(), :notes)"
            );
            $logStmt->execute([
                ':product_id' => $product_id,
                ':qty'        => $qty_added,
                ':notes'      => $notes,
            ]);

            $pdo->commit();
            $message = 'Stock updated successfully.';

        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log("Restock error: " . $e->getMessage());
            $error = 'A database error occurred. Please try again.';
        }
    } else {
        $error = 'Please select a product and enter a valid quantity.';
    }
}

// ── Fetch product list for display ──────────────────────────
$tab    = $_GET['tab']    ?? 'all';
$search = trim($_GET['search'] ?? '');

try {
    $pdo = getPDO();

    // Uses INDEX idx_products_name and idx_products_sku
    $sql = "SELECT p.id, p.product_name, p.sku, p.stock_quantity,
                   p.cost_price, p.selling_price, p.expiry_date,
                   COALESCE(c.category_name, 'Uncategorized') AS category_name,
                   COALESCE(SUM(r.quantity_added), 0) AS total_restocked
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN restock_log r ON r.product_id = p.id
            WHERE p.store_id = :store_id";

    $params = [':store_id' => $store_id];

    if ($tab === 'low') {
        $sql .= " AND p.stock_quantity > 0 AND p.stock_quantity <= 5";
    } elseif ($tab === 'out') {
        $sql .= " AND p.stock_quantity = 0";
    }

    if (!empty($search)) {
        $sql .= " AND (p.product_name LIKE :search OR p.sku LIKE :search2)";
        $params[':search']  = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
    }

    $sql .= " GROUP BY p.id ORDER BY p.product_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    // Counts for tab badges using COUNT() in DB
    $countStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= 5 THEN 1 ELSE 0 END) AS low,
            SUM(CASE WHEN stock_quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock
         FROM products WHERE store_id = :store_id"
    );
    $countStmt->execute([':store_id' => $store_id]);
    $counts = $countStmt->fetch();

} catch (PDOException $e) {
    error_log("Restock fetch error: " . $e->getMessage());
    $products = [];
    $counts   = ['total' => 0, 'low' => 0, 'out_of_stock' => 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Restock – ListaHub</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --sidebar-bg:#4a4a4a; --sidebar-width:240px; --main-bg:#f4f5f7; --nav-item:#6b6b6b; --nav-text:#ddd; --nav-active:#e8e8e8; }
    body { font-family:'Sora',sans-serif; display:flex; min-height:100vh; background:var(--main-bg); }
    aside { width:var(--sidebar-width); background:var(--sidebar-bg); display:flex; flex-direction:column; padding:20px 16px; gap:8px; position:fixed; top:0; left:0; bottom:0; border-radius:0 16px 16px 0; z-index:10; }
    .sidebar-logo { background:#6b6b6b; color:#eee; text-align:center; font-weight:700; font-size:15px; padding:12px; border-radius:12px; margin-bottom:10px; }
    .sidebar-section-label { color:#aaa; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.8px; padding:8px 8px 2px; }
    .sidebar-item { background:var(--nav-item); color:var(--nav-text); border-radius:12px; padding:11px 16px; font-size:14px; font-weight:600; cursor:pointer; border:none; text-align:left; width:100%; transition:background .2s; text-decoration:none; display:block; }
    .sidebar-item:hover { background:#7a7a7a; }
    .sidebar-item.active { background:var(--nav-active); color:#111; }
    .sidebar-logout { margin-top:auto; background:#616161; color:#ddd; border-radius:12px; padding:11px 16px; font-size:14px; font-weight:600; cursor:pointer; border:none; width:100%; transition:background .2s; }
    .sidebar-logout:hover { background:#7a7a7a; }
    .main-content { margin-left:var(--sidebar-width); flex:1; padding:36px 40px; display:flex; flex-direction:column; gap:24px; }
    .topbar { display:flex; justify-content:flex-end; }
    .user-card { background:#e8e8e8; border-radius:12px; padding:10px 16px; display:flex; align-items:center; gap:12px; }
    .user-info { text-align:right; }
    .user-name { font-weight:700; font-size:14px; color:#222; }
    .user-store { font-size:12px; color:#666; }
    .user-avatar { width:40px; height:40px; background:#888; border-radius:50%; }
    .page-title { font-size:26px; font-weight:800; color:#111; }
    .alert { padding:12px 16px; border-radius:10px; font-size:13px; font-weight:600; }
    .alert-success { background:#d1fae5; color:#065f46; }
    .alert-error   { background:#fee2e2; color:#991b1b; }
    .restock-section { background:#d8dadd; border-radius:16px; padding:18px 20px; display:flex; flex-direction:column; gap:14px; }
    .restock-toolbar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .search-box { display:flex; align-items:center; background:#fff; border-radius:50px; padding:8px 16px; gap:8px; flex:1; max-width:300px; }
    .search-box input { border:none; outline:none; font-family:'Sora',sans-serif; font-size:13px; width:100%; }
    .filter-tabs { display:flex; gap:8px; }
    .tab { background:#ccc; border:none; border-radius:50px; padding:8px 20px; font-family:'Sora',sans-serif; font-size:13px; font-weight:600; cursor:pointer; color:#333; text-decoration:none; transition:background .2s; }
    .tab.active { background:#6b6b6b; color:#eee; }
    .tab:hover:not(.active) { background:#bbb; }
    .search-btn { background:#6b6b6b; color:#fff; border:none; border-radius:50px; padding:8px 18px; font-family:'Sora',sans-serif; font-size:13px; font-weight:600; cursor:pointer; }
    table { width:100%; border-collapse:collapse; background:#e8eaed; border-radius:10px; overflow:hidden; }
    thead th { padding:12px 14px; text-align:left; font-size:13px; font-weight:700; color:#222; background:#e0e2e6; }
    tbody tr { border-top:1px solid #d0d2d6; }
    tbody tr:hover { background:#dfe1e5; }
    tbody td { padding:12px 14px; font-size:13px; color:#333; }
    .restock-qty { width:70px; background:#c8c8c8; border:none; border-radius:8px; padding:7px 10px; font-family:'Sora',sans-serif; font-size:13px; text-align:center; }
    .btn-restock { background:#4a4a4a; color:#fff; border:none; border-radius:8px; padding:7px 14px; font-family:'Sora',sans-serif; font-size:12px; font-weight:600; cursor:pointer; white-space:nowrap; }
    .btn-restock:hover { background:#333; }
    .status-low { color:#b45309; font-weight:600; }
    .status-out { color:#b91c1c; font-weight:600; }
    .status-in  { color:#15803d; font-weight:600; }
    .empty-state { text-align:center; color:#aaa; padding:40px 0; font-size:14px; }
  </style>
</head>
<body>
<aside>
  <div class="sidebar-logo">Logo</div>
  <a class="sidebar-item" href="dashboard.php">Dashboard</a>
  <div class="sidebar-section-label">Inventory</div>
  <a class="sidebar-item" href="manage-products.php">Manage Products</a>
  <a class="sidebar-item active" href="restock.php">Restock</a>
  <div class="sidebar-section-label">Sales</div>
  <a class="sidebar-item" href="sales.php">Sales Analytics</a>
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

  <div class="page-title">Restock</div>

  <?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="restock-section">
    <div class="restock-toolbar">
      <form method="get" style="display:flex;align-items:center;gap:8px;flex:1;max-width:320px;">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>"/>
        <div class="search-box">
          <span><i class="bi bi-box-seam"></i></span>
          <input type="text" name="search" placeholder="Search…" value="<?= htmlspecialchars($search) ?>"/>
        </div>
        <button type="submit" class="search-btn">Go</button>
      </form>
      <div class="filter-tabs">
        <a class="tab <?= $tab === 'all' ? 'active' : '' ?>" href="restock.php?tab=all<?= !empty($search) ? '&search='.urlencode($search) : '' ?>">
          All Products (<?= (int)$counts['total'] ?>)
        </a>
        <a class="tab <?= $tab === 'low' ? 'active' : '' ?>" href="restock.php?tab=low<?= !empty($search) ? '&search='.urlencode($search) : '' ?>">
          <i class="bi bi-exclamation-triangle"></i> Low Stock (<?= (int)$counts['low'] ?>)
        </a>
        <a class="tab <?= $tab === 'out' ? 'active' : '' ?>" href="restock.php?tab=out<?= !empty($search) ? '&search='.urlencode($search) : '' ?>">
          <i class="bi bi-exclamation-triangle-fill"></i> Out of Stock (<?= (int)$counts['out_of_stock'] ?>)
        </a>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Product Name</th><th>SKU</th><th>Category</th>
          <th>Current Stock</th><th>Status</th><th>Add Qty</th><th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($products)): ?>
          <tr><td colspan="7" class="empty-state">No products found.</td></tr>
        <?php else: ?>
          <?php foreach ($products as $p):
            if ($p['stock_quantity'] == 0) { $sClass = 'status-out'; $sLabel = 'Out of Stock'; }
            elseif ($p['stock_quantity'] <= 5) { $sClass = 'status-low'; $sLabel = 'Low Stock'; }
            else { $sClass = 'status-in'; $sLabel = 'In Stock'; }
          ?>
          <tr>
            <td><?= htmlspecialchars($p['product_name']) ?></td>
            <td><?= htmlspecialchars($p['sku']) ?></td>
            <td><?= htmlspecialchars($p['category_name']) ?></td>
            <td><?= (int)$p['stock_quantity'] ?> pcs</td>
            <td><span class="<?= $sClass ?>"><?= $sLabel ?></span></td>
            <td>
              <form method="post" style="display:flex;align-items:center;gap:8px;">
                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"/>
                <input class="restock-qty" type="number" name="qty_added" min="1" value="1" required/>
                <input type="hidden" name="notes" value="Manual restock"/>
                <button type="submit" class="btn-restock">Restock</button>
              </form>
            </td>
            <td>Total restocked: <?= (int)$p['total_restocked'] ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>