<?php
// ============================================================
//  manage-products.php
//  CRUD: Create, Read, Update, Delete products
//  Requirements: Prepared statements, try-catch, session guard
// ============================================================
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit;
}

require_once './utils/lhdb.php';

$store_id = (int) $_SESSION['store_id'];
$message  = '';
$error    = '';

// ── Handle POST actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $pdo = getPDO();

        // ── ADD PRODUCT ───────────────────────────────────────
        if ($action === 'add') {
            $product_name  = trim($_POST['product_name']  ?? '');
            $category_name = trim($_POST['category']      ?? '');
            $sku           = trim($_POST['sku']           ?? '');
            $expiry_date   = $_POST['expiry_date']        ?? null;
            $no_expiry     = isset($_POST['no_expiry']);
            $stock_qty     = (int)   ($_POST['stock_quantity'] ?? 0);
            $cost_price    = (float) ($_POST['cost']           ?? 0);
            $selling_price = (float) ($_POST['selling_price']  ?? 0);
            $notes         = trim($_POST['notes']         ?? '');

            if (empty($product_name) || empty($sku)) {
                $error = 'Product name and SKU are required.';
            } else {
                $pdo->beginTransaction();

                // Resolve or create category
                $cat_id = null;
                if (!empty($category_name)) {
                    $catStmt = $pdo->prepare(
                        "SELECT id FROM categories WHERE store_id = :store_id AND category_name = :name LIMIT 1"
                    );
                    $catStmt->execute([':store_id' => $store_id, ':name' => $category_name]);
                    $cat = $catStmt->fetch();
                    if ($cat) {
                        $cat_id = $cat['id'];
                    } else {
                        $insCAT = $pdo->prepare(
                            "INSERT INTO categories (store_id, category_name) VALUES (:store_id, :name)"
                        );
                        $insCAT->execute([':store_id' => $store_id, ':name' => $category_name]);
                        $cat_id = (int) $pdo->lastInsertId();
                    }
                }

                $final_expiry = ($no_expiry || empty($expiry_date)) ? null : $expiry_date;

                $ins = $pdo->prepare(
                    "INSERT INTO products
                        (store_id, category_id, product_name, sku, stock_quantity, cost_price, selling_price, expiry_date, notes)
                     VALUES
                        (:store_id, :category_id, :product_name, :sku, :stock_quantity, :cost_price, :selling_price, :expiry_date, :notes)"
                );
                $ins->execute([
                    ':store_id'       => $store_id,
                    ':category_id'    => $cat_id,
                    ':product_name'   => $product_name,
                    ':sku'            => $sku,
                    ':stock_quantity' => $stock_qty,
                    ':cost_price'     => $cost_price,
                    ':selling_price'  => $selling_price,
                    ':expiry_date'    => $final_expiry,
                    ':notes'          => $notes,
                ]);

                $pdo->commit();
                $message = 'Product added successfully.';
            }
        }

        // ── UPDATE PRODUCT ────────────────────────────────────
        if ($action === 'update') {
            $product_id    = (int)   ($_POST['product_id']    ?? 0);
            $product_name  = trim($_POST['product_name']  ?? '');
            $category_name = trim($_POST['category']      ?? '');
            $sku           = trim($_POST['sku']           ?? '');
            $expiry_date   = $_POST['expiry_date']        ?? null;
            $no_expiry     = isset($_POST['no_expiry']);
            $cost_price    = (float) ($_POST['cost']           ?? 0);
            $selling_price = (float) ($_POST['selling_price']  ?? 0);
            $notes         = trim($_POST['notes']         ?? '');

            if ($product_id && !empty($product_name)) {
                $pdo->beginTransaction();

                $cat_id = null;
                if (!empty($category_name)) {
                    $catStmt = $pdo->prepare(
                        "SELECT id FROM categories WHERE store_id = :store_id AND category_name = :name LIMIT 1"
                    );
                    $catStmt->execute([':store_id' => $store_id, ':name' => $category_name]);
                    $cat = $catStmt->fetch();
                    if ($cat) {
                        $cat_id = $cat['id'];
                    } else {
                        $insCAT = $pdo->prepare(
                            "INSERT INTO categories (store_id, category_name) VALUES (:store_id, :name)"
                        );
                        $insCAT->execute([':store_id' => $store_id, ':name' => $category_name]);
                        $cat_id = (int) $pdo->lastInsertId();
                    }
                }

                $final_expiry = ($no_expiry || empty($expiry_date)) ? null : $expiry_date;

                $upd = $pdo->prepare(
                    "UPDATE products
                     SET product_name   = :product_name,
                         category_id    = :category_id,
                         sku            = :sku,
                         cost_price     = :cost_price,
                         selling_price  = :selling_price,
                         expiry_date    = :expiry_date,
                         notes          = :notes
                     WHERE id = :id AND store_id = :store_id"
                );
                $upd->execute([
                    ':product_name'  => $product_name,
                    ':category_id'   => $cat_id,
                    ':sku'           => $sku,
                    ':cost_price'    => $cost_price,
                    ':selling_price' => $selling_price,
                    ':expiry_date'   => $final_expiry,
                    ':notes'         => $notes,
                    ':id'            => $product_id,
                    ':store_id'      => $store_id,
                ]);

                $pdo->commit();
                $message = 'Product updated successfully.';
            }
        }

        // ── DELETE PRODUCT ────────────────────────────────────
        if ($action === 'delete') {
            $product_id = (int) ($_POST['product_id'] ?? 0);
            if ($product_id) {
                $del = $pdo->prepare(
                    "DELETE FROM products WHERE id = :id AND store_id = :store_id"
                );
                $del->execute([':id' => $product_id, ':store_id' => $store_id]);
                $message = 'Product deleted.';
            }
        }

    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log("Manage products error: " . $e->getMessage());
        $error = 'A database error occurred. Please try again.';
    }
}

