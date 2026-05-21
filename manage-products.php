<?php
// ============================================================
//  manage-products.php
//  CRUD: Read, Delete products.
//  Add Product opens as an INLINE OVERLAY (no popup window).
//  Edit opens as popup page (update_prod.php).
//  Requirements: Prepared statements, try-catch, session guard
// ============================================================
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once './utils/lhdb.php';

$user_id       = (int) $_SESSION['user_id'];
$message       = '';
$error         = '';
$add_flash_msg = '';
$add_flash_type = '';

// ── Handle POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $pdo = getPDO();

        // ── DELETE ──
        if ($action === 'delete') {
            $product_id = (int) ($_POST['product_id'] ?? 0);
            if ($product_id) {
                $del = $pdo->prepare(
                    "DELETE FROM Product WHERE product_id = :id AND user_id = :user_id"
                );
                $del->execute([':id' => $product_id, ':user_id' => $user_id]);
                $message = 'Product deleted successfully.';
            }

        // ── ADD PRODUCT (inline overlay form) ──
        } elseif ($action === 'add') {
            $product_name  = trim($_POST['product_name']    ?? '');
            $category_name = trim($_POST['category']        ?? '');
            $expiry_date   = $_POST['expiry_date']           ?? null;
            $no_expiry     = isset($_POST['no_expiry']);
            $quantity      = (int)   ($_POST['stock_quantity'] ?? 0);
            $cost_price    = (float) ($_POST['cost']           ?? 0);
            $retail_price  = (float) ($_POST['selling_price']  ?? 0);
            $notes         = trim($_POST['notes']           ?? '');

            if (empty($product_name)) {
                $add_flash_msg  = 'Product name is required.';
                $add_flash_type = 'error';
            } else {
                $pdo->beginTransaction();

                // Resolve or create category
                $target_cat = !empty($category_name) ? $category_name : 'Uncategorized';
                $catStmt = $pdo->prepare(
                    "SELECT category_id FROM Category WHERE category_name = :name LIMIT 1"
                );
                $catStmt->execute([':name' => $target_cat]);
                $cat = $catStmt->fetch();
                if ($cat) {
                    $cat_id = $cat['category_id'];
                } else {
                    $insCAT = $pdo->prepare("INSERT INTO Category (category_name) VALUES (:name)");
                    $insCAT->execute([':name' => $target_cat]);
                    $cat_id = (int) $pdo->lastInsertId();
                }

                $final_expiry = ($no_expiry || empty($expiry_date)) ? null : $expiry_date;

                $ins = $pdo->prepare(
                    "INSERT INTO Product
                        (user_id, category_id, product_name, quantity, cost_price, retail_price, expiration_date, notes)
                     VALUES
                        (:user_id, :category_id, :product_name, :quantity, :cost_price, :retail_price, :expiry, :notes)"
                );
                $ins->execute([
                    ':user_id'      => $user_id,
                    ':category_id'  => $cat_id,
                    ':product_name' => $product_name,
                    ':quantity'     => $quantity,
                    ':cost_price'   => $cost_price,
                    ':retail_price' => $retail_price,
                    ':expiry'       => $final_expiry,
                    ':notes'        => $notes,
                ]);

                $new_id = (int) $pdo->lastInsertId();
                $pdo->commit();

                // Fetch auto-generated SKU
                $skuStmt = $pdo->prepare("SELECT sku FROM Product WHERE product_id = :id");
                $skuStmt->execute([':id' => $new_id]);
                $added_sku = $skuStmt->fetchColumn() ?: '';

                $add_flash_msg  = 'Product "' . htmlspecialchars($product_name) . '" added successfully!' .
                                  ($added_sku ? ' (SKU: ' . htmlspecialchars($added_sku) . ')' : '');
                $add_flash_type = 'success';
            }
        }

    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log("Manage products error: " . $e->getMessage());
        if ($action === 'add') {
            $add_flash_msg  = 'A database error occurred. Please try again.';
            $add_flash_type = 'error';
        } else {
            $error = 'A database error occurred. Please try again.';
        }
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

    // Category list for autocomplete + filter dropdown
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

// Whether to auto-open the overlay after a POST (add action)
$open_overlay = ($add_flash_type !== '') ? 'true' : 'false';

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
    Load order:
      1. global_sidebar.css          — body reset + sidebar CSS variables
      2. global_manage-products.css  — merged page + add_prod CSS variables
      3. sidebar.css                 — sidebar component styles
      4. manage-products.css         — merged page + add_prod overlay styles
  -->
  <link rel="stylesheet" href="global_sidebar.css"/>
  <link rel="stylesheet" href="global_manage-products.css"/>
  <link rel="stylesheet" href="sidebar.css"/>
  <link rel="stylesheet" href="manage-products.css"/>
