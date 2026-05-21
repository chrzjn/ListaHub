<?php
// ============================================================
//  manage-products.php
//  CRUD: Read, Delete products. Add/Edit open as popup pages.
//  Requirements: Prepared statements, try-catch, session guard
// ============================================================
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once './utils/lhdb.php';

$user_id = (int) $_SESSION['user_id'];
$message = '';
$error   = '';

// ── Handle POST: DELETE only (Add/Update handled in their own pages) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $pdo = getPDO();

        if ($action === 'delete') {
            $product_id = (int) ($_POST['product_id'] ?? 0);
            if ($product_id) {
                $del = $pdo->prepare(
                    "DELETE FROM Product WHERE product_id = :id AND user_id = :user_id"
                );
                $del->execute([':id' => $product_id, ':user_id' => $user_id]);
                $message = 'Product deleted successfully.';
            }
        }

    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log("Manage products delete error: " . $e->getMessage());
        $error = 'A database error occurred. Please try again.';
    }
}

// ── Fetch data for display ───────────────────────────────────
try {
    $pdo             = getPDO();
    $search          = trim($_GET['search']          ?? '');
    $category_filter = trim($_GET['category_filter'] ?? '');

    // Total product count
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM Product WHERE user_id = :user_id"
    );
    $countStmt->execute([':user_id' => $user_id]);
    $total_products = (int) $countStmt->fetchColumn();

    // Stat cards
    $statsStmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock,
            SUM(CASE WHEN expiration_date < CURDATE() AND expiration_date IS NOT NULL THEN 1 ELSE 0 END) AS expired,
            SUM(CASE WHEN quantity > 0 AND quantity < low_stock_threshold THEN 1 ELSE 0 END) AS low_stock,
            SUM(CASE WHEN expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS near_expiry
         FROM Product WHERE user_id = :user_id"
    );
    $statsStmt->execute([':user_id' => $user_id]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Product list
    $sql = "SELECT p.product_id, p.product_name, p.sku, p.quantity, p.cost_price,
                   p.retail_price, p.expiration_date, p.status,
                   COALESCE(c.category_name, 'Uncategorized') AS category_name
            FROM Product p
            LEFT JOIN Category c ON c.category_id = p.category_id
            WHERE p.user_id = :user_id";
    $params = [':user_id' => $user_id];

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
    $products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

    // Category list for this user
    $catListStmt = $pdo->prepare(
        "SELECT DISTINCT c.category_name
         FROM Category c
         JOIN Product p ON p.category_id = c.category_id
         WHERE p.user_id = :user_id
         ORDER BY c.category_name"
    );
    $catListStmt->execute([':user_id' => $user_id]);
    $categories = $catListStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Manage products fetch error: " . $e->getMessage());
    $products       = [];
    $stats          = [];
    $categories     = [];
    $total_products = 0;
}

// ── Status badge helper ──────────────────────────────────────
function statusBadgeClass(string $status): string {
    return match (strtolower(trim($status))) {
        'in stock'     => 'badge-in-stock',
        'low stock'    => 'badge-low-stock',
        'out of stock' => 'badge-out-stock',
        'near expiry'  => 'badge-near-exp',
        'expired'      => 'badge-expired',
        default        => 'badge-in-stock',
    };
}

$activePage = 'manage-products';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Products – ListaHub</title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Roboto:wght@400;500;600&display=swap" rel="stylesheet"/>

  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"/>

  <!--
    Load order (DO NOT reorder):
      1. global_sidebar.css           — body reset + sidebar CSS variables
      2. global_manage-products.css   — page-specific CSS variables
      3. sidebar.css                  — sidebar component styles
      4. manage-products.css          — page layout + component styles
  -->
  <link rel="stylesheet" href="global_sidebar.css"/>
  <link rel="stylesheet" href="global_manage-products.css"/>
  <link rel="stylesheet" href="sidebar.css"/>
  <link rel="stylesheet" href="manage-products.css"/>