// ── Fetch data for display ───────────────────────────────────
try {
    $pdo = getPDO();

    $search = trim($_GET['search'] ?? '');
    $category_filter = trim($_GET['category_filter'] ?? '');

    // COUNT(), JOIN, INDEX on product_name used here
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total FROM products WHERE store_id = :store_id"
    );
    $countStmt->execute([':store_id' => $store_id]);
    $total_products = (int) $countStmt->fetchColumn();

    // Stat cards using SUM() / COUNT() in DB
    $statsStmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN stock_quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock,
            SUM(CASE WHEN expiry_date < CURDATE() AND expiry_date IS NOT NULL THEN 1 ELSE 0 END) AS expired,
            SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= 5 THEN 1 ELSE 0 END) AS low_stock,
            SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS near_expiry
         FROM products WHERE store_id = :store_id"
    );
    $statsStmt->execute([':store_id' => $store_id]);
    $stats = $statsStmt->fetch();

    // Product list with JOIN to categories
    $sql = "SELECT p.id, p.product_name, p.sku, p.stock_quantity, p.cost_price,
                   p.selling_price, p.expiry_date, p.notes,
                   COALESCE(c.category_name, '') AS category_name
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE p.store_id = :store_id";
    $params = [':store_id' => $store_id];

    if (!empty($search)) {
        $sql .= " AND (p.product_name LIKE :search OR p.sku LIKE :search2)";
        $params[':search']  = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
    }
    if (!empty($category_filter)) {
        $sql .= " AND c.category_name = :cat_filter";
        $params[':cat_filter'] = $category_filter;
    }
    $sql .= " ORDER BY p.product_name ASC";

    $prodStmt = $pdo->prepare($sql);
    $prodStmt->execute($params);
    $products = $prodStmt->fetchAll();

    // Categories for filter dropdown
    $catListStmt = $pdo->prepare(
        "SELECT DISTINCT category_name FROM categories WHERE store_id = :store_id ORDER BY category_name"
    );
    $catListStmt->execute([':store_id' => $store_id]);
    $categories = $catListStmt->fetchAll();

} catch (PDOException $e) {
    error_log("Manage products fetch error: " . $e->getMessage());
    $products = [];
    $stats = [];
    $categories = [];
    $total_products = 0;
}

