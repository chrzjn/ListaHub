<?php
// ============================================================
//  inv_history.php
//  Shows paginated inventory movement history from Inventory_Log
//  joined with vw_inventory_movements view.
//  Requirements met:
//   ✅ SQL View (vw_inventory_movements) used
//   ✅ PDO prepared statements
//   ✅ try-catch
//   ✅ Session-based auth guard
//   ✅ Pagination (10 rows/page)
//   ✅ Delete a log entry (POST action)
// ============================================================

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once './utils/lhdb.php';

$user_id      = (int) $_SESSION['user_id'];
$rows_per_page = 10;
$page          = max(1, (int)($_GET['page'] ?? 1));
$offset        = ($page - 1) * $rows_per_page;
$error_msg     = '';
$success_msg   = '';

// ── Handle DELETE (POST) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $log_id = (int)($_POST['log_id'] ?? 0);
    if ($log_id > 0) {
        try {
            $pdo = getPDO();
            // Only allow deleting logs that belong to this user's products
            $del = $pdo->prepare(
                "DELETE il
                 FROM Inventory_Log il
                 JOIN Product p ON p.product_id = il.product_id
                 WHERE il.log_id = :log_id AND p.user_id = :user_id"
            );
            $del->execute([':log_id' => $log_id, ':user_id' => $user_id]);

            if ($del->rowCount() > 0) {
                $success_msg = 'Log entry deleted successfully.';
            } else {
                $error_msg = 'Log entry not found or access denied.';
            }
        } catch (PDOException $e) {
            error_log("Inventory History delete error: " . $e->getMessage());
            $error_msg = 'Delete failed. Please try again.';
        }
    }
    // Redirect to avoid re-submission on refresh (PRG pattern)
    $redirect_page = max(1, $page);
    $msg_param     = $success_msg ? '&msg=deleted' : '&err=1';
    header("Location: inv_history.php?page={$redirect_page}{$msg_param}");
    exit;
}

// ── Flash message from redirect ──────────────────────────
if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $success_msg = 'Log entry deleted successfully.';
}
if (isset($_GET['err']) && $_GET['err'] == '1') {
    $error_msg = 'Delete failed. Please try again.';
}

// ── Fetch data ────────────────────────────────────────────
$logs       = [];
$total_rows = 0;
$total_pages = 1;

try {
    $pdo = getPDO();

    // Total count for this user
    $count_stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt
         FROM vw_inventory_movements
         WHERE user_id = :user_id"
    );
    $count_stmt->execute([':user_id' => $user_id]);
    $total_rows  = (int)$count_stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total_rows / $rows_per_page));

    // Clamp page to valid range
    $page   = min($page, $total_pages);
    $offset = ($page - 1) * $rows_per_page;

    // Paginated rows — newest first (view is already ORDER BY log_date DESC)
    $stmt = $pdo->prepare(
        "SELECT
            log_id,
            log_date,
            product_id,
            product_name,
            sku,
            category_name,
            movement_type,
            quantity_change,
            stock_before,
            stock_after,
            reference_type,
            reference_id,
            adjustment_reason
         FROM vw_inventory_movements
         WHERE user_id = :user_id
         ORDER BY log_date DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':limit',   $rows_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset',  $offset,        PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Inventory History fetch error: " . $e->getMessage());
    $error_msg = 'Could not load inventory history. Please try again later.';
}

// ── Helper: map reference_type to display label ───────────
function transactionLabel(string $ref_type, string $movement, string $reason = ''): string {
    return match($ref_type) {
        'restock'           => 'Product Restock',
        'sale'              => 'Sold',
        'expired_deletion'  => 'Deleted Expired Items',
        'product_addition'  => 'Product Addition',
        'product_edit'      => 'Product Edit',
        'manual'            => match($reason) {
            'Other'                  => 'Product Deletion',
            'Expired Items'          => 'Deleted Expired Items',
            'Stock Count Correction' => 'Stock Correction',
            'Damaged Goods'          => 'Damaged Goods',
            'Theft/Loss'             => 'Theft / Loss',
            'Returned to Supplier'   => 'Returned to Supplier',
            default                  => 'Manual Adjustment',
        },
        default => ucfirst($ref_type),
    };
}

