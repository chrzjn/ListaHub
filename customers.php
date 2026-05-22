<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once './utils/lhdb.php';

$user_id = (int) $_SESSION['user_id'];
$message = '';
$error   = '';

/* ============================================================
   POST ACTIONS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $pdo = getPDO();

        /* ── ADD CUSTOMER + CREDIT ── */
        if ($action === 'add') {
            $customer_name   = trim($_POST['customer_name'] ?? '');
            $amount_owed     = (float) ($_POST['amount_owed'] ?? 0);
            $settlement_date = $_POST['settlement_date'] ?? null;

            if (empty($customer_name) || $amount_owed <= 0) {
                $error = 'Customer name and a valid amount are required.';
            } else {
                $pdo->beginTransaction();

                $custStmt = $pdo->prepare("
                    INSERT INTO customers (user_id, customer_name, created_at)
                    VALUES (:user_id, :customer_name, NOW())
                ");
                $custStmt->execute([
                    ':user_id'       => $user_id,
                    ':customer_name' => $customer_name,
                ]);

                $customer_id = (int) $pdo->lastInsertId();

                $creditStmt = $pdo->prepare("
                    INSERT INTO customer_credits
                        (customer_id, amount_owed, settlement_date, status, created_at)
                    VALUES
                        (:customer_id, :amount_owed, :settlement_date, 'pending', NOW())
                ");
                $creditStmt->execute([
                    ':customer_id'    => $customer_id,
                    ':amount_owed'    => $amount_owed,
                    ':settlement_date'=> !empty($settlement_date) ? $settlement_date : null,
                ]);

                $pdo->commit();
                $message = 'Customer added successfully.';
            }
        }

        /* ── UPDATE CREDIT ── */
        if ($action === 'update') {
            $credit_id       = (int) ($_POST['credit_id'] ?? 0);
            $amount_owed     = (float) ($_POST['amount_owed'] ?? 0);
            $settlement_date = $_POST['settlement_date'] ?? null;
            $status          = $_POST['status'] ?? 'pending';

            $allowed = ['pending', 'settled', 'overdue'];
            if (!in_array($status, $allowed)) $status = 'pending';

            if ($credit_id) {
                $pdo->beginTransaction();

                $upd = $pdo->prepare("
                    UPDATE customer_credits cc
                    JOIN customers c ON c.id = cc.customer_id
                    SET cc.amount_owed     = :amount_owed,
                        cc.settlement_date = :settlement_date,
                        cc.status          = :status
                    WHERE cc.id    = :id
                      AND c.user_id = :user_id
                ");
                $upd->execute([
                    ':amount_owed'    => $amount_owed,
                    ':settlement_date'=> !empty($settlement_date) ? $settlement_date : null,
                    ':status'         => $status,
                    ':id'             => $credit_id,
                    ':user_id'        => $user_id,
                ]);

                $pdo->commit();
                $message = 'Credit updated successfully.';
            }
        }

        /* ── DELETE CUSTOMER ── */
        if ($action === 'delete') {
            $customer_id = (int) ($_POST['customer_id'] ?? 0);

            if ($customer_id) {
                $pdo->beginTransaction();

                $del = $pdo->prepare("
                    DELETE FROM customers
                    WHERE id = :id AND user_id = :user_id
                ");
                $del->execute([
                    ':id'      => $customer_id,
                    ':user_id' => $user_id,
                ]);

                $pdo->commit();
                $message = 'Customer deleted.';
            }
        }

        /* ── SETTLE CREDIT ── */
        if ($action === 'settle') {
            $credit_id = (int) ($_POST['credit_id'] ?? 0);

            if ($credit_id) {
                $pdo->beginTransaction();

                $settle = $pdo->prepare("
                    UPDATE customer_credits cc
                    JOIN customers c ON c.id = cc.customer_id
                    SET cc.status     = 'settled',
                        cc.amount_owed = 0
                    WHERE cc.id     = :id
                      AND c.user_id  = :user_id
                ");
                $settle->execute([
                    ':id'      => $credit_id,
                    ':user_id' => $user_id,
                ]);

                $pdo->commit();
                $message = 'Credit settled.';
            }
        }

    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        $error = 'Database error: ' . htmlspecialchars($e->getMessage());
    }
}

/* ============================================================
   FETCH DATA
   ============================================================ */
$perPage     = 10;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$offset      = ($currentPage - 1) * $perPage;
$search      = trim($_GET['search'] ?? '');
$filterStatus= trim($_GET['status'] ?? '');

try {
    $pdo = getPDO();

    /* ── Summary stats ── */
    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT CASE WHEN cc.status != 'settled' THEN c.id END)           AS unsettled_count,
            COUNT(DISTINCT c.id)                                                      AS total_customers,
            COALESCE(SUM(CASE WHEN cc.status != 'settled' THEN cc.amount_owed END),0) AS total_credit
        FROM customers c
        LEFT JOIN customer_credits cc ON cc.customer_id = c.id
        WHERE c.user_id = :user_id
    ");
    $summaryStmt->execute([':user_id' => $user_id]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    /* ── Count rows for pagination ── */
    $countSql = "
        SELECT COUNT(*) AS total
        FROM customers c
        LEFT JOIN customer_credits cc ON cc.customer_id = c.id
        WHERE c.user_id = :user_id
    ";
    $countParams = [':user_id' => $user_id];

    if ($search) {
        $countSql .= " AND c.customer_name LIKE :search";
        $countParams[':search'] = "%$search%";
    }
    if ($filterStatus) {
        $countSql .= " AND cc.status = :status";
        $countParams[':status'] = $filterStatus;
    }

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $totalRows  = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRows / $perPage));

    /* ── Customer list ── */
    $sql = "
        SELECT c.id AS customer_id, c.customer_name, c.created_at,
               cc.id AS credit_id, cc.amount_owed, cc.settlement_date, cc.status
        FROM customers c
        LEFT JOIN customer_credits cc ON cc.customer_id = c.id
        WHERE c.user_id = :user_id
    ";
    $params = [':user_id' => $user_id];

    if ($search) {
        $sql .= " AND c.customer_name LIKE :search";
        $params[':search'] = "%$search%";
    }
    if ($filterStatus) {
        $sql .= " AND cc.status = :status";
        $params[':status'] = $filterStatus;
    }

    $sql .= " ORDER BY c.created_at DESC LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log($e->getMessage());
    $summary    = ['unsettled_count' => 0, 'total_customers' => 0, 'total_credit' => 0];
    $customers  = [];
    $totalPages = 1;
}

/* ── Helper: map DB status → display label & CSS class ── */
function statusInfo(string $status): array {
    return match($status) {
        'settled' => ['label' => 'Fully Paid',     'class' => 'fully-paid'],
        'overdue' => ['label' => 'Unpaid',          'class' => 'unpaid'],
        default   => ['label' => 'Partially Paid',  'class' => 'partially-paid'],
    };
}

/* ── Build pagination URL helper ── */
function pageUrl(int $page, string $search, string $filterStatus): string {
    $q = http_build_query(array_filter([
        'page'   => $page,
        'search' => $search,
        'status' => $filterStatus,
    ]));
    return 'customers.php' . ($q ? "?$q" : '');
}

$activePage = 'customers';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="initial-scale=1, width=device-width" />
  <title>Customers Credit – ListaHub</title>

  <!-- Google Fonts: Inter -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" />

  <!--
    TODO: Add Bootstrap Icons CDN for view / trash / chevron icons.
    Uncomment the line below:
  -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />

  <!-- CSS — load order matters; mirrors dashboard.php exactly -->
  <link rel="stylesheet" href="global_sidebar.css" />   <!-- sidebar base variables -->
  <link rel="stylesheet" href="global_customers.css" /> <!-- page variables (extends sidebar vars) -->
  <link rel="stylesheet" href="sidebar.css" />          <!-- sidebar component styles -->
  <link rel="stylesheet" href="customers.css" />        <!-- page styles -->
</head>
<body>

<div class="customer-page">
  <div class="page-container">

    <!-- ===== SIDEBAR ===== -->
    <?php include 'sidebar.php'; ?>

    <!-- ===== MAIN BODY ===== -->
    <main class="main-body">

      <!-- Flash messages -->
      <?php if ($message): ?>
        <div class="flash-msg success"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="flash-msg error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <!-- ── OVERVIEW HEADER ── -->
      <section class="overview-section">
        <div class="overview-card">
          <h1 class="page-title">CUSTOMERS CREDIT</h1>

          <div class="cards-row">

            <!-- Unsettled Customers Card -->
            <div class="summary-card card-unsettled">
              <div class="card-inner">
                <!--
                  TODO: Replace with your icon image or use Bootstrap Icons.
                  Option A (image): <img class="card-icon" src="pics_icons/Group-494.png" alt="Unsettled" />
                  Option B (Bootstrap Icons):
                -->
                <div class="card-icon-placeholder icon-red">
                  <i class="bi bi-calendar-x-fill"></i>
                </div>
                <div class="card-texts">
                  <span class="card-label">Unsettled Customers</span>
                  <h3 class="card-value val-red">
                    <?= (int)($summary['unsettled_count'] ?? 0) ?> Customers
                  </h3>
                </div>
              </div>
            </div>

            <!-- Total Credit Card -->
            <div class="summary-card card-credit">
              <div class="card-inner">
                <!--
                  TODO: Replace with your icon image or use Bootstrap Icons.
                  Option A (image): <img class="card-icon" src="pics_icons/Group-494.png" alt="Credit" />
                  Option B (Bootstrap Icons):
                -->
                <div class="card-icon-placeholder icon-orange">
                  <i class="bi bi-credit-card-fill"></i>
                </div>
                <div class="card-texts">
                  <span class="card-label">Total Credit</span>
                  <h2 class="card-value val-orange">
                    ₱<?= number_format((float)($summary['total_credit'] ?? 0), 0) ?>
                  </h2>
                </div>
              </div>
            </div>

            <!-- Total Customers Card -->
            <div class="summary-card card-total">
              <div class="card-inner">
                <!--
                  TODO: Replace with your icon image or use Bootstrap Icons.
                  Option A (image): <img class="card-icon" src="pics_icons/Group-494.png" alt="Total" />
                  Option B (Bootstrap Icons):
                -->
                <div class="card-icon-placeholder icon-gray">
                  <i class="bi bi-people-fill"></i>
                </div>
                <div class="card-texts">
                  <span class="card-label">Total Customers</span>
                  <h2 class="card-value val-gray">
                    <?= (int)($summary['total_customers'] ?? 0) ?> customers
                  </h2>
                </div>
              </div>
            </div>

          </div><!-- /.cards-row -->
        </div><!-- /.overview-card -->
      </section>

      <!-- ── TABLE SECTION ── -->
      <div class="table-container-wrap">

        <!-- Actions Bar -->
        <div class="table-actions-bar">
          <div class="actions-top-row">

            <!-- Left: search + filters -->
            <div class="actions-left">
              <form method="get" action="customers.php" style="display:contents;">
                <!-- Search -->
                <div class="searchbar">
                  <!--
                    TODO: Replace the icon below with your magnifying glass icon or use Bootstrap Icons.
                    Option A (image): <img class="searchbar-icon" src="pics_icons/magnifying-glass.svg" alt="Search" />
                    Option B (Bootstrap Icons - already included):
                  -->
                  <i class="bi bi-search searchbar-icon"></i>
                  <input
                    type="text"
                    name="search"
                    placeholder="Search for customer"
                    value="<?= htmlspecialchars($search) ?>"
                    autocomplete="off"
                  />
                </div>

                <!-- Status filter -->
                <select name="status" class="category-btn" onchange="this.form.submit()" title="Filter by status">
                  <option value="">Category</option>
                  <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Partially Paid</option>
                  <option value="settled" <?= $filterStatus === 'settled' ? 'selected' : '' ?>>Fully Paid</option>
                  <option value="overdue" <?= $filterStatus === 'overdue' ? 'selected' : '' ?>>Unpaid</option>
                </select>

                <button type="submit" style="display:none;"></button>
              </form>
            </div>

            <!-- Right: export + add -->
            <div class="actions-right-group">
              <!-- Export list -->
              <a href="customers.php?export=csv&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>"
                 class="export-btn">
                <!--
                  TODO: Replace with your export icon or use Bootstrap Icons.
                  Option A (image): <img src="pics_icons/file-export.svg" alt="" style="width:18px;height:18px;" />
                  Option B:
                -->
                <i class="bi bi-file-earmark-arrow-down"></i>
                Export list
              </a>

              

          </div>
        </div><!-- /.table-actions-bar -->

        <!-- Table -->
        <div class="table-view-wrapper">
          <table class="customers-table">
            <thead>
              <tr>
                <th>Customer Name</th>
                <th>Money Owed</th>
                <th>Settlement Date</th>
                <th class="col-status">Status</th>
                <th class="col-actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($customers)): ?>
                <tr class="no-data-row">
                  <td colspan="5">No customers found.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($customers as $row):
                  $info   = statusInfo($row['status'] ?? 'pending');
                  $hasDate = !empty($row['settlement_date']);
                ?>
                <tr>
                  <!-- Customer Name -->
                  <td class="col-name"><?= htmlspecialchars($row['customer_name']) ?></td>

                  <!-- Money Owed -->
                  <td>₱ <?= number_format((float)$row['amount_owed'], 0) ?></td>

                  <!-- Settlement Date -->
                  <td class="col-date">
                    <?php if ($hasDate): ?>
                      <div class="date-cell">
                        <?= htmlspecialchars(date('m/d/y', strtotime($row['settlement_date']))) ?>
                        <!--
                          TODO: Replace with your calendar icon or use Bootstrap Icons.
                          Option A (image): <img src="pics_icons/uiw-date.svg" alt="" style="width:18px;height:18px;" />
                          Option B:
                        -->
                        <i class="bi bi-calendar3"></i>
                      </div>
                    <?php else: ?>
                      –
                    <?php endif; ?>
                  </td>

                  <!-- Status -->
                  <td class="col-status">
                    <span class="status-badge <?= $info['class'] ?>">
                      <?= $info['label'] ?>
                    </span>
                  </td>

                  <!-- Actions -->
                  <td class="col-actions">
                    <div class="actions-cell">
                      <!-- View / Edit -->
                      <button
                        class="action-icon-btn btn-view"
                        title="View / Edit"
                        onclick="openEditModal(
                          <?= (int)$row['credit_id'] ?>,
                          <?= (float)$row['amount_owed'] ?>,
                          '<?= htmlspecialchars($row['settlement_date'] ?? '') ?>',
                          '<?= htmlspecialchars($row['status'] ?? 'pending') ?>'
                        )"
                      >
                        <!--
                          TODO: Replace with your view icon or Bootstrap Icons (bi-eye).
                        -->
                        <i class="bi bi-eye"></i>
                      </button>

                      <!-- Delete -->
                      <button
                        class="action-icon-btn btn-delete"
                        title="Delete customer"
                        onclick="openDeleteModal(<?= (int)$row['customer_id'] ?>, '<?= htmlspecialchars($row['customer_name'], ENT_QUOTES) ?>')"
                      >
                        <!--
                          TODO: Replace with your trash icon or Bootstrap Icons (bi-trash).
                        -->
                        <i class="bi bi-trash"></i>
                      </button>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div><!-- /.table-view-wrapper -->

        <!-- Pagination -->
        <footer class="pagination-area">
          <?php if ($currentPage > 1): ?>
            <a href="<?= pageUrl($currentPage - 1, $search, $filterStatus) ?>" class="page-btn">
              <!--
                TODO: Replace with your left arrow icon or Bootstrap Icons (bi-arrow-left).
              -->
              <i class="bi bi-arrow-left"></i>
              Prev
            </a>
          <?php else: ?>
            <button class="page-btn" disabled>
              <i class="bi bi-arrow-left"></i> Prev
            </button>
          <?php endif; ?>

          <span style="font-size:var(--fs-14);color:var(--1-brown);font-family:var(--font-inter);">
            Page <?= $currentPage ?> of <?= $totalPages ?>
          </span>

          <?php if ($currentPage < $totalPages): ?>
            <a href="<?= pageUrl($currentPage + 1, $search, $filterStatus) ?>" class="page-btn">
              Next
              <!--
                TODO: Replace with your right arrow icon or Bootstrap Icons (bi-arrow-right).
              -->
              <i class="bi bi-arrow-right"></i>
            </a>
          <?php else: ?>
            <button class="page-btn" disabled>
              Next <i class="bi bi-arrow-right"></i>
            </button>
          <?php endif; ?>
        </footer>

      </div><!-- /.table-container-wrap -->

    </main><!-- /.main-body -->
  </div><!-- /.page-container -->
