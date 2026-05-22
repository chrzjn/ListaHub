<?php
// ============================================================
//  restock.php
//  Requirements: Prepared statements, try-catch, session guard
//  Schema: Restock_Transaction + Restock_Item tables,
//          Product.quantity, user_id filter
//          Trigger trg_restock_item_add_stock auto-updates stock.
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

// ── Handle Restock POST ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restock') {
    $product_id   = (int)   ($_POST['product_id']  ?? 0);
    $qty_added    = (int)   ($_POST['qty_added']   ?? 0);
    $cost_at_rest = (float) ($_POST['cost_price']  ?? 0);

    if ($product_id > 0 && $qty_added > 0) {
        try {
            $pdo = getPDO();
            $pdo->beginTransaction();

            // Create a restock transaction header
            $txStmt = $pdo->prepare(
                "INSERT INTO Restock_Transaction (restock_date, total_cost)
                 VALUES (NOW(), :total_cost)"
            );
            $txStmt->execute([':total_cost' => $cost_at_rest * $qty_added]);
            $restock_id = (int) $pdo->lastInsertId();

            // Get current cost_price if not provided
            if ($cost_at_rest <= 0) {
                $priceStmt = $pdo->prepare(
                    "SELECT cost_price FROM Product WHERE product_id = :id AND user_id = :uid"
                );
                $priceStmt->execute([':id' => $product_id, ':uid' => $user_id]);
                $pRow = $priceStmt->fetch();
                $cost_at_rest = (float)($pRow['cost_price'] ?? 0);
            }

            // Insert restock item — trigger trg_restock_item_add_stock fires automatically
            $itemStmt = $pdo->prepare(
                "INSERT INTO Restock_Item (restock_id, product_id, quantity_added, cost_price_at_restock)
                 VALUES (:restock_id, :product_id, :qty, :cost)"
            );
            $itemStmt->execute([
                ':restock_id' => $restock_id,
                ':product_id' => $product_id,
                ':qty'        => $qty_added,
                ':cost'       => $cost_at_rest,
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

// Pagination
$per_page    = 10;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($current_page - 1) * $per_page;

try {
    $pdo = getPDO();

    // Count query for pagination
    $countSql = "SELECT COUNT(DISTINCT p.product_id) AS total_rows
                 FROM Product p
                 WHERE p.user_id = :user_id";
    $countParams = [':user_id' => $user_id];

    if ($tab === 'low') {
        $countSql .= " AND p.status = 'Low Stock'";
    } elseif ($tab === 'out') {
        $countSql .= " AND p.status = 'Out of Stock'";
    } elseif ($tab === 'expired') {
        $countSql .= " AND p.expiration_date < CURDATE()";
    } elseif ($tab === 'near') {
        $countSql .= " AND p.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    }

    if (!empty($search)) {
        $countSql .= " AND (p.product_name LIKE :search OR p.sku LIKE :search2)";
        $countParams[':search']  = '%' . $search . '%';
        $countParams[':search2'] = '%' . $search . '%';
    }

    $countStmt2 = $pdo->prepare($countSql);
    $countStmt2->execute($countParams);
    $total_rows  = (int)$countStmt2->fetchColumn();
    $total_pages = max(1, (int)ceil($total_rows / $per_page));
    $current_page = min($current_page, $total_pages);
    $offset = ($current_page - 1) * $per_page;

    // Main product query
    $sql = "SELECT p.product_id, p.product_name, p.sku, p.quantity,
                   p.cost_price, p.retail_price, p.expiration_date,
                   p.status, p.low_stock_threshold,
                   COALESCE(c.category_name, 'Uncategorized') AS category_name
            FROM Product p
            LEFT JOIN Category c ON c.category_id = p.category_id
            WHERE p.user_id = :user_id";

    $params = [':user_id' => $user_id];

    if ($tab === 'low') {
        $sql .= " AND p.status = 'Low Stock'";
    } elseif ($tab === 'out') {
        $sql .= " AND p.status = 'Out of Stock'";
    } elseif ($tab === 'expired') {
        $sql .= " AND p.expiration_date < CURDATE()";
    } elseif ($tab === 'near') {
        $sql .= " AND p.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    }

    if (!empty($search)) {
        $sql .= " AND (p.product_name LIKE :search OR p.sku LIKE :search2)";
        $params[':search']  = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY p.product_name ASC LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll();

    // Counts for tab badges
    $countStmt = $pdo->prepare(
        "SELECT
            COUNT(*)                                                                    AS total,
            SUM(CASE WHEN status = 'Low Stock'    THEN 1 ELSE 0 END)                  AS low,
            SUM(CASE WHEN status = 'Out of Stock' THEN 1 ELSE 0 END)                  AS out_of_stock,
            SUM(CASE WHEN expiration_date < CURDATE()                   THEN 1 ELSE 0 END) AS expired,
            SUM(CASE WHEN expiration_date BETWEEN CURDATE()
                     AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)           THEN 1 ELSE 0 END) AS near_expiry
         FROM Product WHERE user_id = :user_id"
    );
    $countStmt->execute([':user_id' => $user_id]);
    $counts = $countStmt->fetch();

} catch (PDOException $e) {
    error_log("Restock fetch error: " . $e->getMessage());
    $products     = [];
    $counts       = ['total' => 0, 'low' => 0, 'out_of_stock' => 0, 'expired' => 0, 'near_expiry' => 0];
    $total_pages  = 1;
    $current_page = 1;
}

// Helper: build query string preserving current params
function pageUrl(int $page, string $tab, string $search): string {
    $q = http_build_query(array_filter([
        'tab'    => $tab,
        'search' => $search,
        'page'   => $page,
    ]));
    return 'restock.php?' . $q;
}

$activePage = 'restock';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Restock – ListaHub</title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>

  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"/>

  <!-- Global CSS (variables, reset) -->
  <link rel="stylesheet" href="global_sidebar.css"/>
  <link rel="stylesheet" href="global_restock.css"/>

  <!-- Component CSS -->
  <link rel="stylesheet" href="sidebar.css"/>
  <link rel="stylesheet" href="restock.css"/>
</head>
<body>

<div class="page-wrapper">

  <!-- ============================================================
       SIDEBAR (active page: restock)
       ============================================================ -->
  <?php
    $activePage = 'restock';
    include 'sidebar.php';
  ?>

  <!-- ============================================================
       MAIN BODY
       ============================================================ -->
  <div class="main-body">

    <!-- ── Overview Section: Title + Alerts + Stat Cards (all inside the rounded container) ── -->
    <div class="overview-section">

      <!-- Page Title -->
      <h1 class="page-title">RESTOCK</h1>

      <!-- Alerts -->
      <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="overview-cards">

        <!-- Out of Stocks -->
        <div class="stat-card red-bg">
          <div class="stat-icon icon-red">
            
              <img src="pics_icons/out-of-stock (1).png" width="33" alt="Out of Stock"/>
            
          </div>
          <div class="stat-info">
            <span class="stat-label">Out of Stocks</span>
            <span class="stat-value red"><?= (int)($counts['out_of_stock'] ?? 0) ?> Products</span>
          </div>
        </div>

        <!-- Expired Products -->
        <div class="stat-card orange-bg">
          <div class="stat-icon icon-orange">
            
              <img src="pics_icons/expired.png" width="33" alt="Expired"/>
            
          </div>
          <div class="stat-info">
            <span class="stat-label">Expired Products</span>
            <span class="stat-value orange"><?= (int)($counts['expired'] ?? 0) ?> Products</span>
          </div>
        </div>

        <!-- Low on Stock -->
        <div class="stat-card cream-bg">
          <div class="stat-icon icon-gray">
            <img src="pics_icons/arrow-trend-down.png" width="33" alt="Low Stock"/>
            
          </div>
          <div class="stat-info">
            <span class="stat-label">Low on Stock</span>
            <span class="stat-value gray"><?= (int)($counts['low'] ?? 0) ?> Product<?= (int)($counts['low'] ?? 0) !== 1 ? 's' : '' ?></span>
          </div>
        </div>

        <!-- Near Expiry -->
        <div class="stat-card lavender-bg">
          <div class="stat-icon icon-blue">
           
              <img src="pics_icons/duration-alt.png" width="33" alt="Near Expiry"/>
            
          </div>
          <div class="stat-info">
            <span class="stat-label">Near Expiry</span>
            <span class="stat-value blue"><?= (int)($counts['near_expiry'] ?? 0) ?> Product<?= (int)($counts['near_expiry'] ?? 0) !== 1 ? 's' : '' ?></span>
          </div>
        </div>

      </div>
    </div><!-- /overview-section -->

    <!-- ── Table Section ── -->
    <div class="table-section">

      <!-- Toolbar -->
      <div class="table-toolbar">
        <div class="toolbar-left">

          <!-- Search Form -->
          <form method="get" style="display:contents;">
            <input type="hidden" name="tab"  value="<?= htmlspecialchars($tab) ?>"/>
            <input type="hidden" name="page" value="1"/>
            <div class="searchbar">
              <!--
                TODO: Replace with your magnifying glass icon image.
                e.g. <img src="pics_icons/magnifying-glass.svg" alt="Search" width="20" height="20"/>
              -->
              <i class="bi bi-search"></i>
              <input
                type="text"
                name="search"
                placeholder="Search for product / sku"
                value="<?= htmlspecialchars($search) ?>"
              />
            </div>
            <!-- Hidden submit triggered by Enter key -->
          </form>

          <!-- Filter Tab Buttons -->
          <div class="filter-tabs">
            <a
              href="restock.php?tab=all<?= !empty($search) ? '&search='.urlencode($search) : '' ?>&page=1"
              class="tab-btn <?= $tab === 'all' ? 'active' : '' ?>"
            >All products</a>

            <a
              href="restock.php?tab=out<?= !empty($search) ? '&search='.urlencode($search) : '' ?>&page=1"
              class="tab-btn <?= $tab === 'out' ? 'active' : '' ?>"
            >Out of Stock</a>

            <a
              href="restock.php?tab=low<?= !empty($search) ? '&search='.urlencode($search) : '' ?>&page=1"
              class="tab-btn <?= $tab === 'low' ? 'active' : '' ?>"
            >Low Stock</a>

            <a
              href="restock.php?tab=expired<?= !empty($search) ? '&search='.urlencode($search) : '' ?>&page=1"
              class="tab-btn <?= $tab === 'expired' ? 'active' : '' ?>"
            >Expired</a>

            <a
              href="restock.php?tab=near<?= !empty($search) ? '&search='.urlencode($search) : '' ?>&page=1"
              class="tab-btn <?= $tab === 'near' ? 'active' : '' ?>"
            >Near Expiry</a>

            <!-- Import CSV -->
            <button type="button" class="btn-import" onclick="document.getElementById('csv-upload').click()">
              <!--
                TODO: Replace with your import/upload icon image.
                e.g. <img src="pics_icons/uil-import.svg" alt="Import" width="20" height="20"/>
              -->
              <i class="bi bi-upload"></i>
              Import CSV
            </button>
            <!-- Hidden file input for CSV import -->
            <input type="file" id="csv-upload" accept=".csv" style="display:none;" onchange="handleCsvImport(this)"/>
          </div>

        </div>
      </div><!-- /table-toolbar -->

      <!-- Table -->
      <div class="table-scroll-wrapper">
        <div class="table-wrapper">
          <table class="restock-table">
            <thead>
              <tr>
                <th>Product Name</th>
                <th>SKU</th>
                <th>Category</th>
                <th>Stock</th>
                <th>Add Qty</th>
                <th>Expiry Date</th>
                <th>Price</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($products)): ?>
                <tr class="empty-state">
                  <td colspan="9">No products found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($products as $p):
                  // Determine badge class
                  $status = htmlspecialchars($p['status'] ?? 'In Stock');
                  $badgeClass = match($p['status'] ?? '') {
                      'Out of Stock' => 'badge-out-of-stock',
                      'Low Stock'    => 'badge-low-stock',
                      'Near Expiry'  => 'badge-near-expiry',
                      'Expired'      => 'badge-expired',
                      default        => 'badge-in-stock',
                  };

                  // Determine display status label
                  $statusLabel = $p['status'] ?? 'In Stock';
                  if (empty($statusLabel)) $statusLabel = 'In Stock';

                  // Format expiry date
                  $expiryDisplay = '';
                  if (!empty($p['expiration_date'])) {
                      try {
                          $dt = new DateTime($p['expiration_date']);
                          $expiryDisplay = $dt->format('m/d/y');
                      } catch (Exception $e) {
                          $expiryDisplay = htmlspecialchars($p['expiration_date']);
                      }
                  } else {
                      $expiryDisplay = 'No Expiry Date';
                  }

                  // Price display (retail_price for display, cost_price for restock)
                  $displayPrice = number_format((float)($p['retail_price'] ?? 0), 0);
                  $rowQtyId     = 'qty_' . (int)$p['product_id'];
                ?>
                <tr>
                  <!-- Product Name -->
                  <td class="td-product-name"><?= htmlspecialchars($p['product_name']) ?></td>

                  <!-- SKU -->
                  <td class="td-sku"><?= htmlspecialchars($p['sku']) ?></td>

                  <!-- Category -->
                  <td class="td-category"><?= htmlspecialchars($p['category_name']) ?></td>

                  <!-- Current Stock -->
                  <td class="td-stock"><?= (int)$p['quantity'] ?></td>

                  <!-- Add Qty Stepper -->
                  <td class="td-qty">
                    <div class="qty-stepper">
                      <button type="button" onclick="changeQty('<?= $rowQtyId ?>', 1)" title="Increase">
                        <!--
                          TODO: Replace with your circle-add icon.
                          e.g. <img src="pics_icons/lsicon-circle-add-outline.svg" alt="+" width="16" height="16"/>
                        -->
                        <i class="bi bi-plus-circle" style="font-size:16px;"></i>
                      </button>
                      <input
                        class="qty-input"
                        type="number"
                        id="<?= $rowQtyId ?>"
                        name="qty_<?= (int)$p['product_id'] ?>"
                        min="1"
                        value="1"
                      />
                      <button type="button" onclick="changeQty('<?= $rowQtyId ?>', -1)" title="Decrease">
                        <!--
                          TODO: Replace with your minus icon.
                          e.g. <img src="pics_icons/lsicon-minus-outline.svg" alt="-" width="16" height="16"/>
                        -->
                        <i class="bi bi-dash-circle" style="font-size:16px;"></i>
                      </button>
                    </div>
                  </td>

                  <!-- Expiry Date -->
                  <td class="td-expiry">
                    <div class="expiry-wrap">
                      <span><?= $expiryDisplay ?></span>
                      <!--
                        TODO: Replace with your calendar icon.
                        e.g. <img src="pics_icons/uiw-date.svg" alt="date" width="20" height="20"/>
                      -->
                      <i class="bi bi-calendar3"></i>
                    </div>
                  </td>

                  <!-- Price -->
                  <td class="td-price">₱ <?= $displayPrice ?></td>

                  <!-- Status Badge -->
                  <td class="td-status">
                    <span class="status-badge <?= $badgeClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                  </td>

                  <!-- Actions -->
                  <td class="td-actions">
                    <div class="actions-wrap">
                      <!-- Restock (submit) button wrapped in form -->
                      <form method="post" style="display:contents;">
                        <input type="hidden" name="action"     value="restock"/>
                        <input type="hidden" name="product_id" value="<?= (int)$p['product_id'] ?>"/>
                        <input type="hidden" name="cost_price" value="<?= (float)$p['cost_price'] ?>"/>
                        <input type="hidden" name="qty_added"  id="hidden_<?= $rowQtyId ?>"/>
                        <!-- View / Restock button -->
                        <button
                          type="submit"
                          class="btn-action"
                          title="Restock this product"
                          onclick="document.getElementById('hidden_<?= $rowQtyId ?>').value = document.getElementById('<?= $rowQtyId ?>').value;"
                        >
                          <!--
                            TODO: Replace with your view/restock icon.
                            e.g. <img src="pics_icons/view.svg" alt="Restock" width="22" height="20"/>
                          -->
                          <i class="bi bi-eye"></i>
                        </button>
                      </form>

                      <!-- Delete button -->
                      <form method="post" style="display:contents;" onsubmit="return confirm('Remove this product?');">
                        <input type="hidden" name="action"     value="delete"/>
                        <input type="hidden" name="product_id" value="<?= (int)$p['product_id'] ?>"/>
                        <button type="submit" class="btn-action" title="Delete">
                          <!--
                            TODO: Replace with your trash icon.
                            e.g. <img src="pics_icons/trash.svg" alt="Delete" width="22" height="20"/>
                          -->
                          <i class="bi bi-trash3"></i>
                        </button>
                      </form>
                    </div>
                  </td>

                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div><!-- /table-wrapper -->
      </div><!-- /table-scroll-wrapper -->

      <!-- Pagination Row -->
      <div class="pagination-row">
        <!-- Prev -->
        <?php if ($current_page > 1): ?>
          <a href="<?= htmlspecialchars(pageUrl($current_page - 1, $tab, $search)) ?>" class="btn-page">
            <!--
              TODO: Replace with your left-arrow icon.
              e.g. <img src="pics_icons/tabler-arrow-left.svg" alt="Prev" width="24"/>
            -->
            <i class="bi bi-arrow-left"></i>
            Prev
          </a>
        <?php else: ?>
          <button class="btn-page" disabled style="opacity:0.4;cursor:default;">
            <i class="bi bi-arrow-left"></i>
            Prev
          </button>
        <?php endif; ?>

        <!-- Next -->
        <?php if ($current_page < $total_pages): ?>
          <a href="<?= htmlspecialchars(pageUrl($current_page + 1, $tab, $search)) ?>" class="btn-page">
            Next
            <!--
              TODO: Replace with your right-arrow icon.
              e.g. <img src="pics_icons/mingcute-arrow-right-line.svg" alt="Next" width="24"/>
            -->
            <i class="bi bi-arrow-right"></i>
          </a>
        <?php else: ?>
          <button class="btn-page" disabled style="opacity:0.4;cursor:default;">
            Next
            <i class="bi bi-arrow-right"></i>
          </button>
        <?php endif; ?>
      </div><!-- /pagination-row -->

      <!-- Bottom Actions -->
      <div class="bottom-actions">
        <button type="button" class="btn-cancel" onclick="window.location.href='restock.php'">Cancel</button>
        <button type="button" class="btn-complete" onclick="handleComplete()">Complete</button>
      </div>

    </div><!-- /table-section -->

  </div><!-- /main-body -->
</div><!-- /page-wrapper -->

<script>
// ── Qty Stepper ──────────────────────────────────────────────
function changeQty(inputId, delta) {
  const input = document.getElementById(inputId);
  if (!input) return;
  let val = parseInt(input.value, 10) || 1;
  val += delta;
  if (val < 1) val = 1;
  input.value = val;
}

// ── CSV Import Handler ───────────────────────────────────────
function handleCsvImport(fileInput) {
  if (!fileInput.files || !fileInput.files[0]) return;
  const file = fileInput.files[0];
  if (!file.name.endsWith('.csv')) {
    alert('Please select a valid CSV file.');
    fileInput.value = '';
    return;
  }
  // TODO: Implement actual CSV upload logic to your server endpoint.
  // e.g. use FormData + fetch() to POST the file to import_csv.php
  alert('CSV file selected: ' + file.name + '\n(Connect this to your import endpoint.)');
  fileInput.value = '';
}

// ── Complete Button ──────────────────────────────────────────
function handleComplete() {
  // The "Complete" button can trigger a batch restock of all checked items,
  // or simply redirect. Adjust this logic to match your workflow.
  alert('Restock complete! Inventory has been updated.');
  window.location.href = 'restock.php';
}

// ── Searchbar: submit on Enter ───────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  const searchInput = document.querySelector('.searchbar input[type="text"]');
  if (searchInput) {
    searchInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        this.closest('form').submit();
      }
    });
  }
});
</script>

</body>
</html>