</head>
<body>

<div class="page-wrapper">

  <!-- ============================================================
       SIDEBAR
       sidebar.php reads $activePage to highlight "Manage Products".
       Make sure sidebar.php, sidebar.css, and global_sidebar.css
       are in the same directory as this file.
       ============================================================ -->
  <?php $activePage = 'manage-products'; include 'sidebar.php'; ?>

  <!-- ============================================================
       MAIN BODY
       ============================================================ -->
  <div class="main-body">

    <?php if ($message): ?>
      <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- ── OVERVIEW: stat cards ── -->
    <section class="overview">
      <h1 class="page-title">INVENTORY</h1>

      <div class="cards-container">

        <!-- Out of Stocks -->
        <div class="stat-card card-red">
          <div class="stat-icon icon-red">
            <!--
              NOTE: Replace the <i> below with your icon image, e.g.:
              <img src="./pics_icons/out-of-stock-icon.png" width="33" height="33" alt="Out of Stock"/>
            -->
            <i class="bi bi-box-seam" style="font-size:28px;"></i>
          </div>
          <div class="stat-text">
            <span class="stat-label">Out of Stocks</span>
            <span class="stat-value"><?= (int)($stats['out_of_stock'] ?? 0) ?> Products</span>
          </div>
        </div>

        <!-- Expired Products -->
        <div class="stat-card card-orange">
          <div class="stat-icon icon-orange">
            <!--
              NOTE: Replace the <i> below with your icon image, e.g.:
              <img src="./pics_icons/expired-icon.png" width="33" height="33" alt="Expired"/>
            -->
            <i class="bi bi-x-circle" style="font-size:28px;"></i>
          </div>
          <div class="stat-text">
            <span class="stat-label">Expired Products</span>
            <span class="stat-value"><?= (int)($stats['expired'] ?? 0) ?> Products</span>
          </div>
        </div>

        <!-- Low on Stock -->
        <div class="stat-card card-yellow">
          <div class="stat-icon icon-gray">
            <!--
              NOTE: Replace the <i> below with your icon image, e.g.:
              <img src="./pics_icons/low-stock-icon.png" width="33" height="33" alt="Low Stock"/>
            -->
            <i class="bi bi-arrow-down-circle" style="font-size:28px;"></i>
          </div>
          <div class="stat-text">
            <span class="stat-label">Low on Stock</span>
            <span class="stat-value"><?= (int)($stats['low_stock'] ?? 0) ?> Product<?= ((int)($stats['low_stock'] ?? 0)) !== 1 ? 's' : '' ?></span>
          </div>
        </div>

        <!-- Near Expiry -->
        <div class="stat-card card-blue">
          <div class="stat-icon icon-blue">
            <!--
              NOTE: Replace the <i> below with your icon image, e.g.:
              <img src="./pics_icons/near-expiry-icon.png" width="33" height="33" alt="Near Expiry"/>
            -->
            <i class="bi bi-exclamation-circle" style="font-size:28px;"></i>
          </div>
          <div class="stat-text">
            <span class="stat-label">Near Expiry</span>
            <span class="stat-value"><?= (int)($stats['near_expiry'] ?? 0) ?> product<?= ((int)($stats['near_expiry'] ?? 0)) !== 1 ? 's' : '' ?></span>
          </div>
        </div>

      </div>
    </section><!-- /overview -->

    <!-- ── TABLE SECTION ── -->
    <div class="container2">

      <!-- Action bar -->
      <div class="table-actions">

        <!-- Top row: Search + Add Product + Bulk Restock -->
        <div class="top-row">

          <form method="get" style="flex:1;max-width:260px;display:flex;align-items:center;gap:10px;">
            <?php if (!empty($category_filter)): ?>
              <input type="hidden" name="category_filter" value="<?= htmlspecialchars($category_filter) ?>"/>
            <?php endif; ?>
            <div class="searchbar">
              <!--
                NOTE: Replace with your search icon image, e.g.:
                <img class="searchbar-icon" src="./pics_icons/magnifying-glass-1.svg" width="20" height="20" alt="Search"/>
              -->
              <i class="bi bi-search searchbar-icon"></i>
              <input
                type="text"
                name="search"
                placeholder="Search product name"
                value="<?= htmlspecialchars($search) ?>"
                autocomplete="off"
              />
            </div>
          </form>

          <div class="action-btns">

            <!--
              Add Product button — opens add_prod.php as a popup window.
            -->
            <button class="btn-add-product" type="button" onclick="openAddProductPopup()">
              <!--
                NOTE: Replace with your icon image, e.g.:
                <img src="./pics_icons/gg-add.svg" class="btn-icon" alt=""/>
              -->
              <i class="bi bi-plus-circle btn-icon"></i>
              <span>Add Product</span>
            </button>

            <!-- Bulk Restock — navigates to restock.php -->
            <a href="restock.php" class="btn-outline">
              <!--
                NOTE: Replace with your icon image, e.g.:
                <img src="./pics_icons/basil-box-outline.svg" class="btn-icon" alt=""/>
              -->
              <i class="bi bi-box btn-icon"></i>
              <span>Bulk Restock</span>
            </a>

            <!-- Export Inventory -->
            <button class="btn-outline" type="button" onclick="exportInventory()">
              <!--
                NOTE: Replace with your icon image, e.g.:
                <img src="./pics_icons/material-symbols-file-export-outline-rounded.svg" class="btn-icon" alt=""/>
              -->
              <i class="bi bi-download btn-icon"></i>
              <span>Export Inventory</span>
            </button>

          </div>
        </div><!-- /top-row -->

        <!-- Filter row: Count + Category -->
        <div class="filter-row">
          <div class="filter-left">

            <!-- Products count -->
            <div class="count-box">
              <span class="count-label">Products Count :</span>
              <span class="count-val"><?= $total_products ?></span>
            </div>

            <!-- Category dropdown filter -->
            <form method="get" style="display:flex;">
              <?php if (!empty($search)): ?>
                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"/>
              <?php endif; ?>
              <div class="category-btn">
                <select name="category_filter" class="category-select" onchange="this.form.submit()">
                  <option value="">Category</option>
                  <?php foreach ($categories as $cat): ?>
                    <option
                      value="<?= htmlspecialchars($cat['category_name']) ?>"
                      <?= ($category_filter === $cat['category_name']) ? 'selected' : '' ?>
                    >
                      <?= htmlspecialchars($cat['category_name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <!--
                  NOTE: Replace with your dropdown arrow icon image, e.g.:
                  <img src="./pics_icons/ri-arrow-drop-down-line.svg" width="24" height="24" alt=""/>
                -->
                <i class="bi bi-chevron-down" style="font-size:14px;color:var(--1-brown);pointer-events:none;"></i>
              </div>
            </form>

          </div><!-- /filter-left -->
        </div><!-- /filter-row -->

      </div><!-- /table-actions -->

      <!-- Table -->
      <div class="table-wrap">
        <div class="table-inner">
          <div class="table2">

            <!-- THEAD (always visible, not scrolled) -->
            <div class="thead">
              <div class="thead-row">
                <b class="col-name">Product Name</b>
                <b class="col-sku">SKU</b>
                <b class="col-category">Category</b>
                <b class="col-stock">Stock</b>
                <b class="col-expiry">Expiry Date</b>
                <b class="col-price">Price</b>
                <b class="col-status">Status</b>
                <b class="col-actions">Actions</b>
              </div>
              <div class="thead-divider"></div>
            </div>

            <!-- TBODY (scrollable — scrollbar appears only when list is long) -->
            <div class="tbody-scroll">
              <div class="tbody" id="inv-tbody">
                <?php if (empty($products)): ?>
                  <p class="no-products-msg">No products found. Add a product to get started.</p>
                <?php else: ?>
                  <?php foreach ($products as $p):
                    $badgeClass  = statusBadgeClass($p['status'] ?? '');
                    $statusLabel = htmlspecialchars($p['status'] ?? 'In Stock');
                    $expiryFmt   = $p['expiration_date']
                      ? date('m/d/y', strtotime($p['expiration_date']))
                      : '—';
                  ?>
                  <div class="tbody-row" data-hidden="">
                    <div class="tbody-row-content">
                      <span class="col-name col-name-val"><?= htmlspecialchars($p['product_name']) ?></span>
                      <span class="col-sku  col-sku-val"><?= htmlspecialchars($p['sku'] ?? '—') ?></span>
                      <span class="col-category col-category-val"><?= htmlspecialchars($p['category_name']) ?></span>
                      <span class="col-stock col-stock-val"><?= (int)$p['quantity'] ?></span>
                      <span class="col-expiry col-expiry-val"><?= $expiryFmt ?></span>
                      <span class="col-price col-price-val">₱ <?= number_format((float)$p['retail_price'], 0) ?></span>
                      <div class="col-status col-status-val">
                        <span class="status-badge <?= $badgeClass ?>"><?= $statusLabel ?></span>
                      </div>
                      <div class="col-actions col-actions-val">

                        <!-- Eye / Edit button — opens update_prod.php as a popup window -->
                        <button
                          class="action-btn"
                          type="button"
                          title="View / Edit"
                          onclick="openEditPopup(<?= (int)$p['product_id'] ?>)"
                        >
                          <!--
                            NOTE: Replace with your view icon image, e.g.:
                            <img src="./pics_icons/view.svg" width="18" height="21" alt="View"/>
                          -->
                          <i class="bi bi-eye"></i>
                        </button>

                        <!-- Delete button -->
                        <form class="delete-form" method="post"
                              onsubmit="return confirm('Delete this product?')">
                          <input type="hidden" name="action" value="delete"/>
                          <input type="hidden" name="product_id" value="<?= (int)$p['product_id'] ?>"/>
                          <button class="action-btn delete-btn" type="submit" title="Delete">
                            <!--
                              NOTE: Replace with your trash icon image, e.g.:
                              <img src="./pics_icons/trash.svg" width="22" height="20" alt="Delete"/>
                            -->
                            <i class="bi bi-trash3"></i>
                          </button>
                        </form>

                      </div>
                    </div>
                    <div class="row-divider"></div>
                  </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div><!-- /tbody -->
            </div><!-- /tbody-scroll -->

          </div><!-- /table2 -->
        </div><!-- /table-inner -->

        <!-- Right edge scrollbar indicator strip -->
        <div class="table-scroll-indicator"></div>
      </div><!-- /table-wrap -->

      <!-- Pagination -->
      <div class="pagination">
        <button class="btn-page" id="btn-prev" type="button">
          <!--
            NOTE: Replace with your arrow icon image, e.g.:
            <img src="./pics_icons/tabler-arrow-left.svg" width="18" height="18" alt="Prev"/>
          -->
          <i class="bi bi-arrow-left"></i>
          <span>Prev</span>
        </button>
        <button class="btn-page" id="btn-next" type="button">
          <span>Next</span>
          <!--
            NOTE: Replace with your arrow icon image, e.g.:
            <img src="./pics_icons/mingcute-arrow-right-line.svg" width="18" height="18" alt="Next"/>
          -->
          <i class="bi bi-arrow-right"></i>
        </button>
      </div>

    </div><!-- /container2 -->

  </div><!-- /main-body -->
</div><!-- /page-wrapper -->

<script>
'use strict';

/* ─────────────────────────────────────────────────────────────
   Popup helpers
───────────────────────────────────────────────────────────── */

/**
 * Open add_prod.php as a centred popup window.
 * When the popup closes, this page reloads so the new product appears.
 */
function openAddProductPopup() {
  var w    = 820;
  var h    = 700;
  var left = Math.round((screen.width  - w) / 2);
  var top  = Math.round((screen.height - h) / 2);
  var popup = window.open(
    'add_prod.php',
    'add_product_popup',
    'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top +
    ',resizable=yes,scrollbars=yes'
  );
  var timer = setInterval(function () {
    if (popup && popup.closed) {
      clearInterval(timer);
      window.location.reload();
    }
  }, 600);
}

/**
 * Open update_prod.php?product_id=X as a centred popup window.
 * When the popup closes, this page reloads to reflect any changes.
 */
function openEditPopup(productId) {
  var w    = 820;
  var h    = 700;
  var left = Math.round((screen.width  - w) / 2);
  var top  = Math.round((screen.height - h) / 2);
  var popup = window.open(
    'update_prod.php?product_id=' + productId,
    'edit_product_popup',
    'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top +
    ',resizable=yes,scrollbars=yes'
  );
  var timer = setInterval(function () {
    if (popup && popup.closed) {
      clearInterval(timer);
      window.location.reload();
    }
  }, 600);
}

/* ─────────────────────────────────────────────────────────────
   Client-side search filter
   Filters visible rows in real-time without a page reload.
   Works in tandem with server-side ?search= for initial load.
───────────────────────────────────────────────────────────── */
var searchInput = document.querySelector('.searchbar input');
if (searchInput) {
  searchInput.addEventListener('input', function () {
    var q = this.value.toLowerCase().trim();
    var rows = document.querySelectorAll('#inv-tbody .tbody-row');
    rows.forEach(function (row) {
      var name = (row.querySelector('.col-name-val') || {}).textContent || '';
      var sku  = (row.querySelector('.col-sku-val')  || {}).textContent || '';
      row.dataset.hidden = (q && !name.toLowerCase().includes(q) && !sku.toLowerCase().includes(q)) ? '1' : '';
    });
    currentPage = 1;
    renderPage();
  });
}

/* ─────────────────────────────────────────────────────────────
   Pagination
   Shows ROWS_PER_PAGE rows at a time; scrollbar appears inside
   the tbody-scroll div when the full list is displayed.
───────────────────────────────────────────────────────────── */
var currentPage   = 1;
var ROWS_PER_PAGE = 10;

function renderPage() {
  var allRows = Array.from(document.querySelectorAll('#inv-tbody .tbody-row'));
  var visible = allRows.filter(function (r) { return r.dataset.hidden !== '1'; });
  var total   = visible.length;
  var maxPage = Math.max(1, Math.ceil(total / ROWS_PER_PAGE));
  if (currentPage > maxPage) currentPage = maxPage;

  var start = (currentPage - 1) * ROWS_PER_PAGE;
  var end   = start + ROWS_PER_PAGE;

  allRows.forEach(function (r)    { r.style.display = 'none'; });
  visible.forEach(function (r, i) { r.style.display = (i >= start && i < end) ? '' : 'none'; });

  var btnPrev = document.getElementById('btn-prev');
  var btnNext = document.getElementById('btn-next');
  if (btnPrev) btnPrev.disabled = currentPage <= 1;
  if (btnNext) btnNext.disabled = currentPage >= maxPage;
}

var btnPrev = document.getElementById('btn-prev');
var btnNext = document.getElementById('btn-next');

if (btnPrev) {
  btnPrev.addEventListener('click', function () {
    if (currentPage > 1) { currentPage--; renderPage(); }
  });
}

if (btnNext) {
  btnNext.addEventListener('click', function () {
    currentPage++;
    renderPage();
  });
}

/* ─────────────────────────────────────────────────────────────
   Export Inventory
───────────────────────────────────────────────────────────── */
function exportInventory() {
  window.location.href = 'export_inventory.php';
}

/* ─────────────────────────────────────────────────────────────
   Init
───────────────────────────────────────────────────────────── */
renderPage();
</script>
</body>
</html>