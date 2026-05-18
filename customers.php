<?php
// ============================================================
//  customers.php  –  Customer Credit Management
//  Requirements met:
//   ✅ Session guard
//   ✅ PDO prepared statements (no variable interpolation)
//   ✅ Transactions (BEGIN / COMMIT / ROLLBACK)
//   ✅ try-catch
//   ✅ COUNT(), SUM() done in DB
//   ✅ CRUD: Create, Read, Update, Delete
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

// ── Handle POST actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $pdo = getPDO();

        // ── ADD CUSTOMER + CREDIT ─────────────────────────────
        if ($action === 'add') {
            $customer_name   = trim($_POST['customer_name']   ?? '');
            $amount_owed     = (float) ($_POST['amount_owed']     ?? 0);
            $settlement_date = $_POST['settlement_date'] ?? null;

            if (empty($customer_name) || $amount_owed <= 0) {
                $error = 'Customer name and a valid amount are required.';
            } else {
                $pdo->beginTransaction();

                // Insert customer
                $custStmt = $pdo->prepare(
                    "INSERT INTO customers (store_id, customer_name, created_at)
                     VALUES (:store_id, :customer_name, NOW())"
                );
                $custStmt->execute([
                    ':store_id'      => $store_id,
                    ':customer_name' => $customer_name,
                ]);
                $customer_id = (int) $pdo->lastInsertId();

                // Insert credit record
                $creditStmt = $pdo->prepare(
                    "INSERT INTO customer_credits (customer_id, amount_owed, settlement_date, status, created_at)
                     VALUES (:customer_id, :amount_owed, :settlement_date, 'pending', NOW())"
                );
                $creditStmt->execute([
                    ':customer_id'     => $customer_id,
                    ':amount_owed'     => $amount_owed,
                    ':settlement_date' => !empty($settlement_date) ? $settlement_date : null,
                ]);

                $pdo->commit();
                $message = 'Customer added successfully.';
            }
        }

        // ── UPDATE CREDIT STATUS ──────────────────────────────
        if ($action === 'update') {
            $credit_id       = (int)   ($_POST['credit_id']       ?? 0);
            $amount_owed     = (float) ($_POST['amount_owed']     ?? 0);
            $settlement_date = $_POST['settlement_date'] ?? null;
            $status          = $_POST['status'] ?? 'pending';

            $allowed_statuses = ['pending', 'settled', 'overdue'];
            if (!in_array($status, $allowed_statuses)) $status = 'pending';

            if ($credit_id && $amount_owed >= 0) {
                $pdo->beginTransaction();

                $updStmt = $pdo->prepare(
                    "UPDATE customer_credits
                     SET amount_owed     = :amount_owed,
                         settlement_date = :settlement_date,
                         status          = :status
                     WHERE id = :id
                       AND customer_id IN (
                           SELECT id FROM customers WHERE store_id = :store_id
                       )"
                );
                $updStmt->execute([
                    ':amount_owed'     => $amount_owed,
                    ':settlement_date' => !empty($settlement_date) ? $settlement_date : null,
                    ':status'          => $status,
                    ':id'              => $credit_id,
                    ':store_id'        => $store_id,
                ]);

                $pdo->commit();
                $message = 'Credit updated successfully.';
            }
        }

        // ── DELETE CUSTOMER ───────────────────────────────────
        if ($action === 'delete') {
            $customer_id = (int) ($_POST['customer_id'] ?? 0);
            if ($customer_id) {
                $pdo->beginTransaction();

                $delStmt = $pdo->prepare(
                    "DELETE FROM customers WHERE id = :id AND store_id = :store_id"
                );
                $delStmt->execute([
                    ':id'       => $customer_id,
                    ':store_id' => $store_id,
                ]);

                $pdo->commit();
                $message = 'Customer deleted.';
            }
        }

        // ── MARK AS SETTLED ───────────────────────────────────
        if ($action === 'settle') {
            $credit_id = (int) ($_POST['credit_id'] ?? 0);
            if ($credit_id) {
                $pdo->beginTransaction();

                $settleStmt = $pdo->prepare(
                    "UPDATE customer_credits
                     SET status = 'settled', amount_owed = 0
                     WHERE id = :id
                       AND customer_id IN (
                           SELECT id FROM customers WHERE store_id = :store_id
                       )"
                );
                $settleStmt->execute([
                    ':id'       => $credit_id,
                    ':store_id' => $store_id,
                ]);

                $pdo->commit();
                $message = 'Credit marked as settled.';
            }
        }

    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log("Customers error: " . $e->getMessage());
        $error = 'A database error occurred. Please try again.';
    }
}