// ── Helper: generate sequential display ID ────────────────
function displayId(int $log_id, string $log_date): string {
    $ym = date('Ym', strtotime($log_date));
    return $ym . '-' . str_pad($log_id, 4, '0', STR_PAD_LEFT);
}

$activePage = 'inv_history';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Inventory History – ListaHub</title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Roboto:wght@600&display=swap" rel="stylesheet"/>

  <!-- Global CSS (variables, reset) -->
  <link rel="stylesheet" href="global_sidebar.css"/>
  <link rel="stylesheet" href="global_inv_history.css"/>

  <!-- Component CSS -->
  <link rel="stylesheet" href="sidebar.css"/>
  <link rel="stylesheet" href="inv_history.css"/>
</head>
<body>

<div class="page-wrapper">

  <!-- ============================================================
       SIDEBAR
       sidebar.php renders the <aside> with active state highlighting
       ============================================================ -->
  <?php
    $activePage = 'inv_history';
    include 'sidebar.php';
  ?>

  <!-- ============================================================
       MAIN BODY
       ============================================================ -->
  <div class="main-body">

    <!-- ── Page header ── -->
    <div class="overview">
      <div class="header-card">
        <div class="header-icon">
          <!--
            TODO: Insert history icon image here.
            e.g. <img src="pics_icons/history.png" width="43" height="43" alt="Inventory History"/>
          -->
        </div>
        <h1 class="page-title">Inventory History</h1>
      </div>
    </div>

    <!-- ── Content card ── -->
    <div class="content-container">

      <!-- Table wrapper -->
      <div class="tablecontainer">
        <div class="table-view">
          <div class="info">
            <div class="inv-table">

              <!-- ── THEAD ── -->
              <div class="thead">
                <div class="row">
                  <div class="text-container">
                    <b class="col-date">Date</b>
                    <b class="col-id">ID</b>
                    <b class="col-type">Transaction Type</b>
                    <b class="col-item">Item</b>
                    <b class="col-stock-before">Stock Before</b>
                    <b class="col-stock-after">Stock After</b>
                    <b class="col-delete">Delete</b>
                  </div>
                  <div class="header-separator"></div>
                </div>
              </div><!-- /thead -->

              <!-- ── TBODY ── -->
              <div class="tbody">
                <?php if (empty($logs)): ?>
                  <div class="empty-state">No inventory history found.</div>
                <?php else: ?>
                  <?php foreach ($logs as $row): ?>
                    <?php
                      $display_id   = displayId((int)$row['log_id'], $row['log_date']);
                      $tx_label = transactionLabel($row['reference_type'], $row['movement_type'], $row['adjustment_reason'] ?? '');
                      $date_display = date('m/d/Y - g:ia', strtotime($row['log_date']));
                      $item_name    = htmlspecialchars($row['product_name']);
                    ?>
                    <div class="row">
                      <div class="text-container">

                        <!-- Date -->
                        <div class="cell-date">
                          <i><?= htmlspecialchars($date_display) ?></i>
                        </div>

                        <!-- ID -->
                        <div class="cell-id">
                          <i><?= htmlspecialchars($display_id) ?></i>
                        </div>

                        <!-- Transaction Type -->
                        <span class="cell-type"><?= htmlspecialchars($tx_label) ?></span>

                        <!-- Item Name -->
                        <i class="cell-item"><?= $item_name ?></i>

                        <!-- Stock Before -->
                        <i class="cell-stock-before"><?= (int)$row['stock_before'] ?></i>

                        <!-- Stock After -->
                        <i class="cell-stock-after"><?= (int)$row['stock_after'] ?></i>

                        <!-- Delete button -->
                        <div class="action-buttons">
                          <button
                            class="btn-delete"
                            type="button"
                            onclick="openDeleteModal(<?= (int)$row['log_id'] ?>, '<?= htmlspecialchars(addslashes($item_name)) ?>')"
                            title="Delete this log entry"
                          >
                           
                               <img src="pics_icons/trash.svg" alt="Delete"/>
                            
                            
                          </button>
                        </div>

                      </div>
                      <div class="row-separator"></div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div><!-- /tbody -->

            </div><!-- /inv-table -->
          </div><!-- /info -->
        </div><!-- /table-view -->
      </div><!-- /tablecontainer -->

      <!-- ── Pagination ── -->
      <div class="buttons-parent">

        <!-- Prev -->
        <button
          class="btn-page"
          type="button"
          onclick="goToPage(<?= max(1, $page - 1) ?>)"
          <?= ($page <= 1) ? 'disabled' : '' ?>
        >
          <!--
            TODO: Insert left arrow icon image here.
            e.g. <img src="pics_icons/tabler-arrow-left.svg" alt="Prev"/>
          -->
          <span class="btn-page-text">← Prev</span>
        </button>

        <!-- Page indicator -->
        <span style="font-size: var(--fs-13); color: var(--text-brown); font-family: var(--font-inter); font-weight: 500;">
          Page <?= $page ?> of <?= $total_pages ?>
          (<?= $total_rows ?> record<?= $total_rows !== 1 ? 's' : '' ?>)
        </span>

        <!-- Next -->
        <button
          class="btn-page"
          type="button"
          onclick="goToPage(<?= min($total_pages, $page + 1) ?>)"
          <?= ($page >= $total_pages) ? 'disabled' : '' ?>
        >
          <span class="btn-page-text">Next →</span>
          <!--
            TODO: Insert right arrow icon image here.
            e.g. <img src="pics_icons/mingcute-arrow-right-line.svg" alt="Next"/>
          -->
        </button>

      </div><!-- /buttons-parent -->

    </div><!-- /content-container -->

  </div><!-- /main-body -->