</div><!-- /.customer-page -->


<!-- ============================================================
     MODAL: ADD CUSTOMER
     ============================================================ -->
<div class="modal-overlay" id="addModal">
  <div class="modal-box">
    <h2 class="modal-title">Add New Customer</h2>
    <form method="post" action="customers.php">
      <input type="hidden" name="action" value="add" />

      <div class="modal-form-group">
        <label class="modal-label" for="add_customer_name">Customer Name</label>
        <input class="modal-input" type="text" id="add_customer_name" name="customer_name"
               placeholder="e.g. Juan dela Cruz" required />
      </div>

      <div class="modal-form-group" style="margin-top:12px;">
        <label class="modal-label" for="add_amount_owed">Amount Owed (₱)</label>
        <input class="modal-input" type="number" id="add_amount_owed" name="amount_owed"
               placeholder="0.00" min="0.01" step="0.01" required />
      </div>

      <div class="modal-form-group" style="margin-top:12px;">
        <label class="modal-label" for="add_settlement_date">Settlement Date <span style="font-weight:400;opacity:.6;">(optional)</span></label>
        <input class="modal-input" type="date" id="add_settlement_date" name="settlement_date" />
      </div>

      
    </form>
  </div>
</div>


<!-- ============================================================
     MODAL: EDIT / VIEW CREDIT
     ============================================================ -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box">
    <h2 class="modal-title">Edit Credit Record</h2>
    <form method="post" action="customers.php">
      <input type="hidden" name="action"    value="update" />
      <input type="hidden" name="credit_id" id="edit_credit_id" />

      <div class="modal-form-group">
        <label class="modal-label" for="edit_amount_owed">Amount Owed (₱)</label>
        <input class="modal-input" type="number" id="edit_amount_owed" name="amount_owed"
               placeholder="0.00" min="0" step="0.01" required />
      </div>

      <div class="modal-form-group" style="margin-top:12px;">
        <label class="modal-label" for="edit_settlement_date">Settlement Date <span style="font-weight:400;opacity:.6;">(optional)</span></label>
        <input class="modal-input" type="date" id="edit_settlement_date" name="settlement_date" />
      </div>

      <div class="modal-form-group" style="margin-top:12px;">
        <label class="modal-label" for="edit_status">Status</label>
        <select class="modal-select" id="edit_status" name="status">
          <option value="pending">Partially Paid</option>
          <option value="settled">Fully Paid</option>
          <option value="overdue">Unpaid</option>
        </select>
      </div>

      <div class="modal-footer" style="margin-top:16px;">
        <button type="button" class="modal-btn btn-cancel" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="modal-btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>