</head>
<body>

<div class="page-wrapper">

  <!-- ════════════════════════════════
       SIDEBAR
       ════════════════════════════════ -->
  <?php $activePage = 'manage-products'; include 'sidebar.php'; ?>

  <!-- ════════════════════════════════
       MAIN BODY
       ════════════════════════════════ -->
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

        <div class="stat-card card-red">
          <div class="stat-icon icon-red">
            <img src="./pics_icons/out-of-stock (1).png" width="33" height="33" alt="Out of Stock"/>
          </div>
          <div class="stat-text">
            <span class="stat-label">Out of Stocks</span>
            <span class="stat-value"><?= (int)($stats['out_of_stock'] ?? 0) ?> Products</span>
          </div>
        </div>

        <div class="stat-card card-orange">
          <div class="stat-icon icon-orange">
            <img src="./pics_icons/expired.png" width="33" height="33" alt="Expired"/>
          </div>
          <div class="stat-text">
            <span class="stat-label">Expired Products</span>
            <span class="stat-value"><?= (int)($stats['expired'] ?? 0) ?> Products</span>
          </div>
        </div>

        <div class="stat-card card-yellow">
          <div class="stat-icon icon-gray">
            <img src="pics_icons/arrow-trend-down.png" width="33" alt="Low Stock"/>
          </div>
          <div class="stat-text">
            <span class="stat-label">Low on Stock</span>
            <span class="stat-value"><?= (int)($stats['low_stock'] ?? 0) ?> Product<?= ((int)($stats['low_stock'] ?? 0)) !== 1 ? 's' : '' ?></span>
          </div>
        </div>

        <div class="stat-card card-blue">
          <div class="stat-icon icon-blue">
            <img src="pics_icons/duration-alt.png" width="33" alt="Near Expiry"/>
          </div>
          <div class="stat-text">
            <span class="stat-label">Near Expiry</span>
            <span class="stat-value"><?= (int)($stats['near_expiry'] ?? 0) ?> product<?= ((int)($stats['near_expiry'] ?? 0)) !== 1 ? 's' : '' ?></span>
          </div>
        </div>

      </div>
    </section>

    <!-- ── TABLE SECTION ── -->
    <div class="container2">

      <div class="table-actions">

        <!-- Top row -->
        <div class="top-row">

          <form method="get" style="flex:1;max-width:260px;display:flex;align-items:center;gap:10px;">
            <?php if (!empty($category_filter)): ?>
              <input type="hidden" name="category_filter" value="<?= htmlspecialchars($category_filter) ?>"/>
            <?php endif; ?>
            <div class="searchbar">
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

            <!-- ★ Add Product → opens INLINE OVERLAY (no popup window) -->
            <button class="btn-add-product" type="button" onclick="openAddOverlay()">
              <img src="./pics_icons/add (1).svg" class="btn-icon" alt=""/>
              <span>Add Product</span>
            </button>

            <a href="restock.php" class="btn-outline">
              <img src="./pics_icons/supplies.png" class="btn-icon" alt=""/>
              <span>Bulk Restock</span>
            </a>

            <button class="btn-outline" type="button" onclick="exportInventory()">
              <i class="bi bi-download btn-icon"></i>
              <span>Export Inventory</span>
            </button>

          </div>
        </div>

        <!-- Filter row -->
        <div class="filter-row">
          <div class="filter-left">

            <div class="count-box">
              <span class="count-label">Products Count :</span>
              <span class="count-val"><?= $total_products ?></span>
            </div>

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
                <i class="bi bi-chevron-down" style="font-size:14px;color:var(--1-brown);pointer-events:none;"></i>
              </div>
            </form>

          </div>
        </div>

      </div><!-- /table-actions -->

      <!-- Table -->
      <div class="table-wrap">
        <div class="table-inner">
          <div class="table2">

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
                        <button
                          class="action-btn"
                          type="button"
                          title="View / Edit"
                          onclick="openEditPopup(<?= (int)$p['product_id'] ?>)"
                        >
                          <i class="bi bi-eye"></i>
                        </button>

                        <form class="delete-form" method="post"
                              onsubmit="return confirm('Delete this product?')">
                          <input type="hidden" name="action" value="delete"/>
                          <input type="hidden" name="product_id" value="<?= (int)$p['product_id'] ?>"/>
                          <button class="action-btn delete-btn" type="submit" title="Delete">
                            <i class="bi bi-trash3"></i>
                          </button>
                        </form>
                      </div>
                    </div>
                    <div class="row-divider"></div>
                  </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

          </div>
        </div>
        <div class="table-scroll-indicator"></div>
      </div>

      <!-- Pagination -->
      <div class="pagination">
        <button class="btn-page" id="btn-prev" type="button">
          <i class="bi bi-arrow-left"></i>
          <span>Prev</span>
        </button>
        <button class="btn-page" id="btn-next" type="button">
          <span>Next</span>
          <i class="bi bi-arrow-right"></i>
        </button>
      </div>

    </div><!-- /container2 -->

  </div><!-- /main-body -->
