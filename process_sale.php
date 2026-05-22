<?php
// ============================================================
//  process_sale.php  —  AJAX endpoint for POS checkout
//  Called via fetch() from pos.php
//  Handles: cash sale (Cash / G-Cash) and credit sale (Utang)
//  Uses stored procedures: sp_process_cash_sale,
//                          sp_process_credit_sale
// ============================================================
session_start();

header('Content-Type: application/json');

// ── Auth guard ───────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// ── Only accept POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

require_once './utils/lhdb.php';

$user_id = (int) $_SESSION['user_id'];

// ── Parse JSON body ───────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || !isset($data['type'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid or missing payload.']);
    exit;
}

$type = $data['type'];   // 'cash' | 'credit'

// ── Validate cart ─────────────────────────────────────────────
if (empty($data['items']) || !is_array($data['items'])) {
    echo json_encode(['success' => false, 'message' => 'Cart is empty.']);
    exit;
}

// Build comma-separated product_ids and quantities for stored procedures
$productIds = [];
$quantities = [];
foreach ($data['items'] as $item) {
    $pid = (int)($item['product_id'] ?? 0);
    $qty = (int)($item['qty']        ?? 0);
    if ($pid <= 0 || $qty <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid cart item.']);
        exit;
    }
    $productIds[] = $pid;
    $quantities[] = $qty;
}

$productIdsStr  = implode(',', $productIds);
$quantitiesStr  = implode(',', $quantities);

// ── Handle cash / G-Cash sale ─────────────────────────────────
if ($type === 'cash') {

    $tendered   = (float)($data['tendered']   ?? 0);
    $total      = (float)($data['total']      ?? 0);
    $pay_method = ($data['pay_method'] ?? 'cash');

    // G-Cash is stored as 'cash' in the DB (ENUM only allows cash|credit)
    // The pay_method label is informational; the DB payment_method = 'cash'

    if ($tendered < $total) {
        echo json_encode(['success' => false, 'message' => 'Kulang po ang pera mo.']);
        exit;
    }

    try {
        $pdo = getPDO();

        // Call stored procedure sp_process_cash_sale
        $stmt = $pdo->prepare(
            "CALL sp_process_cash_sale(:user_id, :product_ids, :quantities, :tendered, @p_sale_id, @p_message)"
        );
        $stmt->execute([
            ':user_id'     => $user_id,
            ':product_ids' => $productIdsStr,
            ':quantities'  => $quantitiesStr,
            ':tendered'    => $tendered,
        ]);
        $stmt->closeCursor();

        // Fetch OUT params
        $out = $pdo->query("SELECT @p_sale_id AS sale_id, @p_message AS message")->fetch(PDO::FETCH_ASSOC);

        $saleId  = (int)$out['sale_id'];
        $message = $out['message'];

        if ($saleId > 0) {
            echo json_encode([
                'success' => true,
                'sale_id' => $saleId,
                'message' => $message,
                'change'  => round($tendered - $total, 2),
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => $message ?: 'Sale failed.']);
        }

    } catch (PDOException $e) {
        error_log("process_sale (cash) error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
    }

// ── Handle credit / Utang sale ────────────────────────────────
} elseif ($type === 'credit') {

    $custName    = trim($data['customer_name']    ?? '');
    $custContact = trim($data['customer_contact'] ?? '');
    $custAddress = trim($data['customer_address'] ?? '');

    if (!$custName || !$custContact || !$custAddress) {
        echo json_encode(['success' => false, 'message' => 'Kulang pa ang info ng customer.']);
        exit;
    }

    try {
        $pdo = getPDO();

        // Call stored procedure sp_process_credit_sale
        $stmt = $pdo->prepare(
            "CALL sp_process_credit_sale(
                :user_id,
                :customer_name,
                :contact_number,
                :address,
                :product_ids,
                :quantities,
                @p_sale_id,
                @p_customer_id,
                @p_debt_id,
                @p_message
            )"
        );
        $stmt->execute([
            ':user_id'        => $user_id,
            ':customer_name'  => $custName,
            ':contact_number' => $custContact,
            ':address'        => $custAddress,
            ':product_ids'    => $productIdsStr,
            ':quantities'     => $quantitiesStr,
        ]);
        $stmt->closeCursor();

        $out = $pdo->query(
            "SELECT @p_sale_id AS sale_id, @p_customer_id AS customer_id, @p_debt_id AS debt_id, @p_message AS message"
        )->fetch(PDO::FETCH_ASSOC);

        $saleId     = (int)$out['sale_id'];
        $customerId = (int)$out['customer_id'];
        $debtId     = (int)$out['debt_id'];
        $message    = $out['message'];

        if ($saleId > 0) {
            echo json_encode([
                'success'     => true,
                'sale_id'     => $saleId,
                'customer_id' => $customerId,
                'debt_id'     => $debtId,
                'message'     => $message,
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => $message ?: 'Credit sale failed.']);
        }

    } catch (PDOException $e) {
        error_log("process_sale (credit) error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Unknown sale type.']);
}