<!-- ============================================================
     MODAL: DELETE CONFIRMATION
     ============================================================ -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box">
    <h2 class="modal-title">Delete Customer</h2>
    <p style="font-size:var(--fs-15);color:var(--1-brown);margin:0 0 16px;">
      Are you sure you want to delete <strong id="delete_customer_name"></strong>?
      This will also remove all their credit records.
    </p>
    <form method="post" action="customers.php">
      <input type="hidden" name="action"      value="delete" />
      <input type="hidden" name="customer_id" id="delete_customer_id" />
      <div class="modal-footer">
        <button type="button" class="modal-btn btn-cancel" onclick="closeModal('deleteModal')">Cancel</button>
        <button type="submit" class="modal-btn btn-danger">Delete</button>
      </div>
    </form>
  </div>
</div>


<!-- ============================================================
     JAVASCRIPT
     ============================================================ -->
<script>
  /* ── Modal helpers ── */
  function openModal(id) {
    document.getElementById(id).classList.add('active');
  }

  function closeModal(id) {
    document.getElementById(id).classList.remove('active');
  }

  /* Close modal on overlay click */
  document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
      if (e.target === overlay) overlay.classList.remove('active');
    });
  });

  /* ── Open Edit Modal with pre-filled data ── */
  function openEditModal(creditId, amountOwed, settlementDate, status) {
    document.getElementById('edit_credit_id').value       = creditId;
    document.getElementById('edit_amount_owed').value     = amountOwed;
    document.getElementById('edit_settlement_date').value = settlementDate;
    document.getElementById('edit_status').value          = status;
    openModal('editModal');
  }

  /* ── Open Delete Modal ── */
  function openDeleteModal(customerId, customerName) {
    document.getElementById('delete_customer_id').value    = customerId;
    document.getElementById('delete_customer_name').textContent = customerName;
    openModal('deleteModal');
  }

  /* ── Live search (optional: auto-submit on input after short delay) ── */
  (function() {
    var searchInput = document.querySelector('.searchbar input[name="search"]');
    if (!searchInput) return;
    var timer;
    searchInput.addEventListener('input', function() {
      clearTimeout(timer);
      timer = setTimeout(function() {
        searchInput.closest('form').submit();
      }, 400);
    });
  })();

  /* ── Auto-dismiss flash messages after 4 s ── */
  setTimeout(function() {
    var msgs = document.querySelectorAll('.flash-msg');
    msgs.forEach(function(m) {
      m.style.transition = 'opacity .5s';
      m.style.opacity    = '0';
      setTimeout(function() { m.remove(); }, 500);
    });
  }, 4000);
</script>

</body>
</html>