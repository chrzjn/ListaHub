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

        /* ── UPDATE CUSTOMER (Edit Customer modal) ── */
        if ($action === 'update_customer') {
            $debt_id         = (int)   ($_POST['credit_id']      ?? 0);
            $customer_id     = (int)   ($_POST['customer_id']    ?? 0);
            $customer_name   = trim(   $_POST['customer_name']   ?? '');
            $contact_number  = trim(   $_POST['contact_number']  ?? '');
            $contact_address = trim(   $_POST['contact_address'] ?? '');
            $amount_paid     = (float) ($_POST['amount_paid']    ?? 0);
            $settlement_date = $_POST['settlement_date'] ?? null;
            $status          = $_POST['status'] ?? 'Unpaid';
            $notes           = trim($_POST['notes'] ?? '');

            $allowed = ['Unpaid', 'Partially Paid', 'Fully Paid'];
            if (!in_array($status, $allowed)) $status = 'Unpaid';

            /* Combine address + phone into contact_number field (pipe-separated) */
            if ($contact_address !== '' && $contact_number !== '') {
                $combined_contact = $contact_address . ' || ' . $contact_number;
            } elseif ($contact_address !== '') {
                $combined_contact = $contact_address;
            } else {
                $combined_contact = $contact_number;
            }

            if ($debt_id && $customer_id) {
                $pdo->beginTransaction();

                /* 1. Update Customer name & contact in correct table */
                $updCust = $pdo->prepare("
                    UPDATE Customer
                    SET customer_name  = :customer_name,
                        contact_number = :contact_number
                    WHERE customer_id = :customer_id
                ");
                $updCust->execute([
                    ':customer_name'  => $customer_name,
                    ':contact_number' => $combined_contact,
                    ':customer_id'    => $customer_id,
                ]);

                /* 2. Fetch current remaining balance for validation */
                $balStmt = $pdo->prepare("
                    SELECT remaining_balance, status FROM Debt WHERE debt_id = :debt_id
                ");
                $balStmt->execute([':debt_id' => $debt_id]);
                $debtRow = $balStmt->fetch(PDO::FETCH_ASSOC);
                $currentBalance = (float) ($debtRow['remaining_balance'] ?? 0);

                /* 3. If amount paid > 0, insert Debt_Payment.
                      The trigger trg_debt_payment_after_insert will automatically:
                        - Deduct from Debt.remaining_balance
                        - Update Debt.status (Partially Paid / Fully Paid)
                        - Update Customer.total_outstanding */
                if ($amount_paid > 0) {
                    if ($amount_paid > $currentBalance) {
                        $pdo->rollBack();
                        $error = 'Payment amount (₱' . number_format($amount_paid, 2) . ') exceeds remaining balance (₱' . number_format($currentBalance, 2) . ').';
                    } else {
                        $payStmt = $pdo->prepare("
                            INSERT INTO Debt_Payment (debt_id, payment_date, amount_paid)
                            VALUES (:debt_id, CURDATE(), :amount_paid)
                        ");
                        $payStmt->execute([
                            ':debt_id'     => $debt_id,
                            ':amount_paid' => $amount_paid,
                        ]);

                        /* Update settlement date if provided */
                        if (!empty($settlement_date)) {
                            $pdo->prepare("
                                UPDATE Debt SET settlement_date = :sd WHERE debt_id = :debt_id
                            ")->execute([':sd' => $settlement_date, ':debt_id' => $debt_id]);
                        }

                        $pdo->commit();
                        $message = 'Payment of ₱' . number_format($amount_paid, 2) . ' recorded successfully.';
                    }

                } else {
                    /* No payment — manual status/date update only */
                    if ($status === 'Fully Paid' && $currentBalance > 0) {
                        /* Force-close: zero out the balance and mark paid */
                        $pdo->prepare("
                            UPDATE Debt
                            SET remaining_balance = 0,
                                status            = 'Fully Paid',
                                settlement_date   = :sd
                            WHERE debt_id = :debt_id
                        ")->execute([
                            ':sd'      => !empty($settlement_date) ? $settlement_date : date('Y-m-d'),
                            ':debt_id' => $debt_id,
                        ]);

                        /* Sync Customer.total_outstanding */
                        $pdo->prepare("
                            UPDATE Customer
                            SET total_outstanding = GREATEST(total_outstanding - :bal, 0)
                            WHERE customer_id = :customer_id
                        ")->execute([
                            ':bal'         => $currentBalance,
                            ':customer_id' => $customer_id,
                        ]);

                    } else {
                        /* Just update settlement date and/or status label */
                        $pdo->prepare("
                            UPDATE Debt
                            SET status          = :status,
                                settlement_date = :sd
                            WHERE debt_id = :debt_id
                        ")->execute([
                            ':status'  => $status,
                            ':sd'      => !empty($settlement_date) ? $settlement_date : null,
                            ':debt_id' => $debt_id,
                        ]);
                    }

                    $pdo->commit();
                    $message = 'Customer updated successfully.';
                }
            }
        }

        /* ── DELETE CUSTOMER ── */
        if ($action === 'delete') {
            $customer_id = (int) ($_POST['customer_id'] ?? 0);

            if ($customer_id) {
                $pdo->beginTransaction();

                /* Remove Debt_Payment rows first (FK chain) */
                $pdo->prepare("
                    DELETE dp FROM Debt_Payment dp
                    JOIN Debt d ON d.debt_id = dp.debt_id
                    JOIN Sale s ON s.sale_id = d.sale_id
                    WHERE s.customer_id = :customer_id
                ")->execute([':customer_id' => $customer_id]);

                /* Remove Debt rows */
                $pdo->prepare("
                    DELETE d FROM Debt d
                    JOIN Sale s ON s.sale_id = d.sale_id
                    WHERE s.customer_id = :customer_id
                ")->execute([':customer_id' => $customer_id]);

                /* Nullify customer_id on Sales so history is preserved */
                $pdo->prepare("
                    UPDATE Sale SET customer_id = NULL WHERE customer_id = :customer_id
                ")->execute([':customer_id' => $customer_id]);

                /* Delete the Customer row */
                $pdo->prepare("
                    DELETE FROM Customer WHERE customer_id = :customer_id
                ")->execute([':customer_id' => $customer_id]);

                $pdo->commit();
                $message = 'Customer deleted.';
            }
        }

    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        $error = 'Database error: ' . htmlspecialchars($e->getMessage());
    }
}

/* ============================================================
   FETCH DATA  —  reads Customer, Sale, Debt tables via views
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
            COUNT(DISTINCT CASE WHEN co.total_remaining > 0 THEN co.customer_id END) AS unsettled_count,
            COUNT(DISTINCT co.customer_id)                                            AS total_customers,
            COALESCE(SUM(co.total_remaining), 0)                                     AS total_credit
        FROM vw_customer_outstanding co
        WHERE co.customer_id IN (
            SELECT DISTINCT s.customer_id
            FROM Sale s
            JOIN Sale_Item si ON si.sale_id   = s.sale_id
            JOIN Product   p  ON p.product_id = si.product_id
            WHERE p.user_id = :user_id
              AND s.customer_id IS NOT NULL
        )
    ");
    $summaryStmt->execute([':user_id' => $user_id]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    /* ── Build WHERE conditions ── */
    $whereParts  = [];
    $countParams = [':user_id' => $user_id];

    if ($search) {
        $whereParts[]           = "vdd.customer_name LIKE :search";
        $countParams[':search'] = "%$search%";
    }
    if ($filterStatus) {
        $whereParts[]           = "vdd.debt_status = :status";
        $countParams[':status'] = $filterStatus;
    }

    $whereClause = $whereParts ? ('AND ' . implode(' AND ', $whereParts)) : '';

    /* ── Count rows for pagination ── */
    $countSql = "
        SELECT COUNT(*) AS total
        FROM vw_customer_debt_detail vdd
        WHERE vdd.customer_id IN (
            SELECT DISTINCT s.customer_id
            FROM Sale s
            JOIN Sale_Item si ON si.sale_id   = s.sale_id
            JOIN Product   p  ON p.product_id = si.product_id
            WHERE p.user_id = :user_id
              AND s.customer_id IS NOT NULL
        )
        $whereClause
    ";

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $totalRows  = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRows / $perPage));

    /* ── Customer list ── */
    $listParams = $countParams;
    $sql = "
        SELECT
            vdd.customer_id,
            vdd.customer_name,
            vdd.contact_number,
            vdd.created_at,
            vdd.debt_id           AS credit_id,
            vdd.remaining_balance AS amount_owed,
            vdd.original_amount,
            vdd.settlement_date,
            vdd.debt_status       AS status
        FROM vw_customer_debt_detail vdd
        WHERE vdd.customer_id IN (
            SELECT DISTINCT s.customer_id
            FROM Sale s
            JOIN Sale_Item si ON si.sale_id   = s.sale_id
            JOIN Product   p  ON p.product_id = si.product_id
            WHERE p.user_id = :user_id
              AND s.customer_id IS NOT NULL
        )
        $whereClause
        ORDER BY vdd.created_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($listParams as $k => $v) $stmt->bindValue($k, $v);
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
        'Fully Paid'     => ['label' => 'Fully Paid',     'class' => 'fully-paid'],
        'Unpaid'         => ['label' => 'Unpaid',          'class' => 'unpaid'],
        'Partially Paid' => ['label' => 'Partially Paid',  'class' => 'partially-paid'],
        default          => ['label' => 'Partially Paid',  'class' => 'partially-paid'],
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

  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />

  <link rel="stylesheet" href="global_sidebar.css" />
  <link rel="stylesheet" href="global_customers.css" />
  <link rel="stylesheet" href="sidebar.css" />
  <link rel="stylesheet" href="customers.css" />
  <link rel="stylesheet" href="main-body.css" />

  <style>
  .edit-customer-box {
    max-width: 560px !important;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    background: linear-gradient(
        146.01deg,
        rgba(253, 253, 253, 0.58),
        rgba(254, 246, 227, 0.49) 49.52%,
        rgba(255, 244, 216, 0.6)
      ),
      linear-gradient(rgba(252, 248, 238, 0.2), rgba(252, 248, 238, 0.2)) !important;
    border-radius: 15px !important;
    border: 2px solid var(--text-brown) !important;
    box-shadow: 36px 30px 13px transparent,
                23px 19px 12px rgba(62, 44, 35, 0.01),
                13px 11px 10px rgba(62, 44, 35, 0.05),
                6px 5px 8px rgba(62, 44, 35, 0.09),
                1px 1px 4px rgba(62, 44, 35, 0.1) !important;
    backdrop-filter: blur(20.6px);
    -webkit-backdrop-filter: blur(20.6px);
    gap: 0 !important;
    padding: 17px 15px !important;
    animation: modalIn 0.2s ease;
  }
  .ec-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
  }
  .ec-title {
    margin: 0;
    font-size: 28px;
    font-weight: 800;
    letter-spacing: -0.04em;
    color: #3e2c23;
    font-family: Inter, sans-serif;
  }
  .ec-close-btn {
    background: transparent;
    border: none;
    font-size: 18px;
    color: #3e2c23;
    cursor: pointer;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s;
    flex-shrink: 0;
  }
  .ec-close-btn:hover { background: rgba(62,44,35,0.1); }
  .ec-form-wrap {
    background-image: url('pics_icons/Product-Form@3x.png');
    background-size: cover;
    background-repeat: no-repeat;
    background-position: top;
    background-color: rgba(253,243,219,0.25);
    border-radius: 10px;
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .ec-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }
  .ec-label {
    font-size: 16px;
    font-weight: 600;
    letter-spacing: -0.04em;
    color: #3e2c23;
    font-family: Inter, sans-serif;
  }
  .ec-input-wrap {
    border-radius: 10px;
    background-color: rgba(253,243,219,0.55);
    border: 1px solid rgba(0,0,0,0.2);
    overflow: hidden;
    display: flex;
    align-items: center;
  }
  .ec-textarea-wrap {
    flex-direction: column;
    align-items: stretch;
  }
  .ec-input {
    width: 100%;
    border: none;
    outline: none;
    background: transparent;
    font-family: Inter, sans-serif;
    font-size: 15px;
    color: rgba(43,43,43,0.85);
    letter-spacing: -0.04em;
    padding: 10px 12px 10px 14px;
    box-sizing: border-box;
  }
  .ec-input::placeholder { color: rgba(62,44,35,0.4); }
  .ec-input-border-top { border-top: 1px solid rgba(0,0,0,0.15); }
  .ec-textarea {
    width: 100%;
    border: none;
    outline: none;
    background: transparent;
    font-family: Inter, sans-serif;
    font-size: 15px;
    color: rgba(43,43,43,0.85);
    letter-spacing: -0.04em;
    padding: 10px 12px 10px 14px;
    box-sizing: border-box;
    resize: none;
    min-height: 72px;
  }
  .ec-textarea::placeholder { color: rgba(62,44,35,0.4); }
  .ec-peso-wrap { gap: 0; }
  .ec-peso {
    padding-left: 14px;
    font-size: 15px;
    font-weight: 600;
    color: #3e2c23;
    flex-shrink: 0;
  }
  .ec-input-pl { padding-left: 6px; }
  .ec-date-wrap { padding-left: 10px; gap: 6px; }
  .ec-cal-icon {
    font-size: 16px;
    color: #3e2c23;
    opacity: 0.7;
    flex-shrink: 0;
  }
  input[type="date"].ec-input { padding-left: 6px; color: #3e2c23; }
  .ec-status-wrap {
    padding: 0;
    justify-content: center;
    min-height: 41px;
  }
  .ec-select-status {
    width: 100%;
    height: 100%;
    border: none;
    outline: none;
    background: transparent;
    font-family: Inter, sans-serif;
    font-size: 14px;
    font-weight: 700;
    font-style: italic;
    letter-spacing: -0.04em;
    padding: 10px 12px;
    cursor: pointer;
    appearance: auto;
    color: #ff383c;
  }
  .ec-status-unpaid  .ec-select-status { color: #ff383c; }
  .ec-status-partial .ec-select-status { color: #e6a817; }
  .ec-status-paid    .ec-select-status { color: #159459; }
  .ec-row-3 {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: flex-start;
  }
  .ec-row-3 .ec-field { flex: 1; min-width: 120px; }
  .ec-footer {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    padding-top: 6px;
  }
  .ec-btn-cancel {
    background: rgba(252,248,238,0);
    border: none;
    font-family: Inter, sans-serif;
    font-size: 15px;
    font-weight: 500;
    color: #3e2c23;
    cursor: pointer;
    padding: 10px 20px;
    border-radius: 231px;
    letter-spacing: -0.04em;
    transition: background 0.15s;
  }
  .ec-btn-cancel:hover { background: rgba(62,44,35,0.08); }
  .ec-btn-update {
    background: rgba(235,214,101,0.66);
    border: 2px solid #3e2c23;
    font-family: Inter, sans-serif;
    font-size: 15px;
    font-weight: 500;
    color: #3e2c23;
    cursor: pointer;
    padding: 10px 24px;
    border-radius: 231px;
    letter-spacing: -0.04em;
    box-shadow:
      -4px -3px 5px rgba(44,44,44,0.09) inset,
      -1px -1px 3px rgba(44,44,44,0.1) inset;
    transition: background 0.15s;
  }
  .ec-btn-update:hover { background: rgba(220,200,80,0.85); }

  /* Balance preview inside modal */
  .ec-balance-preview {
    font-size: 13px;
    color: #3e2c23;
    opacity: 0.7;
    font-family: Inter, sans-serif;
    letter-spacing: -0.03em;
    margin-top: 2px;
  }
  .ec-balance-preview span { font-weight: 700; opacity: 1; color: #e05a00; }

  @media screen and (max-width: 600px) {
    .ec-row-3 { flex-direction: column; }
    .ec-title  { font-size: 22px; }
  }
  </style>
</head>
<body>

<div class="customer-page">
  <div class="page-container">

    <?php include 'sidebar.php'; ?>

    <main class="main-body">

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

            <div class="summary-card card-unsettled">
              <div class="card-inner">
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

            <div class="summary-card card-credit">
              <div class="card-inner">
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

            <div class="summary-card card-total">
              <div class="card-inner">
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

          </div>
        </div>
      </section>

      <!-- ── TABLE SECTION ── -->
      <div class="table-container-wrap">

        <div class="table-actions-bar">
          <div class="actions-top-row">

            <div class="actions-left">
              <form method="get" action="customers.php" style="display:contents;">
                <div class="searchbar">
                  <i class="bi bi-search searchbar-icon"></i>
                  <input
                    type="text"
                    name="search"
                    placeholder="Search for customer"
                    value="<?= htmlspecialchars($search) ?>"
                    autocomplete="off"
                  />
                </div>

                <select name="status" class="category-btn" onchange="this.form.submit()" title="Filter by status">
                  <option value="">Category</option>
                  <option value="Partially Paid" <?= $filterStatus === 'Partially Paid' ? 'selected' : '' ?>>Partially Paid</option>
                  <option value="Fully Paid"     <?= $filterStatus === 'Fully Paid'     ? 'selected' : '' ?>>Fully Paid</option>
                  <option value="Unpaid"         <?= $filterStatus === 'Unpaid'         ? 'selected' : '' ?>>Unpaid</option>
                </select>

                <button type="submit" style="display:none;"></button>
              </form>
            </div>

            <div class="actions-right-group">
              <a href="customers.php?export=csv&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>"
                 class="export-btn">
                <i class="bi bi-file-earmark-arrow-down"></i>
                Export list
              </a>
            </div>

          </div>
        </div>

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
                  $info    = statusInfo($row['status'] ?? 'Unpaid');
                  $hasDate = !empty($row['settlement_date']);
                ?>
                <tr>
                  <td class="col-name"><?= htmlspecialchars($row['customer_name']) ?></td>

                  <td>₱ <?= number_format((float)$row['amount_owed'], 2) ?></td>

                  <td class="col-date">
                    <?php if ($hasDate): ?>
                      <div class="date-cell">
                        <?= htmlspecialchars(date('m/d/y', strtotime($row['settlement_date']))) ?>
                        <i class="bi bi-calendar3"></i>
                      </div>
                    <?php else: ?>
                      –
                    <?php endif; ?>
                  </td>

                  <td class="col-status">
                    <span class="status-badge <?= $info['class'] ?>">
                      <?= $info['label'] ?>
                    </span>
                  </td>

                  <td class="col-actions">
                    <div class="actions-cell">
                      <button
                        class="action-icon-btn btn-view"
                        title="View / Edit"
                        onclick="openEditModal(
                          <?= (int)   $row['credit_id']    ?>,
                          <?= (int)   $row['customer_id']  ?>,
                          '<?= htmlspecialchars($row['customer_name'],               ENT_QUOTES) ?>',
                          '<?= htmlspecialchars($row['contact_number'] ?? '',        ENT_QUOTES) ?>',
                          <?= (float) $row['amount_owed']  ?>,
                          <?= (float) ($row['original_amount'] ?? $row['amount_owed']) ?>,
                          '<?= htmlspecialchars($row['settlement_date'] ?? '',       ENT_QUOTES) ?>',
                          '<?= htmlspecialchars($row['status']          ?? 'Unpaid', ENT_QUOTES) ?>'
                        )"
                      >
                        <i class="bi bi-eye"></i>
                      </button>

                      <button
                        class="action-icon-btn btn-delete"
                        title="Delete customer"
                        onclick="openDeleteModal(<?= (int)$row['customer_id'] ?>, '<?= htmlspecialchars($row['customer_name'], ENT_QUOTES) ?>')"
                      >
                        <i class="bi bi-trash"></i>
                      </button>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <footer class="pagination-area">
          <?php if ($currentPage > 1): ?>
            <a href="<?= pageUrl($currentPage - 1, $search, $filterStatus) ?>" class="page-btn">
              <i class="bi bi-arrow-left"></i> Prev
            </a>
          <?php else: ?>
            <button class="page-btn" disabled><i class="bi bi-arrow-left"></i> Prev</button>
          <?php endif; ?>

          <span style="font-size:var(--fs-14);color:var(--1-brown);font-family:var(--font-inter);">
            Page <?= $currentPage ?> of <?= $totalPages ?>
          </span>

          <?php if ($currentPage < $totalPages): ?>
            <a href="<?= pageUrl($currentPage + 1, $search, $filterStatus) ?>" class="page-btn">
              Next <i class="bi bi-arrow-right"></i>
            </a>
          <?php else: ?>
            <button class="page-btn" disabled>Next <i class="bi bi-arrow-right"></i></button>
          <?php endif; ?>
        </footer>

      </div>

    </main>
  </div>
</div>


<!-- ============================================================
     MODAL: EDIT CUSTOMER
     ============================================================ -->
<div class="modal-overlay" id="editModal">
  <div class="modal-box edit-customer-box">

    <div class="ec-header">
      <h2 class="ec-title">Edit Customer</h2>
      <button type="button" class="ec-close-btn" onclick="closeModal('editModal')">&#x2715;</button>
    </div>

    <form method="post" action="customers.php" id="editCustomerForm">
      <input type="hidden" name="action"      value="update_customer" />
      <input type="hidden" name="credit_id"   id="edit_credit_id" />
      <input type="hidden" name="customer_id" id="edit_customer_id" />

      <div class="ec-form-wrap">

        <!-- Customer Name -->
        <div class="ec-field">
          <label class="ec-label">Customer Name</label>
          <div class="ec-input-wrap">
            <input class="ec-input" type="text" name="customer_name" id="edit_customer_name"
                   placeholder="Customer name" required />
          </div>
        </div>

        <!-- Contact Information -->
        <div class="ec-field">
          <label class="ec-label">Contact Information</label>
          <div class="ec-input-wrap ec-textarea-wrap">
            <input class="ec-input" type="text" name="contact_address" id="edit_contact_address"
                   placeholder="Address" />
            <input class="ec-input ec-input-border-top" type="text" name="contact_number" id="edit_contact_number"
                   placeholder="Contact number" />
          </div>
        </div>

        <!-- Amount Owed (read-only display) -->
        <div class="ec-field">
          <label class="ec-label">Remaining Balance</label>
          <div class="ec-input-wrap ec-peso-wrap">
            <span class="ec-peso">&#8369;</span>
            <input class="ec-input ec-input-pl" type="number" name="amount_owed" id="edit_amount_owed"
                   placeholder="0" min="0" step="0.01" readonly
                   style="opacity:0.7;cursor:not-allowed;" />
          </div>
          <p class="ec-balance-preview">
            Original debt: ₱<span id="edit_original_display">0.00</span>
            &nbsp;·&nbsp; After payment: ₱<span id="edit_after_display">—</span>
          </p>
        </div>

        <!-- Settlement Date | Status | Amount Paid -->
        <div class="ec-row-3">

          <div class="ec-field">
            <label class="ec-label">Settlement Date</label>
            <div class="ec-input-wrap ec-date-wrap">
              <i class="bi bi-calendar3 ec-cal-icon"></i>
              <input class="ec-input ec-input-pl" type="date" name="settlement_date" id="edit_settlement_date" />
            </div>
          </div>

          <div class="ec-field">
            <label class="ec-label">Status</label>
            <div class="ec-input-wrap ec-status-wrap" id="ec_status_display">
              <select class="ec-select-status" name="status" id="edit_status"
                      onchange="ecUpdateStatusDisplay(this); ecAutoFillOnFullyPaid();">
                <option value="Unpaid">Unpaid</option>
                <option value="Partially Paid">Partially Paid</option>
                <option value="Fully Paid">Fully Paid</option>
              </select>
            </div>
          </div>

          <div class="ec-field">
            <label class="ec-label">Amount Paid</label>
            <div class="ec-input-wrap ec-peso-wrap">
              <span class="ec-peso">&#8369;</span>
              <input class="ec-input ec-input-pl" type="number" name="amount_paid" id="edit_amount_paid"
                     placeholder="0" min="0" step="0.01"
                     oninput="ecUpdateAfterBalance()" />
            </div>
          </div>

        </div>

        <!-- Additional Notes -->
        <div class="ec-field">
          <label class="ec-label">Additional Notes</label>
          <div class="ec-input-wrap">
            <textarea class="ec-textarea" name="notes" id="edit_notes"
                      placeholder="Enter Here"></textarea>
          </div>
        </div>

        <!-- Footer -->
        <div class="ec-footer">
          <button type="button" class="ec-btn-cancel" onclick="closeModal('editModal')">Cancel</button>
          <button type="submit" class="ec-btn-update">Update</button>
        </div>

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
  function openModal(id)  { document.getElementById(id).classList.add('active'); }
  function closeModal(id) { document.getElementById(id).classList.remove('active'); }

  document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
      if (e.target === overlay) overlay.classList.remove('active');
    });
  });

  /* Tracks the current remaining balance for live calculation */
  var _currentBalance  = 0;
  var _originalAmount  = 0;

  /* ── Open Edit Customer Modal ── */
  function openEditModal(creditId, customerId, customerName, contactNumber,
                         remainingBalance, originalAmount, settlementDate, status) {

    _currentBalance = remainingBalance;
    _originalAmount = originalAmount;

    document.getElementById('edit_credit_id').value   = creditId;
    document.getElementById('edit_customer_id').value = customerId;
    document.getElementById('edit_customer_name').value = customerName;

    /* Split "address || phone" */
    var parts = (contactNumber || '').split('||');
    document.getElementById('edit_contact_address').value = parts[0] ? parts[0].trim() : '';
    document.getElementById('edit_contact_number').value  = parts[1] ? parts[1].trim() : '';

    /* Remaining balance (read-only) */
    document.getElementById('edit_amount_owed').value = remainingBalance.toFixed(2);

    /* Preview labels */
    document.getElementById('edit_original_display').textContent =
      parseFloat(originalAmount).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('edit_after_display').textContent = '—';

    document.getElementById('edit_settlement_date').value = settlementDate;
    document.getElementById('edit_amount_paid').value     = '';
    document.getElementById('edit_notes').value           = '';

    var sel = document.getElementById('edit_status');
    sel.value = status;
    ecUpdateStatusDisplay(sel);

    openModal('editModal');
  }

  /* ── Live balance preview as user types amount paid ── */
  function ecUpdateAfterBalance() {
    var paid  = parseFloat(document.getElementById('edit_amount_paid').value) || 0;
    var after = _currentBalance - paid;
    var el    = document.getElementById('edit_after_display');

    if (paid <= 0) {
      el.textContent = '—';
      el.style.color = '';
    } else if (after < 0) {
      el.textContent = 'Exceeds balance!';
      el.style.color = '#cc0000';
    } else {
      el.textContent = after.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
      el.style.color = after === 0 ? '#159459' : '#e6a817';

      /* Auto-set status */
      var sel = document.getElementById('edit_status');
      sel.value = (after === 0) ? 'Fully Paid' : 'Partially Paid';
      ecUpdateStatusDisplay(sel);
    }
  }

  /* ── When status is set to Fully Paid, auto-fill payment = remaining ── */
  function ecAutoFillOnFullyPaid() {
    var sel = document.getElementById('edit_status');
    if (sel.value === 'Fully Paid') {
      document.getElementById('edit_amount_paid').value = _currentBalance.toFixed(2);
      ecUpdateAfterBalance();
    }
  }

  /* ── Colour the Status select based on value ── */
  function ecUpdateStatusDisplay(sel) {
    var wrap = document.getElementById('ec_status_display');
    wrap.classList.remove('ec-status-unpaid', 'ec-status-partial', 'ec-status-paid');
    if      (sel.value === 'Unpaid')         wrap.classList.add('ec-status-unpaid');
    else if (sel.value === 'Partially Paid') wrap.classList.add('ec-status-partial');
    else if (sel.value === 'Fully Paid')     wrap.classList.add('ec-status-paid');
  }

  /* ── Open Delete Modal ── */
  function openDeleteModal(customerId, customerName) {
    document.getElementById('delete_customer_id').value         = customerId;
    document.getElementById('delete_customer_name').textContent = customerName;
    openModal('deleteModal');
  }

  /* ── Live search ── */
  (function() {
    var searchInput = document.querySelector('.searchbar input[name="search"]');
    if (!searchInput) return;
    var timer;
    searchInput.addEventListener('input', function() {
      clearTimeout(timer);
      timer = setTimeout(function() { searchInput.closest('form').submit(); }, 400);
    });
  })();

  /* ── Auto-dismiss flash messages ── */
  setTimeout(function() {
    document.querySelectorAll('.flash-msg').forEach(function(m) {
      m.style.transition = 'opacity .5s';
      m.style.opacity    = '0';
      setTimeout(function() { m.remove(); }, 500);
    });
  }, 4000);
</script>

</body>
</html>