</div><!-- /page-wrapper -->


<!-- ════════════════════════════════════════════════════════════
     ADD PRODUCT OVERLAY
     Exactly matches the design in add_new_overlay.png.
     Submits to THIS page via POST (action=add).
     The X button closes the overlay.
     Complete saves & keeps overlay open for more entries.
     ════════════════════════════════════════════════════════════ -->
<div id="add-product-overlay" role="dialog" aria-modal="true" aria-label="Add Product">

  <div class="add-modal-wrapper">

    <!-- Header -->
    <div class="modal-header">
      <h2 class="modal-title">ADD PRODUCTS</h2>
      <button class="btn-close-modal" type="button" onclick="closeAddOverlay()" title="Close">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>

    <!-- Flash message (shown after add attempt) -->
    <div
      class="flash-message<?= $add_flash_msg ? ' show flash-' . $add_flash_type : '' ?>"
      id="add-flash"
    ><?= htmlspecialchars($add_flash_msg) ?></div>

    <!-- Form posts to manage-products.php -->
    <form
      class="form-section"
      method="post"
      enctype="multipart/form-data"
      id="add-product-form"
    >
      <input type="hidden" name="action" value="add"/>

      <!-- Row 1: Image + Product Name & Category -->
      <div class="top-fields-row">

        <div class="img-upload-box" id="img-upload-box" title="Click to upload image">
          <i class="bi bi-image-fill" style="font-size:64px;color:var(--1-brown);opacity:0.4;pointer-events:none;"></i>
          <img class="img-upload-preview" id="img-preview" src="" alt="Preview"/>
          <input
            type="file"
            name="product_image"
            id="product-image-input"
            accept="image/*"
            onchange="previewImage(this)"
            title="Upload product image"
          />
        </div>

        <div class="right-fields">
          <div class="field-group" style="align-self:stretch;">
            <label class="field-label" for="product-name">Product Name</label>
            <div class="field-input">
              <input
                type="text"
                id="product-name"
                name="product_name"
                placeholder="Enter Here"
                required
                autocomplete="off"
              />
            </div>
          </div>

          <div class="field-group" style="align-self:stretch;">
            <label class="field-label" for="product-category">Category</label>
            <div class="field-input">
              <input
                type="text"
                id="product-category"
                name="category"
                placeholder="Enter Here"
                list="cat-list"
                autocomplete="off"
              />
            </div>
          </div>
        </div>

      </div>

      <!-- Row 2: SKU (auto) + Expiry Date -->
      <div class="fields-row">
        <div class="field-group">
          <label class="field-label">SKU</label>
          <div class="sku-field">
            <span>Auto-generated on save</span>
          </div>
        </div>

        <div class="field-group">
          <label class="field-label" for="expiry-date">Expiry Date</label>
          <div class="expiry-field-wrap">
            <div class="expiry-date-part">
              <i class="bi bi-calendar3"></i>
              <input type="date" id="expiry-date" name="expiry_date"/>
            </div>
            <div class="none-checkbox-part">
              <label for="no-expiry">None</label>
              <input
                type="checkbox"
                id="no-expiry"
                name="no_expiry"
                onchange="toggleExpiry(this)"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- Row 3: Stock Quantity + Cost + Selling Price -->
      <div class="fields-row">
        <div class="field-group">
          <label class="field-label" for="stock-qty">Stock Quantity</label>
          <div class="field-input">
            <input
              type="number"
              id="stock-qty"
              name="stock_quantity"
              placeholder="Enter Here"
              min="0"
              value="0"
            />
          </div>
        </div>

        <div class="field-group">
          <label class="field-label" for="cost-price">Cost</label>
          <div class="field-input">
            <input
              type="number"
              id="cost-price"
              name="cost"
              placeholder="₱"
              step="0.01"
              min="0"
              value="0"
            />
          </div>
        </div>

        <div class="field-group">
          <label class="field-label" for="selling-price">Selling Price</label>
          <div class="field-input">
            <input
              type="number"
              id="selling-price"
              name="selling_price"
              placeholder="₱"
              step="0.01"
              min="0"
              value="0"
            />
          </div>
        </div>
      </div>

      <!-- Row 4: Additional Notes -->
      <div class="fields-row" style="align-items:flex-start;">
        <div class="field-group full-width">
          <label class="field-label" for="notes">Additional Notes</label>
          <div class="field-input textarea-wrap">
            <textarea id="notes" name="notes" placeholder="Enter Here"></textarea>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="form-footer">
        <button type="button" class="btn-cancel-modal" onclick="closeAddOverlay()">Cancel</button>
        <button type="submit" class="btn-complete">Complete</button>
      </div>

    </form>

  </div><!-- /add-modal-wrapper -->