// ── Fetch summary + table data ───────────────────────────────
try {
    $pdo = getPDO();

    // SUM() and COUNT() done in DB — not PHP loops
    $summaryStmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN cc.status = 'pending' AND cc.settlement_date <= CURDATE()
                              THEN cc.amount_owed ELSE 0 END), 0) AS on_due,
            COALESCE(SUM(CASE WHEN cc.status = 'pending'
                              THEN cc.amount_owed ELSE 0 END), 0) AS total_credit
         FROM customers c
         JOIN customer_credits cc ON cc.customer_id = c.id
         WHERE c.store_id = :store_id"
    );
    $summaryStmt->execute([':store_id' => $store_id]);
    $summary = $summaryStmt->fetch();

    // Customer list with JOIN — DB does the work
    $search = trim($_GET['search'] ?? '');
    $sql = "SELECT c.id AS customer_id, c.customer_name, c.created_at,
                   cc.id AS credit_id, cc.amount_owed, cc.settlement_date, cc.status
            FROM customers c
            LEFT JOIN customer_credits cc ON cc.customer_id = c.id
            WHERE c.store_id = :store_id";
    $params = [':store_id' => $store_id];

    if (!empty($search)) {
        $sql .= " AND c.customer_name LIKE :search";
        $params[':search'] = '%' . $search . '%';
    }
    $sql .= " ORDER BY c.created_at DESC";

    $custStmt = $pdo->prepare($sql);
    $custStmt->execute($params);
    $customers = $custStmt->fetchAll();

    // Transaction history — recent 20 credit changes
    $histStmt = $pdo->prepare(
        "SELECT c.customer_name, cc.amount_owed, cc.status,
                cc.settlement_date, cc.created_at
         FROM customer_credits cc
         JOIN customers c ON c.id = cc.customer_id
         WHERE c.store_id = :store_id
         ORDER BY cc.created_at DESC
         LIMIT 20"
    );
    $histStmt->execute([':store_id' => $store_id]);
    $history = $histStmt->fetchAll();

} catch (PDOException $e) {
    error_log("Customers fetch error: " . $e->getMessage());
    $summary   = ['on_due' => 0, 'total_credit' => 0];
    $customers = [];
    $history   = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Customers Credit – ListaHub</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --sidebar-bg: #4a4a4a;
      --sidebar-width: 240px;
      --main-bg: #f4f5f7;
      --card-bg: #e2e4e8;
      --table-bg: #d8dadd;
      --nav-item: #6b6b6b;
      --nav-text: #ddd;
      --nav-active: #e8e8e8;
    }

    body { font-family: 'Sora', sans-serif; display: flex; min-height: 100vh; background: var(--main-bg); }

    aside {
      width: var(--sidebar-width); background: var(--sidebar-bg);
      display: flex; flex-direction: column; padding: 20px 16px; gap: 8px;
      position: fixed; top: 0; left: 0; bottom: 0;
      border-radius: 0 16px 16px 0; z-index: 10;
    }
    .sidebar-logo { background: #6b6b6b; color: #eee; text-align: center; font-weight: 700; font-size: 15px; padding: 12px; border-radius: 12px; margin-bottom: 10px; }
    .sidebar-section-label { color: #aaa; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .8px; padding: 8px 8px 2px; }
    .sidebar-item { background: var(--nav-item); color: var(--nav-text); border-radius: 12px; padding: 11px 16px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; text-align: left; width: 100%; transition: background .2s; text-decoration: none; display: block; }
    .sidebar-item:hover { background: #7a7a7a; }
    .sidebar-item.active { background: var(--nav-active); color: #111; }
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

    .customers-layout {
      display: grid;
      grid-template-columns: 200px 1fr;
      gap: 20px;
      align-items: start;
    }

    .summary-col { display: flex; flex-direction: column; gap: 14px; }
    .summary-card { background: var(--card-bg); border-radius: 14px; padding: 18px 20px 26px; }
    .summary-card .label { font-size: 13px; color: #555; margin-bottom: 14px; }
    .summary-card .value { font-size: 22px; font-weight: 700; color: #111; }

    .table-panel {
      background: var(--table-bg); border-radius: 16px; padding: 16px 20px;
      display: flex; flex-direction: column; gap: 12px;
    }
    .table-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .btn-add-customer {
      background: #e8e8e8; border: 2px solid #333; border-radius: 50px;
      padding: 8px 18px; font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 700;
      cursor: pointer; display: flex; align-items: center; gap: 6px; transition: background .2s;
    }
    .btn-add-customer:hover { background: #d4d4d4; }
    .search-form { margin-left: auto; display: flex; align-items: center; gap: 8px; }
    .search-box {
      display: flex; align-items: center; background: #fff;
      border-radius: 50px; padding: 7px 14px; gap: 6px;
    }
    .search-box input { border: none; outline: none; font-family: 'Sora', sans-serif; font-size: 13px; width: 160px; }
    .search-btn { background: #6b6b6b; color: #fff; border: none; border-radius: 50px; padding: 7px 14px; font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 600; cursor: pointer; }

    table { width: 100%; border-collapse: collapse; background: #e8eaed; border-radius: 10px; overflow: hidden; }
    thead th { padding: 12px 14px; text-align: left; font-size: 13px; font-weight: 700; color: #222; background: #e0e2e6; }
    tbody tr { border-top: 1px solid #d0d2d6; }
    tbody tr:hover { background: #dfe1e5; }
    tbody td { padding: 14px 14px; font-size: 13px; color: #333; }

    .status-badge { font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 50px; }
    .status-pending  { background: #fef3c7; color: #92400e; }
    .status-settled  { background: #d1fae5; color: #065f46; }
    .status-overdue  { background: #fee2e2; color: #991b1b; }

    .action-icons { display: flex; gap: 8px; align-items: center; }
    .icon-btn { background: none; border: none; cursor: pointer; font-size: 17px; color: #444; padding: 3px 6px; border-radius: 6px; transition: background .2s; }
    .icon-btn:hover { background: #ccc; }

    .history-card { background: var(--card-bg); border-radius: 14px; padding: 20px 24px; }
    .history-title { font-size: 16px; font-weight: 700; color: #111; margin-bottom: 14px; text-align: center; }
    .history-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .history-table th, .history-table td { padding: 9px 12px; text-align: left; border-bottom: 1px solid #ccc; }
    .history-table th { color: #555; font-weight: 600; }

    /* MODAL */
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 100; justify-content: center; align-items: center; }
    .modal-overlay.open { display: flex; }
    .modal { background: #9a9a9a; border-radius: 16px; width: 480px; max-width: 95vw; overflow: hidden; box-shadow: 0 16px 60px rgba(0,0,0,.3); }
    .modal-header { background: #7a7a7a; padding: 20px 28px; }
    .modal-header h2 { font-size: 20px; font-weight: 800; color: #111; }
    .modal-body { padding: 24px 28px; display: flex; flex-direction: column; gap: 16px; }
    .field-group { display: flex; flex-direction: column; gap: 5px; }
    .field-label { font-size: 12px; font-weight: 600; color: #222; }
    .field-input { background: #c8c8c8; border: none; border-radius: 8px; padding: 10px 14px; font-family: 'Sora', sans-serif; font-size: 13px; outline: none; width: 100%; }
    .field-input:focus { background: #bbb; }
    .modal-footer { padding: 16px 28px; display: flex; justify-content: flex-end; gap: 12px; }
    .btn-cancel { background: none; border: none; font-family: 'Sora', sans-serif; font-size: 14px; font-weight: 600; color: #222; cursor: pointer; padding: 10px 16px; border-radius: 8px; }
    .btn-cancel:hover { background: #bbb; }
    .btn-save { background: #e8e8e8; border: none; border-radius: 50px; padding: 11px 28px; font-family: 'Sora', sans-serif; font-size: 14px; font-weight: 700; color: #111; cursor: pointer; }
    .btn-save:hover { background: #d4d4d4; }
    .delete-form { display: inline; }
    .settle-form { display: inline; }
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
  <a class="sidebar-item" href="sales.php">Sales Analytics</a>
  <div class="sidebar-section-label">Customer Credit</div>
  <a class="sidebar-item active" href="customers.php">Customers</a>
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

  <div class="page-title">Customers Credit</div>

  <?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="customers-layout">
    <!-- Left: Summary -->
    <div class="summary-col">
      <div class="summary-card">
        <div class="label">On Due</div>
        <div class="value">₱<?= number_format((float)($summary['on_due'] ?? 0), 2) ?></div>
      </div>
      <div class="summary-card">
        <div class="label">Total Amount on Credit</div>
        <div class="value">₱<?= number_format((float)($summary['total_credit'] ?? 0), 2) ?></div>
      </div>
    </div>

    <!-- Right: Table -->
    <div class="table-panel">
      <div class="table-toolbar">
        <button class="btn-add-customer" onclick="openModal('add')">
          <i class="bi bi-person-fill-add"></i> Add Customer
        </button>
        <form class="search-form" method="get">
          <div class="search-box">
            <input type="text" name="search" placeholder="Search…" value="<?= htmlspecialchars($search ?? '') ?>"/>
            <span><i class="bi bi-search-heart"></i></span>
          </div>
          <button type="submit" class="search-btn">Go</button>
        </form>
      </div>

      <table>
        <thead>
          <tr>
            <th>Customer Name</th>
            <th>Money Owed</th>
            <th>Settlement Date</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($customers)): ?>
            <tr>
              <td colspan="5" style="text-align:center; color:#aaa; padding: 40px 0;">
                No customers yet. Add a customer to get started.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($customers as $c): ?>
              <?php
                $statusClass = 'status-' . ($c['status'] ?? 'pending');
                $statusLabel = ucfirst($c['status'] ?? 'pending');
              ?>
              <tr>
                <td><?= htmlspecialchars($c['customer_name']) ?></td>
                <td>₱<?= number_format((float)($c['amount_owed'] ?? 0), 2) ?></td>
                <td><?= $c['settlement_date'] ? htmlspecialchars($c['settlement_date']) : '—' ?></td>
                <td>
                  <span class="status-badge <?= $statusClass ?>">
                    <?= $statusLabel ?>
                  </span>
                </td>
                <td class="action-icons">
                  <!-- Edit -->
                  <button class="icon-btn" title="Edit"
                    onclick="openEditModal(<?= htmlspecialchars(json_encode($c)) ?>)">
                    <i class="bi bi-pencil-fill"></i>
                  </button>
                  <!-- Settle -->
                  <?php if (($c['status'] ?? '') === 'pending'): ?>
                  <form class="settle-form" method="post"
                        onsubmit="return confirm('Mark this credit as settled?')">
                    <input type="hidden" name="action"    value="settle"/>
                    <input type="hidden" name="credit_id" value="<?= (int)$c['credit_id'] ?>"/>
                    <button type="submit" class="icon-btn" title="Mark Settled">
                      <i class="bi bi-check-circle-fill" style="color:#15803d;"></i>
                    </button>
                  </form>
                  <?php endif; ?>
                  <!-- Delete -->
                  <form class="delete-form" method="post"
                        onsubmit="return confirm('Delete this customer and all credit records?')">
                    <input type="hidden" name="action"      value="delete"/>
                    <input type="hidden" name="customer_id" value="<?= (int)$c['customer_id'] ?>"/>
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

  <!-- Transaction History -->
  <div class="history-card">
    <div class="history-title">Transaction History</div>
    <?php if (empty($history)): ?>
      <p style="text-align:center; color:#aaa; font-size:13px;">No credit history yet.</p>
    <?php else: ?>
      <table class="history-table">
        <thead>
          <tr>
            <th>Customer</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Settlement Date</th>
            <th>Date Added</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td><?= htmlspecialchars($h['customer_name']) ?></td>
              <td>₱<?= number_format((float)$h['amount_owed'], 2) ?></td>
              <td>
                <span class="status-badge status-<?= $h['status'] ?>">
                  <?= ucfirst($h['status']) ?>
                </span>
              </td>
              <td><?= $h['settlement_date'] ? htmlspecialchars($h['settlement_date']) : '—' ?></td>
              <td><?= htmlspecialchars(date('M d, Y', strtotime($h['created_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<!-- ADD CUSTOMER MODAL -->
<div class="modal-overlay" id="modal-add">
  <div class="modal">
    <div class="modal-header"><h2>Add Customer Credit</h2></div>
    <form method="post">
      <input type="hidden" name="action" value="add"/>
      <div class="modal-body">
        <div class="field-group">
          <label class="field-label">Customer Name *</label>
          <input class="field-input" type="text" name="customer_name" required placeholder="Enter customer name"/>
        </div>
        <div class="field-group">
          <label class="field-label">Amount Owed (₱) *</label>
          <input class="field-input" type="number" name="amount_owed" step="0.01" min="0.01" required placeholder="0.00"/>
        </div>
        <div class="field-group">
          <label class="field-label">Settlement Date</label>
          <input class="field-input" type="date" name="settlement_date"/>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('add')">Cancel</button>
        <button type="submit" class="btn-save">Add Customer</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT CREDIT MODAL -->
<div class="modal-overlay" id="modal-edit">
  <div class="modal">
    <div class="modal-header"><h2>Edit Credit</h2></div>
    <form method="post">
      <input type="hidden" name="action" value="update"/>
      <input type="hidden" name="credit_id" id="edit-credit-id"/>
      <div class="modal-body">
        <div class="field-group">
          <label class="field-label">Customer</label>
          <input class="field-input" type="text" id="edit-cust-name" disabled/>
        </div>
        <div class="field-group">
          <label class="field-label">Amount Owed (₱)</label>
          <input class="field-input" type="number" name="amount_owed" id="edit-amount" step="0.01" min="0" required/>
        </div>
        <div class="field-group">
          <label class="field-label">Settlement Date</label>
          <input class="field-input" type="date" name="settlement_date" id="edit-settlement"/>
        </div>
        <div class="field-group">
          <label class="field-label">Status</label>
          <select class="field-input" name="status" id="edit-status">
            <option value="pending">Pending</option>
            <option value="settled">Settled</option>
            <option value="overdue">Overdue</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('edit')">Cancel</button>
        <button type="submit" class="btn-save">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(type) { document.getElementById('modal-' + type).classList.add('open'); }
function closeModal(type) { document.getElementById('modal-' + type).classList.remove('open'); }

function openEditModal(c) {
  document.getElementById('edit-credit-id').value  = c.credit_id  || '';
  document.getElementById('edit-cust-name').value  = c.customer_name || '';
  document.getElementById('edit-amount').value      = c.amount_owed || 0;
  document.getElementById('edit-settlement').value  = c.settlement_date || '';
  document.getElementById('edit-status').value      = c.status || 'pending';
  openModal('edit');
}

document.querySelectorAll('.modal-overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});
</script>
</body>
</html>