</div><!-- /page-wrapper -->

<!-- ============================================================
     DELETE CONFIRMATION MODAL
     ============================================================ -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box">
    <div class="modal-title">Delete Log Entry?</div>
    <p class="modal-desc" id="modalDesc">
      Are you sure you want to delete this inventory log entry?
      This action cannot be undone.
    </p>
    <div class="modal-actions">
      <button class="btn-cancel" type="button" onclick="closeDeleteModal()">Cancel</button>
      <form method="post" action="inv_history.php?page=<?= $page ?>" style="flex:1; display:flex;">
        <input type="hidden" name="action"  value="delete"/>
        <input type="hidden" name="log_id"  id="modalLogId" value=""/>
        <button class="btn-confirm-delete" type="submit" style="flex:1;">Delete</button>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================
     TOAST NOTIFICATION
     ============================================================ -->
<div class="toast <?= $success_msg ? 'success' : ($error_msg ? 'error' : '') ?>" id="toastMsg">
  <?= htmlspecialchars($success_msg ?: $error_msg) ?>
</div>

<!-- ============================================================
     JAVASCRIPT
     ============================================================ -->
<script>
  // ── Pagination ──────────────────────────────────────────
  function goToPage(page) {
    window.location.href = 'inv_history.php?page=' + page;
  }

  // ── Delete modal ────────────────────────────────────────
  function openDeleteModal(logId, itemName) {
    document.getElementById('modalLogId').value = logId;
    document.getElementById('modalDesc').textContent =
      'Are you sure you want to delete the log entry for "' + itemName + '"? This action cannot be undone.';
    document.getElementById('deleteModal').classList.add('active');
  }

  function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
  }

  // Close modal on overlay click
  document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
  });

  // ── Toast auto-hide ─────────────────────────────────────
  (function () {
    var toast = document.getElementById('toastMsg');
    if (toast && toast.textContent.trim() !== '') {
      toast.classList.add('show');
      setTimeout(function () {
        toast.classList.remove('show');
      }, 3500);
    }
  })();
</script>

</body>
</html>