</div><!-- /add-product-overlay -->

<!-- Category autocomplete datalist -->
<datalist id="cat-list">
  <?php foreach ($categories as $cat): ?>
    <option value="<?= htmlspecialchars($cat['category_name']) ?>">
  <?php endforeach; ?>
</datalist>


<script>
'use strict';

/* ─────────────────────────────────────────────────────────────
   ADD PRODUCT OVERLAY helpers
───────────────────────────────────────────────────────────── */

var overlay = document.getElementById('add-product-overlay');

/**
 * openAddOverlay — show the overlay
 */
function openAddOverlay() {
  overlay.classList.add('is-open');
  document.body.style.overflow = 'hidden'; // prevent background scroll
}

/**
 * closeAddOverlay — hide the overlay (only the X / Cancel trigger this)
 */
function closeAddOverlay() {
  overlay.classList.remove('is-open');
  document.body.style.overflow = '';
}

/**
 * Close overlay when clicking the dark backdrop (outside the card)
 */
overlay.addEventListener('click', function (e) {
  if (e.target === overlay) {
    closeAddOverlay();
  }
});

/**
 * Close on Escape key
 */
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape' && overlay.classList.contains('is-open')) {
    closeAddOverlay();
  }
});

/* ── Auto-open overlay if we just processed an add action (success or error) ── */
var shouldOpen = <?= $open_overlay ?>;
if (shouldOpen) {
  openAddOverlay();

  <?php if ($add_flash_type === 'success'): ?>
  /* Reset form fields after successful add so the user can enter another product */
  (function () {
    var form = document.getElementById('add-product-form');
    if (form) {
      form.reset();
      var preview = document.getElementById('img-preview');
      var icon    = document.querySelector('.img-upload-box > i');
      if (preview) { preview.style.display = 'none'; preview.src = ''; }
      if (icon)    { icon.style.display = ''; }
      var expiryInput = document.getElementById('expiry-date');
      if (expiryInput) expiryInput.disabled = false;
    }

    /* Auto-hide flash after 4 s */
    setTimeout(function () {
      var flash = document.getElementById('add-flash');
      if (flash) { flash.style.transition = 'opacity 0.5s'; flash.style.opacity = '0'; }
    }, 4000);
    setTimeout(function () {
      var flash = document.getElementById('add-flash');
      if (flash) { flash.style.display = 'none'; }
    }, 4600);
  })();
  <?php endif; ?>
}

/* ─────────────────────────────────────────────────────────────
   Expiry checkbox helper
───────────────────────────────────────────────────────────── */
function toggleExpiry(checkbox) {
  var dateInput = document.getElementById('expiry-date');
  dateInput.disabled = checkbox.checked;
  if (checkbox.checked) dateInput.value = '';
}

/* ─────────────────────────────────────────────────────────────
   Image preview
───────────────────────────────────────────────────────────── */
function previewImage(input) {
  var preview = document.getElementById('img-preview');
  var icon    = document.querySelector('.img-upload-box > i');
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function (e) {
      preview.src           = e.target.result;
      preview.style.display = 'block';
      if (icon) icon.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
  }
}

/* ─────────────────────────────────────────────────────────────
   Edit popup (update_prod.php in a new window — unchanged)
───────────────────────────────────────────────────────────── */
function openEditPopup(productId) {
  var w    = 820, h = 700;
  var left = Math.round((screen.width  - w) / 2);
  var top  = Math.round((screen.height - h) / 2);
  var popup = window.open(
    'update_prod.php?product_id=' + productId,
    'edit_product_popup',
    'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top +
    ',resizable=yes,scrollbars=yes'
  );
  var timer = setInterval(function () {
    if (popup && popup.closed) { clearInterval(timer); window.location.reload(); }
  }, 600);
}

/* ─────────────────────────────────────────────────────────────
   Client-side search filter
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
if (btnPrev) btnPrev.addEventListener('click', function () { if (currentPage > 1) { currentPage--; renderPage(); } });
if (btnNext) btnNext.addEventListener('click', function () { currentPage++; renderPage(); });

/* ─────────────────────────────────────────────────────────────
   Export
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