function getStatus(array $p): array {
    if ($p['stock_quantity'] == 0) return ['Out of Stock', 'status-out'];
    if ($p['stock_quantity'] <= 5) return ['Low Stock', 'status-low'];
    return ['In Stock', 'status-in'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Products – ListaHub</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --sidebar-bg: #4a4a4a; --sidebar-width: 240px; --main-bg: #f4f5f7;
      --card-bg: #e2e4e8; --table-bg: #d8dadd; --nav-item: #6b6b6b;
      --nav-text: #ddd; --nav-active: #e8e8e8; --nav-text-active: #111;
    }
    body { font-family: 'Sora', sans-serif; display: flex; min-height: 100vh; background: var(--main-bg); }
    aside { width: var(--sidebar-width); background: var(--sidebar-bg); display: flex; flex-direction: column; padding: 20px 16px; gap: 8px; position: fixed; top: 0; left: 0; bottom: 0; border-radius: 0 16px 16px 0; z-index: 10; }
    .sidebar-logo { background: #6b6b6b; color: #eee; text-align: center; font-weight: 700; font-size: 15px; padding: 12px; border-radius: 12px; margin-bottom: 10px; }
    .sidebar-section-label { color: #aaa; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .8px; padding: 8px 8px 2px; }
    .sidebar-item { background: var(--nav-item); color: var(--nav-text); border-radius: 12px; padding: 11px 16px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; text-align: left; width: 100%; transition: background .2s; text-decoration: none; display: block; }
    .sidebar-item:hover { background: #7a7a7a; }
    .sidebar-item.active { background: var(--nav-active); color: var(--nav-text-active); }
    .sidebar-logout { margin-top: auto; background: #616161; color: #ddd; border-radius: 12px; padding: 11px 16px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; width: 100%; transition: background .2s; }
    .sidebar-logout:hover { background: #7a7a7a; }
    .main-content { margin-left: var(--sidebar-width); flex: 1; padding: 36px 40px; display: flex; flex-direction: column; gap: 24px; }
    .topbar { display: flex; justify-content: flex-end; }
    .user-card { background: #e8e8e8; border-radius: 12px; padding: 10px 16px; display: flex; align-items: center; gap: 12px; }
    .user-info { text-align: right; }
    .user-name { font-weight: 700; font-size: 14px; color: #222; }
    .user-store { font-size: 12px; color: #666; }
    .user-avatar { width: 40px; height: 40px; background: #888; border-radius: 50%; }
    .page-title { font-size: 26px; font-weight: 800; color: #111; }
    .alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; font-weight: 600; }
    .alert-success { background: #d1fae5; color: #065f46; }
    .alert-error   { background: #fee2e2; color: #991b1b; }
    .stat-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
    .stat-card { background: var(--card-bg); border-radius: 14px; padding: 14px 18px 20px; }
    .stat-card .label { font-size: 12px; color: #555; margin-bottom: 10px; border-bottom: 2px solid #bbb; padding-bottom: 6px; }
    .stat-card .value { font-size: 24px; font-weight: 700; color: #111; }
    .table-section { background: var(--table-bg); border-radius: 16px; padding: 18px 20px; display: flex; flex-direction: column; gap: 14px; }
    .table-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .search-form { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 160px; }
    .search-box { display: flex; align-items: center; background: #fff; border-radius: 50px; padding: 8px 16px; gap: 8px; flex: 1; }
    .search-box input { border: none; outline: none; font-family: 'Sora', sans-serif; font-size: 13px; width: 100%; }
    .tool-btn { background: #fff; border: 1.5px solid #ccc; border-radius: 50px; padding: 7px 16px; font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: background .2s; }
    .tool-btn:hover { background: #f0f0f0; }
    .btn-primary { background: #fff; border: 2px solid #333; font-weight: 700; }
    .table-filters { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .count-badge { background: #c8c8c8; border-radius: 50px; padding: 6px 14px; font-size: 12px; font-weight: 600; color: #333; }
    .select-btn { background: #d0d0d0; border: none; border-radius: 50px; padding: 7px 14px; font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 600; cursor: pointer; }
    table { width: 100%; border-collapse: collapse; background: #e8eaed; border-radius: 10px; overflow: hidden; }
    thead th { padding: 12px 14px; text-align: left; font-size: 13px; font-weight: 700; color: #222; background: #e0e2e6; }
    tbody tr { border-top: 1px solid #d0d2d6; }
    tbody tr:hover { background: #dfe1e5; }
    tbody td { padding: 12px 14px; font-size: 13px; color: #333; }
    .status-badge { font-size: 12px; font-weight: 600; }
    .status-low { color: #b45309; }
    .status-in  { color: #15803d; }
    .status-out { color: #b91c1c; }
    .action-icons { display: flex; gap: 10px; align-items: center; }
    .icon-btn { background: none; border: none; cursor: pointer; font-size: 18px; color: #444; padding: 2px 4px; border-radius: 6px; transition: background .2s; }
    .icon-btn:hover { background: #ccc; }
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 100; justify-content: center; align-items: center; }
    .modal-overlay.open { display: flex; }
    .modal { background: #9a9a9a; border-radius: 16px; width: 760px; max-width: 95vw; overflow: hidden; box-shadow: 0 16px 60px rgba(0,0,0,.3); }
    .modal-header { background: #7a7a7a; padding: 22px 28px; }
    .modal-header h2 { font-size: 22px; font-weight: 800; color: #111; }
    .modal-body { padding: 24px 28px; display: flex; flex-direction: column; gap: 18px; }
    .modal-top-row { display: flex; gap: 20px; align-items: flex-start; }
    .fields-grid { flex: 1; display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; }
    .field-group { display: flex; flex-direction: column; gap: 4px; }
    .field-label { font-size: 12px; font-weight: 600; color: #222; }
    .field-input { background: #c8c8c8; border: none; border-radius: 8px; padding: 10px 14px; font-family: 'Sora', sans-serif; font-size: 13px; outline: none; width: 100%; }
    .field-input:focus { background: #bbb; }
    .checkbox-row { display: flex; align-items: center; gap: 6px; margin-top: 2px; }
    .checkbox-row label { font-size: 11px; color: #333; }
    .bottom-fields { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px 20px; }
    .notes-area { background: #c8c8c8; border: none; border-radius: 8px; padding: 10px 14px; font-family: 'Sora', sans-serif; font-size: 13px; resize: vertical; min-height: 80px; outline: none; width: 100%; }
    .modal-footer { padding: 16px 28px; display: flex; justify-content: flex-end; gap: 12px; }
    .btn-cancel { background: none; border: none; font-family: 'Sora', sans-serif; font-size: 14px; font-weight: 600; color: #222; cursor: pointer; padding: 10px 16px; border-radius: 8px; }
    .btn-cancel:hover { background: #bbb; }
    .btn-save { background: #e8e8e8; border: none; border-radius: 50px; padding: 11px 28px; font-family: 'Sora', sans-serif; font-size: 14px; font-weight: 700; color: #111; cursor: pointer; transition: background .2s; }
    .btn-save:hover { background: #d4d4d4; }
    .delete-form { display: inline; }
  </style>
</head>
<body>
<aside>
  <div class="sidebar-logo">Logo</div>
  <a class="sidebar-item" href="dashboard.php">Dashboard</a>
  <div class="sidebar-section-label">Inventory</div>
  <a class="sidebar-item active" href="manage-products.php">Manage Products</a>
  <a class="sidebar-item" href="restock.php">Restock</a>
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

  <div class="page-title">Inventory Overview</div>

  <?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="stat-row">
    <div class="stat-card"><div class="label">Out of Stock</div><div class="value"><?= (int)($stats['out_of_stock'] ?? 0) ?> Products</div></div>
    <div class="stat-card"><div class="label">Expired Products</div><div class="value"><?= (int)($stats['expired'] ?? 0) ?> Products</div></div>
    <div class="stat-card"><div class="label">Low Stock Products</div><div class="value"><?= (int)($stats['low_stock'] ?? 0) ?> Products</div></div>
    <div class="stat-card"><div class="label">Near Expiration</div><div class="value"><?= (int)($stats['near_expiry'] ?? 0) ?> Products</div></div>
  </div>

  <div class="table-section">
    <div class="table-toolbar">
      <form class="search-form" method="get">
        <div class="search-box">
          <span><i class="bi bi-box-seam"></i></span>
          <input type="text" name="search" placeholder="Search products…" value="<?= htmlspecialchars($search) ?>"/>
        </div>
        <button type="submit" class="tool-btn">Search</button>
      </form>
      <button class="tool-btn btn-primary" onclick="openModal('add')">＋ Add Product</button>
    </div>

    <div class="table-filters">
      <div class="count-badge">Products Count: <?= $total_products ?></div>
      <?php if (!empty($categories)): ?>
      <form method="get" style="display:inline;">
        <?php if (!empty($search)): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
        <select name="category_filter" class="select-btn" onchange="this.form.submit()">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat['category_name']) ?>"
              <?= ($category_filter === $cat['category_name']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($cat['category_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>
    </div>

    <table>
      <thead>
        <tr>
          <th>Product Name</th><th>SKU</th><th>Category</th>
          <th>In Stock</th><th>SRP</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($products)): ?>
          <tr><td colspan="7" style="text-align:center;color:#aaa;padding:40px 0;">No products found. Add a product to get started.</td></tr>
        <?php else: ?>
          <?php foreach ($products as $p):
            [$statusLabel, $statusClass] = getStatus($p);
          ?>
          <tr>
            <td><?= htmlspecialchars($p['product_name']) ?></td>
            <td><?= htmlspecialchars($p['sku']) ?></td>
            <td><?= htmlspecialchars($p['category_name']) ?></td>
            <td><?= (int)$p['stock_quantity'] ?> pcs</td>
            <td>₱<?= number_format((float)$p['selling_price'], 2) ?></td>
            <td><span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
            <td class="action-icons">
              <button class="icon-btn" title="Edit"
                onclick="openEditModal(<?= htmlspecialchars(json_encode($p)) ?>)">
                <i class="bi bi-eye-fill"></i>
              </button>
              <form class="delete-form" method="post"
                    onsubmit="return confirm('Delete this product?')">
                <input type="hidden" name="action" value="delete"/>
                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"/>
                <button type="submit" class="icon-btn" title="Delete">
                  <i class="bi bi-trash-fill"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ADD PRODUCT MODAL -->
<div class="modal-overlay" id="modal-add">
  <div class="modal">
    <div class="modal-header"><h2>Add New Product</h2></div>
    <form method="post">
      <input type="hidden" name="action" value="add"/>
      <div class="modal-body">
        <div class="fields-grid" style="grid-template-columns:1fr 1fr;">
          <div class="field-group"><label class="field-label">Product Name *</label><input class="field-input" type="text" name="product_name" required/></div>
          <div class="field-group"><label class="field-label">Category</label><input class="field-input" type="text" name="category" list="cat-list"/></div>
          <div class="field-group"><label class="field-label">SKU *</label><input class="field-input" type="text" name="sku" required/></div>
          <div class="field-group">
            <label class="field-label">Expiry Date</label>
            <input class="field-input" type="date" name="expiry_date" id="add-expiry"/>
            <div class="checkbox-row"><input type="checkbox" name="no_expiry" id="no-exp" onchange="document.getElementById('add-expiry').disabled=this.checked"/><label for="no-exp">No expiration date</label></div>
          </div>
        </div>
        <div class="bottom-fields">
          <div class="field-group"><label class="field-label">Stock Quantity</label><input class="field-input" type="number" name="stock_quantity" min="0" value="0"/></div>
          <div class="field-group"><label class="field-label">Cost Price (₱)</label><input class="field-input" type="number" name="cost" step="0.01" min="0" value="0"/></div>
          <div class="field-group"><label class="field-label">Selling Price (₱)</label><input class="field-input" type="number" name="selling_price" step="0.01" min="0" value="0"/></div>
        </div>
        <div class="field-group"><label class="field-label">Notes</label><textarea class="notes-area" name="notes"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('add')">Cancel</button>
        <button type="submit" class="btn-save">Add Product</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT PRODUCT MODAL -->
<div class="modal-overlay" id="modal-edit">
  <div class="modal">
    <div class="modal-header"><h2>Update Product</h2></div>
    <form method="post" id="edit-form">
      <input type="hidden" name="action" value="update"/>
      <input type="hidden" name="product_id" id="edit-id"/>
      <div class="modal-body">
        <div class="fields-grid" style="grid-template-columns:1fr 1fr;">
          <div class="field-group"><label class="field-label">Product Name *</label><input class="field-input" type="text" name="product_name" id="edit-name" required/></div>
          <div class="field-group"><label class="field-label">Category</label><input class="field-input" type="text" name="category" id="edit-category" list="cat-list"/></div>
          <div class="field-group"><label class="field-label">SKU</label><input class="field-input" type="text" name="sku" id="edit-sku"/></div>
          <div class="field-group">
            <label class="field-label">Expiry Date</label>
            <input class="field-input" type="date" name="expiry_date" id="edit-expiry"/>
            <div class="checkbox-row"><input type="checkbox" name="no_expiry" id="no-exp2" onchange="document.getElementById('edit-expiry').disabled=this.checked"/><label for="no-exp2">No expiration date</label></div>
          </div>
        </div>
        <div class="bottom-fields" style="grid-template-columns:1fr 1fr;">
          <div class="field-group"><label class="field-label">Cost Price (₱)</label><input class="field-input" type="number" name="cost" id="edit-cost" step="0.01" min="0"/></div>
          <div class="field-group"><label class="field-label">Selling Price (₱)</label><input class="field-input" type="number" name="selling_price" id="edit-price" step="0.01" min="0"/></div>
        </div>
        <div class="field-group"><label class="field-label">Notes</label><textarea class="notes-area" name="notes" id="edit-notes"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('edit')">Cancel</button>
        <button type="submit" class="btn-save">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<datalist id="cat-list">
  <?php foreach ($categories as $cat): ?>
    <option value="<?= htmlspecialchars($cat['category_name']) ?>">
  <?php endforeach; ?>
</datalist>

<script>
function openModal(type) { document.getElementById('modal-' + type).classList.add('open'); }
function closeModal(type) { document.getElementById('modal-' + type).classList.remove('open'); }

function openEditModal(p) {
  document.getElementById('edit-id').value       = p.id;
  document.getElementById('edit-name').value     = p.product_name;
  document.getElementById('edit-category').value = p.category_name;
  document.getElementById('edit-sku').value      = p.sku;
  document.getElementById('edit-expiry').value   = p.expiry_date || '';
  document.getElementById('edit-cost').value     = p.cost_price;
  document.getElementById('edit-price').value    = p.selling_price;
  document.getElementById('edit-notes').value    = p.notes || '';
  openModal('edit');
}

document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});
</script>
</body>
</html>