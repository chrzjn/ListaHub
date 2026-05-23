<?php
// ============================================================
//  restock.php  — with inline Edit Product overlay
//  Changes vs original:
//   • Eye icon opens Edit Product modal (same page, no redirect)
//   • Add Qty stepper default = 0 (Complete saves all qty > 0)
//   • action=update  → edits product + logs 'manual' Inventory_Log
//   • action=delete  → deletes product + logs 'manual' Inventory_Log
//   • Complete button batch-restocks every row whose qty > 0
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

// ── Handle POST actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── SINGLE RESTOCK (eye button form submit) ───────────────
    if ($action === 'restock') {
        $product_id   = (int)   ($_POST['product_id']  ?? 0);
        $qty_added    = (int)   ($_POST['qty_added']   ?? 0);
        $cost_at_rest = (float) ($_POST['cost_price']  ?? 0);

        if ($product_id > 0 && $qty_added > 0) {
            try {
                $pdo = getPDO();
                $pdo->beginTransaction();

                $txStmt = $pdo->prepare(
                    "INSERT INTO Restock_Transaction (restock_date, total_cost)
                     VALUES (NOW(), :total_cost)"
                );
                $txStmt->execute([':total_cost' => $cost_at_rest * $qty_added]);
                $restock_id = (int) $pdo->lastInsertId();

                if ($cost_at_rest <= 0) {
                    $priceStmt = $pdo->prepare(
                        "SELECT cost_price FROM Product WHERE product_id = :id AND user_id = :uid"
                    );
                    $priceStmt->execute([':id' => $product_id, ':uid' => $user_id]);
                    $pRow = $priceStmt->fetch();
                    $cost_at_rest = (float)($pRow['cost_price'] ?? 0);
                }

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

    // ── BATCH COMPLETE (all rows with qty > 0) ────────────────
    } elseif ($action === 'batch_restock') {
        $items = $_POST['batch'] ?? [];
        if (!empty($items)) {
            try {
                $pdo = getPDO();
                $pdo->beginTransaction();

                foreach ($items as $product_id => $qty_added) {
                    $product_id = (int)$product_id;
                    $qty_added  = (int)$qty_added;
                    if ($product_id <= 0 || $qty_added <= 0) continue;

                    $priceStmt = $pdo->prepare(
                        "SELECT cost_price FROM Product WHERE product_id = :id AND user_id = :uid"
                    );
                    $priceStmt->execute([':id' => $product_id, ':uid' => $user_id]);
                    $pRow = $priceStmt->fetch();
                    $cost_at_rest = (float)($pRow['cost_price'] ?? 0);

                    $txStmt = $pdo->prepare(
                        "INSERT INTO Restock_Transaction (restock_date, total_cost)
                         VALUES (NOW(), :total_cost)"
                    );
                    $txStmt->execute([':total_cost' => $cost_at_rest * $qty_added]);
                    $restock_id = (int) $pdo->lastInsertId();

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
                }

                $pdo->commit();
                $message = 'Restock complete! Inventory has been updated.';
            } catch (PDOException $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                error_log("Batch restock error: " . $e->getMessage());
                $error = 'A database error occurred during batch restock. Please try again.';
            }
        } else {
            $error = 'No quantities entered. Please add at least one quantity before completing.';
        }

    // ── UPDATE PRODUCT (from Edit overlay) ────────────────────
    } elseif ($action === 'update') {
        $product_id    = (int)   ($_POST['product_id']     ?? 0);
        $product_name  = trim(   $_POST['product_name']    ?? '');
        $category_name = trim(   $_POST['category']        ?? '');
        $expiry_date   =         $_POST['expiry_date']      ?? null;
        $no_expiry     = isset(  $_POST['no_expiry']);
        $quantity      = (int)   ($_POST['stock_quantity'] ?? 0);
        $cost_price    = (float) ($_POST['cost']           ?? 0);
        $retail_price  = (float) ($_POST['selling_price']  ?? 0);
        $notes         = trim(   $_POST['notes']           ?? '');

        if ($product_id > 0 && !empty($product_name)) {
            try {
                $pdo = getPDO();
                $pdo->beginTransaction();

                // Resolve category
                $target_cat = !empty($category_name) ? $category_name : 'Uncategorized';
                $insCAT = $pdo->prepare("INSERT IGNORE INTO Category (category_name) VALUES (:name)");
                $insCAT->execute([':name' => $target_cat]);
                $catStmt = $pdo->prepare("SELECT category_id FROM Category WHERE category_name = :name LIMIT 1");
                $catStmt->execute([':name' => $target_cat]);
                $cat = $catStmt->fetch();
                $cat_id = $cat ? (int)$cat['category_id'] : 1;

                $final_expiry = ($no_expiry || empty($expiry_date)) ? null : $expiry_date;

                // Snapshot old quantity for inventory log
                $oldStmt = $pdo->prepare(
                    "SELECT quantity, product_name FROM Product WHERE product_id = :id AND user_id = :uid"
                );
                $oldStmt->execute([':id' => $product_id, ':uid' => $user_id]);
                $old = $oldStmt->fetch();
                $old_qty  = (int)($old['quantity'] ?? 0);
                $old_name = $old['product_name'] ?? '';

                // Handle optional image upload
                $image_url = null;
                if (!empty($_FILES['product_image']['tmp_name'])) {
                    $upload_dir = './uploads/products/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    $ext     = strtolower(pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg','jpeg','png','gif','webp'];
                    if (in_array($ext, $allowed)) {
                        $filename = 'product_' . $product_id . '_' . time() . '.' . $ext;
                        $dest     = $upload_dir . $filename;
                        if (move_uploaded_file($_FILES['product_image']['tmp_name'], $dest)) {
                            $image_url = $dest;
                        }
                    }
                }

                if ($image_url) {
                    $upd = $pdo->prepare(
                        "UPDATE Product SET product_name=:pname, category_id=:cat, quantity=:qty,
                         cost_price=:cost, retail_price=:retail, expiration_date=:expiry,
                         notes=:notes, image_url=:img
                         WHERE product_id=:id AND user_id=:uid"
                    );
                    $upd->execute([
                        ':pname' => $product_name, ':cat' => $cat_id, ':qty' => $quantity,
                        ':cost'  => $cost_price,   ':retail' => $retail_price,
                        ':expiry'=> $final_expiry, ':notes' => $notes,
                        ':img'   => $image_url,    ':id' => $product_id, ':uid' => $user_id,
                    ]);
                } else {
                    $upd = $pdo->prepare(
                        "UPDATE Product SET product_name=:pname, category_id=:cat, quantity=:qty,
                         cost_price=:cost, retail_price=:retail, expiration_date=:expiry,
                         notes=:notes
                         WHERE product_id=:id AND user_id=:uid"
                    );
                    $upd->execute([
                        ':pname' => $product_name, ':cat' => $cat_id, ':qty' => $quantity,
                        ':cost'  => $cost_price,   ':retail' => $retail_price,
                        ':expiry'=> $final_expiry, ':notes' => $notes,
                        ':id'    => $product_id,   ':uid' => $user_id,
                    ]);
                }

                // Log the edit to Inventory_Log
                try {
                    $qty_diff    = $quantity - $old_qty;
                    $move_type   = $qty_diff >= 0 ? 'in' : 'out';
                    $qty_change  = abs($qty_diff) > 0 ? abs($qty_diff) : 1;
                    $stock_after = $qty_diff !== 0 ? $quantity : $old_qty;

                    $logStmt = $pdo->prepare(
                        "INSERT INTO Inventory_Log
                            (product_id, product_name_snap, movement_type,
                             quantity_change, stock_before, stock_after,
                             reference_type, adjustment_reason)
                         VALUES
                            (:pid, :pname, :move,
                             :change, :before, :after,
                             'product_edit', NULL)"
                    );
                    $logStmt->execute([
                        ':pid'    => $product_id,
                        ':pname'  => $product_name,
                        ':move'   => $move_type,
                        ':change' => $qty_change,
                        ':before' => $old_qty,
                        ':after'  => $stock_after,
                    ]);
                } catch (PDOException $logEx) {
                    error_log("Restock edit log error: " . $logEx->getMessage());
                }

                $pdo->commit();
                $message = 'Product updated successfully.';
            } catch (PDOException $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                error_log("Restock update error: " . $e->getMessage());
                $error = 'A database error occurred. Please try again.';
            }
        } else {
            $error = 'Product name is required.';
        }

    // ── DELETE PRODUCT ────────────────────────────────────────
    } elseif ($action === 'delete') {
    $product_id = (int) ($_POST['product_id'] ?? 0);
    if ($product_id > 0) {
        try {
            $pdo = getPDO();

            $snapStmt = $pdo->prepare(
                "SELECT quantity, product_name, expiration_date, status
                 FROM Product WHERE product_id = :id AND user_id = :uid"
            );
            $snapStmt->execute([':id' => $product_id, ':uid' => $user_id]);
            $snap = $snapStmt->fetch();

            if ($snap) {
                $is_expired = (!empty($snap['expiration_date']) && $snap['expiration_date'] < date('Y-m-d'))
                               || $snap['status'] === 'Expired';
                $snap_qty  = (int)$snap['quantity'];
                $snap_name = $snap['product_name'];

                $pdo->beginTransaction();
                $del = $pdo->prepare(
                    "DELETE FROM Product WHERE product_id = :id AND user_id = :uid"
                );
                $del->execute([':id' => $product_id, ':uid' => $user_id]);

                if ($del->rowCount() === 0) {
                    $pdo->rollBack();
                    $error = 'Product not found or access denied.';
                } else {
                    $pdo->commit();

                    // Log AFTER delete — product_id SET NULL on FK so use NULL
                    try {
                        $pdo2 = getPDO();
                        if ($is_expired && $snap_qty > 0) {
                            $logStmt = $pdo2->prepare(
                                "INSERT INTO Inventory_Log
                            (product_id, user_id_snap, product_name_snap, movement_type, quantity_change,
                             stock_before, stock_after, reference_type, adjustment_reason)
                         VALUES (NULL, :uid, :pname, 'out', :qty, :before, 0, 'expired_deletion', 'Expired Items')"
                            );
                            $logStmt->execute([
                                ':pname'  => $snap_name,
                                ':qty'    => $snap_qty,
                                ':before' => $snap_qty,
                                ':uid'    => $user_id,
                            ]);
                        } elseif (!$is_expired && $snap_qty > 0) {
                            $logStmt = $pdo2->prepare(
                                "INSERT INTO Inventory_Log
                            (product_id, user_id_snap, product_name_snap, movement_type, quantity_change,
                             stock_before, stock_after, reference_type, adjustment_reason)
                         VALUES (NULL, :uid, :pname, 'out', :qty, :before, 0, 'manual', 'Other')"
                            );
                            $logStmt->execute([
                                ':pname'  => $snap_name,
                                ':qty'    => $snap_qty,
                                ':before' => $snap_qty,
                                ':uid'    => $user_id,
                            ]);
                        }
                    } catch (PDOException $logEx) {
                        error_log("Restock delete log error: " . $logEx->getMessage());
                    }

                    $message = 'Product deleted successfully.';
                }
            } else {
                $error = 'Product not found.';
            }
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log("Restock delete error: " . $e->getMessage());
            $error = 'Database error: ' . $e->getMessage();
        }
    }
  }
}

// ── Fetch product list for display ──────────────────────────
$tab    = $_GET['tab']    ?? 'all';
$search = trim($_GET['search'] ?? '');

$per_page     = 10;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($current_page - 1) * $per_page;

try {
    $pdo = getPDO();

    $countSql    = "SELECT COUNT(DISTINCT p.product_id) AS total_rows FROM Product p WHERE p.user_id = :user_id";
    $countParams = [':user_id' => $user_id];

    if ($tab === 'low')     { $countSql .= " AND p.status = 'Low Stock'"; }
    elseif ($tab === 'out') { $countSql .= " AND p.status = 'Out of Stock'"; }
    elseif ($tab === 'expired') { $countSql .= " AND p.expiration_date < CURDATE()"; }
    elseif ($tab === 'near') { $countSql .= " AND p.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"; }

    if (!empty($search)) {
        $countSql .= " AND (p.product_name LIKE :search OR p.sku LIKE :search2)";
        $countParams[':search']  = '%' . $search . '%';
        $countParams[':search2'] = '%' . $search . '%';
    }

    $countStmt2 = $pdo->prepare($countSql);
    $countStmt2->execute($countParams);
    $total_rows   = (int)$countStmt2->fetchColumn();
    $total_pages  = max(1, (int)ceil($total_rows / $per_page));
    $current_page = min($current_page, $total_pages);
    $offset       = ($current_page - 1) * $per_page;

    $sql    = "SELECT p.product_id, p.product_name, p.sku, p.quantity,
                      p.cost_price, p.retail_price, p.expiration_date,
                      p.status, p.low_stock_threshold, p.notes, p.image_url,
                      COALESCE(c.category_name, 'Uncategorized') AS category_name
               FROM Product p
               LEFT JOIN Category c ON c.category_id = p.category_id
               WHERE p.user_id = :user_id";
    $params = [':user_id' => $user_id];

    if ($tab === 'low')      { $sql .= " AND p.status = 'Low Stock'"; }
    elseif ($tab === 'out')  { $sql .= " AND p.status = 'Out of Stock'"; }
    elseif ($tab === 'expired') { $sql .= " AND p.expiration_date < CURDATE()"; }
    elseif ($tab === 'near') { $sql .= " AND p.expiration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"; }

    if (!empty($search)) {
        $sql .= " AND (p.product_name LIKE :search OR p.sku LIKE :search2)";
        $params[':search']  = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY p.product_name ASC LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll();

    $countStmt = $pdo->prepare(
        "SELECT
            COUNT(*)                                                                        AS total,
            SUM(CASE WHEN status = 'Low Stock'    THEN 1 ELSE 0 END)                      AS low,
            SUM(CASE WHEN status = 'Out of Stock' THEN 1 ELSE 0 END)                      AS out_of_stock,
            SUM(CASE WHEN expiration_date < CURDATE()                   THEN 1 ELSE 0 END) AS expired,
            SUM(CASE WHEN expiration_date BETWEEN CURDATE()
                     AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)           THEN 1 ELSE 0 END) AS near_expiry
         FROM Product WHERE user_id = :user_id"
    );
    $countStmt->execute([':user_id' => $user_id]);
    $counts = $countStmt->fetch();

    // Fetch categories for edit dropdown
    $catListStmt = $pdo->prepare(
        "SELECT DISTINCT c.category_name FROM Category c
         LEFT JOIN Product p ON p.category_id = c.category_id AND p.user_id = :user_id
         WHERE c.category_name = 'Uncategorized' OR p.product_id IS NOT NULL
         ORDER BY c.category_name"
    );
    $catListStmt->execute([':user_id' => $user_id]);
    $categories = $catListStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Restock fetch error: " . $e->getMessage());
    $products     = [];
    $counts       = ['total' => 0, 'low' => 0, 'out_of_stock' => 0, 'expired' => 0, 'near_expiry' => 0];
    $total_pages  = 1;
    $current_page = 1;
    $categories   = [];
}

// Build product map for JS (for edit overlay pre-fill)
$productMap = [];
foreach ($products as $p) {
    $productMap[(int)$p['product_id']] = [
        'product_name'    => $p['product_name'],
        'category_name'   => $p['category_name'],
        'sku'             => $p['sku'] ?? '',
        'quantity'        => (int)$p['quantity'],
        'cost_price'      => (float)$p['cost_price'],
        'retail_price'    => (float)$p['retail_price'],
        'expiration_date' => $p['expiration_date'] ?? '',
        'notes'           => $p['notes'] ?? '',
        'image_url'       => $p['image_url'] ?? '',
    ];
}
$productsDataJson = json_encode($productMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

function pageUrl(int $page, string $tab, string $search): string {
    $q = http_build_query(array_filter(['tab' => $tab, 'search' => $search, 'page' => $page]));
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

  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"/>

  <link rel="stylesheet" href="global_sidebar.css"/>
  <link rel="stylesheet" href="global_restock.css"/>
  <link rel="stylesheet" href="sidebar.css"/>
  <link rel="stylesheet" href="restock.css"/>

  <style>
  /* ═══════════════════════════════════════════════════════
     GLOBAL CSS VARS (edit overlay design tokens)
  ═══════════════════════════════════════════════════════ */
  :root {
    --color-cornsilk:    rgba(253, 243, 219, 0.45);
    --color-floralwhite: rgba(252, 248, 238, 0);
    --color-gray-100:    #212934;
    --color-gray-200:    rgba(62, 44, 35, 0.8);
    --color-gray-300:    rgba(43, 43, 43, 0.8);
    --color-gray-400:    rgba(0, 0, 0, 0.2);
    --color-khaki:       rgba(235, 214, 101, 0.66);
    --text-brown:        #3e2c23;
    --gap-4: 4px;  --gap-8: 8px; --gap-10: 10px; --gap-15: 15px;
    --padding-0: 0px; --padding-01: 0; --padding-2: 2px;
    --padding-10: 10px; --padding-12: 12px; --padding-20: 20px;
    --br-2: 2px; --br-10: 10px;
    --font-inter: Inter;
    --fs-16: 16px; --fs-18: 18px;
    --border-1: 1px solid var(--color-gray-400);
    --height-19: 19px; --height-20: 20px; --height-41: 41px; --height-67: 67px;
  }

  /* ═══════════════════════════════════════════════════════
     EDIT OVERLAY BACKDROP
  ═══════════════════════════════════════════════════════ */
  #edit-product-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1200;
    background: linear-gradient(180deg, rgba(235,233,225,0.55), rgba(169,174,181,0.55));
    backdrop-filter: blur(51px);
    -webkit-backdrop-filter: blur(51px);
    box-shadow: 0 11.4px 22.3px rgba(53,106,185,0.09) inset,
                0 -2px 1px rgba(151,193,255,0.4) inset;
    align-items: center;
    justify-content: center;
    padding: 20px;
    overflow-y: auto;
  }
  #edit-product-overlay.is-open { display: flex; }

  /* ═══════════════════════════════════════════════════════
     EDIT OVERLAY — modal card  (matches the design image)
  ═══════════════════════════════════════════════════════ */
  .edit-overlay-card {
    box-sizing: border-box;
    width: 100%;
    max-width: 753px;
    display: flex;
    flex-direction: column;
    gap: 0;
    box-shadow: 36px 30px 13px transparent,
                23px 19px 12px rgba(62,44,35,0.01),
                13px 11px 10px rgba(62,44,35,0.05),
                6px 5px 8px rgba(62,44,35,0.09),
                1px 1px 4px rgba(62,44,35,0.1);
    backdrop-filter: blur(20.6px);
    -webkit-backdrop-filter: blur(20.6px);
    border-radius: 15px;
    background: linear-gradient(146.01deg,
        rgba(253,253,253,0.58),
        rgba(254,246,227,0.49) 49.52%,
        rgba(255,244,216,0.6)),
      linear-gradient(rgba(252,248,238,0.2), rgba(252,248,238,0.2));
    border: 2px solid var(--text-brown);
    overflow: hidden;
    padding: 17px 15px;
    animation: editModalIn 0.2s ease;
  }
  @keyframes editModalIn {
    from { opacity: 0; transform: translateY(-12px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0)     scale(1); }
  }

  /* Header row */
  .eo-header {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--gap-10);
    margin-bottom: 14px;
  }
  .eo-title {
    margin: 0;
    flex: 1;
    font-size: 32px;
    letter-spacing: -0.04em;
    font-weight: 800;
    font-family: var(--font-inter);
    color: var(--text-brown);
    min-width: 179px;
  }
  .eo-close-btn {
    cursor: pointer;
    border: 0;
    padding: 0;
    background: transparent;
    height: 34px;
    width: 34px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: var(--text-brown);
    transition: background 0.15s;
  }
  .eo-close-btn:hover { background: rgba(62,44,35,0.1); }

  /* Flash banner */
  .eo-flash {
    display: none;
    padding: 8px 14px;
    border-radius: var(--br-10);
    font-family: var(--font-inter);
    font-size: 14px;
    margin-bottom: 10px;
  }
  .eo-flash.show { display: block; }
  .eo-flash.flash-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
  .eo-flash.flash-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

  /* Inner beige form section */
  .eo-form-card {
    box-shadow: 31px 57px 18px transparent,
                20px 36px 17px rgba(0,0,0,0.01),
                11px 20px 14px rgba(0,0,0,0.03),
                5px 9px 10px rgba(0,0,0,0.04),
                1px 2px 6px rgba(0,0,0,0.05);
    border-radius: var(--br-10);
    overflow: hidden;
    padding: var(--padding-12) 14px var(--padding-10);
    display: flex;
    flex-direction: column;
    gap: var(--gap-8);
    background: rgba(252,248,235,0.92);
    font-family: var(--font-inter);
    font-size: var(--fs-18);
    color: var(--color-gray-100);
  }

  /* Top row: image + product name/category */
  .eo-top-row {
    display: flex;
    align-items: flex-start;
    gap: var(--gap-10);
    flex-wrap: wrap;
  }

  /* Image upload box */
  .eo-img-box {
    height: 201px;
    width: 232px;
    flex-shrink: 0;
    border-radius: var(--br-10);
    border: 2px dashed rgba(62,44,35,0.3);
    background: rgba(253,243,219,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    cursor: pointer;
    overflow: hidden;
  }
  .eo-img-box input[type="file"] {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    width: 100%;
    height: 100%;
  }
  .eo-img-box img.eo-img-preview {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: var(--br-10);
    display: none;
  }
  .eo-img-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    pointer-events: none;
    color: var(--text-brown);
    opacity: 0.45;
  }
  .eo-img-placeholder i    { font-size: 52px; }
  .eo-img-placeholder span { font-size: 12px; font-family: var(--font-inter); }

  /* Right fields */
  .eo-right-fields {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: var(--gap-8);
    min-width: 220px;
    justify-content: center;
    padding: 30px 0 28px;
  }

  /* Field group */
  .eo-field-group {
    display: flex;
    flex-direction: column;
    gap: var(--gap-4);
    align-self: stretch;
  }
  .eo-label {
    font-size: var(--fs-16);
    letter-spacing: -0.04em;
    color: var(--text-brown);
    font-weight: 500;
  }
  .eo-input-field {
    height: var(--height-41);
    border-radius: var(--br-10);
    background-color: var(--color-cornsilk);
    border: var(--border-1);
    box-sizing: border-box;
    display: flex;
    align-items: center;
    padding: var(--padding-10) var(--padding-12) var(--padding-10) var(--padding-20);
    gap: 4px;
  }
  .eo-input-field input {
    width: 100%;
    border: 0;
    outline: 0;
    font-family: var(--font-inter);
    font-size: var(--fs-16);
    background: transparent;
    letter-spacing: -0.04em;
    color: var(--color-gray-300);
    padding: 0;
  }
  .eo-peso { font-size: var(--fs-16); color: var(--text-brown); flex-shrink: 0; }

  /* SKU (read-only) */
  .eo-sku-field {
    height: var(--height-41);
    border-radius: var(--br-10);
    background-color: var(--color-cornsilk);
    border: var(--border-1);
    box-sizing: border-box;
    display: flex;
    align-items: center;
    padding: var(--padding-10) var(--padding-12) var(--padding-10) var(--padding-20);
    font-family: var(--font-inter);
    font-size: var(--fs-16);
    color: var(--color-gray-200);
    letter-spacing: -0.04em;
  }

  /* Expiry date row */
  .eo-expiry-wrap {
    height: 42px;
    border-radius: var(--br-10);
    background-color: var(--color-cornsilk);
    border: var(--border-1);
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--padding-10) var(--padding-12) var(--padding-10) var(--padding-20);
    font-size: 13px;
    color: var(--color-gray-200);
  }
  .eo-date-picker {
    display: flex;
    align-items: center;
    gap: var(--gap-10);
  }
  .eo-date-picker i { font-size: 16px; color: var(--text-brown); }
  .eo-date-picker input[type="date"] {
    border: 0; outline: 0; background: transparent;
    font-family: var(--font-inter); font-size: 13px;
    color: var(--color-gray-200); cursor: pointer;
  }
  .eo-none-part {
    display: flex; align-items: center; gap: 6px;
    font-size: 13px; color: var(--text-brown);
  }

  /* Inline row for multiple fields */
  .eo-fields-row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--gap-10);
    align-items: flex-start;
  }
  .eo-fields-row .eo-field-group { flex: 1; min-width: 140px; }

  /* Notes textarea */
  .eo-textarea-field {
    height: 72px;
    border-radius: var(--br-10);
    background-color: var(--color-cornsilk);
    border: var(--border-1);
    box-sizing: border-box;
    display: flex;
    align-items: flex-start;
    padding: 9px 18px;
    overflow: hidden;
  }
  .eo-textarea-field textarea {
    width: 100%; border: 0; outline: 0; background: transparent;
    font-family: var(--font-inter); font-size: var(--fs-16);
    color: var(--color-gray-300); resize: none; height: 100%;
    letter-spacing: -0.04em;
  }

  /* Footer buttons */
  .eo-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding: 2px 0 0;
    gap: var(--gap-10);
  }
  .eo-btn-cancel {
    border: none; background: transparent; cursor: pointer;
    border-radius: 231px;
    padding: var(--padding-10) var(--padding-20);
    font-family: var(--font-inter); font-size: var(--fs-16);
    font-weight: 500; letter-spacing: -0.04em; color: var(--text-brown);
  }
  .eo-btn-cancel:hover { text-decoration: underline; }
  .eo-btn-update {
    cursor: pointer;
    border: 2px solid var(--text-brown);
    padding: var(--padding-10) var(--padding-20);
    background-color: var(--color-khaki);
    box-shadow: -26px -17px 9px transparent inset,
                -17px -11px 8px rgba(44,44,44,0.01) inset,
                -9px -6px 7px rgba(44,44,44,0.05) inset,
                -4px -3px 5px rgba(44,44,44,0.09) inset,
                -1px -1px 3px rgba(44,44,44,0.1) inset;
    border-radius: 231px;
    display: flex; align-items: center; justify-content: center;
    font-family: var(--font-inter); font-size: var(--fs-16);
    font-weight: 500; letter-spacing: -0.04em; color: var(--text-brown);
  }
  .eo-btn-update:hover { background-color: rgba(235,214,101,0.9); }

  @media screen and (max-width: 800px) {
    .edit-overlay-card { max-width: 100%; width: calc(100% - 40px); }
    .eo-title { font-size: 26px; }
  }
  @media screen and (max-width: 450px) {
    .eo-title { font-size: 19px; }
    .eo-fields-row { flex-wrap: wrap; }
  }
  #del-modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.45);
  display: flex; align-items: center; justify-content: center;
  z-index: 9999;
  }
  #del-modal-box {
    background: #fff;
    border-radius: 12px;
    padding: 32px 28px 24px;
    min-width: 300px; max-width: 420px;
    text-align: center;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18);
    font-family: inherit;
  }
  #del-modal-icon  { font-size: 2.4rem; margin-bottom: 8px; }
  #del-modal-title { font-size: 1.15rem; font-weight: 700; margin-bottom: 8px; color: #1a1a2e; }
  #del-modal-body  { font-size: 0.95rem; color: #444; line-height: 1.6; }
  #img-preview-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.65);
  align-items: center;
  justify-content: center;
  z-index: 10000;
}
#img-preview-box {
  position: relative;
  border-radius: 15px;
  padding: 20px 20px 16px;
  width: 360px;
  max-width: 90vw;
  text-align: center;
  box-shadow: 0 12px 40px rgba(0,0,0,0.3);
  background: linear-gradient(
    146.01deg,
    rgba(253, 253, 253, 0.98),
    rgba(254, 246, 227, 0.98) 49.52%,
    rgba(255, 244, 216, 0.98)
  );
  border: 1px solid #3e2c23;
  animation: popIn 0.18s ease;
}
#img-preview-src {
  width: 320px;
  height: 320px;
  max-width: 100%;
  object-fit: cover;
  border-radius: 10px;
  border: 1px solid rgba(62, 44, 35, 0.2);
  display: block;
  margin: 0 auto;
}
#img-preview-name {
  margin-top: 10px;
  font-size: 14px;
  font-weight: 600;
  color: #3e2c23;
  font-family: Inter, sans-serif;
}
#img-preview-close {
  position: absolute;
  top: 10px;
  right: 10px;
  background: rgba(112, 94, 87, 0.09);
  border: 1px solid rgba(62, 44, 35, 0.2);
  border-radius: 50%;
  width: 30px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 14px;
  color: #3e2c23;
  transition: background 0.15s;
}
#img-preview-close:hover { background: rgba(62, 44, 35, 0.14); }

@keyframes popIn {
  from { opacity: 0; transform: scale(0.92); }
  to   { opacity: 1; transform: scale(1); }
}
  </style>
</head>
<body>

<div class="page-wrapper">

  <?php $activePage = 'restock'; include 'sidebar.php'; ?>

  <div class="main-body">

    <!-- ── Overview Section ── -->
    <div class="overview-section">
      <h1 class="page-title">RESTOCK</h1>

      <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="overview-cards">
        <div class="stat-card red-bg">
          <div class="stat-icon icon-red">
            <img src="pics_icons/out-of-stock (1).png" width="33" alt="Out of Stock"/>
          </div>
          <div class="stat-info">
            <span class="stat-label">Out of Stocks</span>
            <span class="stat-value red"><?= (int)($counts['out_of_stock'] ?? 0) ?> Products</span>
          </div>
        </div>

        <div class="stat-card orange-bg">
          <div class="stat-icon icon-orange">
            <img src="pics_icons/expired.png" width="33" alt="Expired"/>
          </div>
          <div class="stat-info">
            <span class="stat-label">Expired Products</span>
            <span class="stat-value orange"><?= (int)($counts['expired'] ?? 0) ?> Products</span>
          </div>
        </div>

        <div class="stat-card cream-bg">
          <div class="stat-icon icon-gray">
            <img src="pics_icons/arrow-trend-down.png" width="33" alt="Low Stock"/>
            
          </div>
          <div class="stat-info">
            <span class="stat-label">Low on Stock</span>
            <span class="stat-value gray"><?= (int)($counts['low'] ?? 0) ?> Product<?= (int)($counts['low'] ?? 0) !== 1 ? 's' : '' ?></span>
          </div>
        </div>

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
    </div>

    <!-- ── Table Section ── -->
    <div class="table-section">

      <div class="table-toolbar">
        <div class="toolbar-left">

          <form method="get" style="display:contents;">
          <form method="get" style="display:contents;">
            <input type="hidden" name="tab"  value="<?= htmlspecialchars($tab) ?>"/>
            <input type="hidden" name="page" value="1"/>
            <div class="searchbar">
              <i class="bi bi-search"></i>
              <input type="text" name="search" placeholder="Search for product / sku"
                     value="<?= htmlspecialchars($search) ?>"/>
            </div>
          </form>

          <div class="filter-tabs">
            <a href="restock.php?tab=all<?= !empty($search) ? '&search='.urlencode($search) : '' ?>&page=1"
               class="tab-btn <?= $tab === 'all'     ? 'active' : '' ?>">All products</a>
            <a href="restock.php?tab=out<?= !empty($search) ? '&search='.urlencode($search) : '' ?>&page=1"
               class="tab-btn <?= $tab === 'out'     ? 'active' : '' ?>">Out of Stock</a>
            <a href="restock.php?tab=low<?= !empty($search) ? '&search='.urlencode($search) : '' ?>&page=1"
               class="tab-btn <?= $tab === 'low'     ? 'active' : '' ?>">Low Stock</a>
            <a href="restock.php?tab=expired<?= !empty($search) ? '&search='.urlencode($search) : '' ?>&page=1"
               class="tab-btn <?= $tab === 'expired' ? 'active' : '' ?>">Expired</a>
            <a href="restock.php?tab=near<?= !empty($search) ? '&search='.urlencode($search) : '' ?>&page=1"
               class="tab-btn <?= $tab === 'near'    ? 'active' : '' ?>">Near Expiry</a>

            <button type="button" class="btn-import" onclick="document.getElementById('csv-upload').click()">
              <i class="bi bi-upload"></i> Import CSV
            </button>
            <input type="file" id="csv-upload" accept=".csv" style="display:none;" onchange="handleCsvImport(this)"/>
          </div>

        </div>
      </div>

      <!-- Batch restock form — wraps the entire table so Complete can collect all qty inputs -->
      <form method="post" id="batch-restock-form">
        <input type="hidden" name="action" value="batch_restock"/>

        <div class="table-scroll-wrapper">
          <div class="table-wrapper">
            <table class="restock-table">
              <thead>
                <tr>
                  <th style="width:72px; text-align:center; white-space:nowrap; padding-left: 16px;">View Image</th>
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
                    <td colspan="10">No products found.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($products as $p):
                    $status = htmlspecialchars($p['status'] ?? 'In Stock');
                    $badgeClass = match($p['status'] ?? '') {
                        'Out of Stock' => 'badge-out-of-stock',
                        'Low Stock'    => 'badge-low-stock',
                        'Near Expiry'  => 'badge-near-expiry',
                        'Expired'      => 'badge-expired',
                        default        => 'badge-in-stock',
                    };
                    $statusLabel  = $p['status'] ?? 'In Stock';
                    if (empty($statusLabel)) $statusLabel = 'In Stock';

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

                    $displayPrice = number_format((float)($p['retail_price'] ?? 0), 0);
                    $rowQtyId     = 'qty_' . (int)$p['product_id'];
                    $batchName    = 'batch[' . (int)$p['product_id'] . ']';
                  ?>
                  <tr>
                    <td style="width:72px; text-align:center; vertical-align:middle; padding:6px 10px;">
                      <?php if (!empty($p['image_url'])): ?>
                        <img
                          src="<?= htmlspecialchars($p['image_url']) ?>"
                          onclick="openImgPreview('<?= htmlspecialchars($p['image_url']) ?>', '<?= htmlspecialchars(addslashes($p['product_name'])) ?>')"
                          alt="<?= htmlspecialchars($p['product_name']) ?>"
                          style="width:60px;height:60px;object-fit:cover;border-radius:6px;border:1px solid rgba(62,44,35,0.2);cursor:pointer;display:block;margin:0 auto;flex-shrink:0;"
                        />
                      <?php else: ?>
                        <div style="width:60px;height:60px;border-radius:6px;border:1px dashed rgba(62,44,35,0.2);display:flex;align-items:center;justify-content:center;color:rgba(62,44,35,0.3);font-size:24px;flex-shrink:0;cursor:default;margin:0 auto;">
                          <i class="bi bi-image"></i>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td class="td-product-name"><?= htmlspecialchars($p['product_name']) ?></td>
                    <td class="td-sku"><?= htmlspecialchars($p['sku']) ?></td>
                    <td class="td-category"><?= htmlspecialchars($p['category_name']) ?></td>
                    <td class="td-stock"><?= (int)$p['quantity'] ?></td>

                    <!-- Add Qty Stepper — default 0, only submitted rows (>0) get restocked -->
                    <td class="td-qty">
                      <div class="qty-stepper">
                        <button type="button" onclick="changeQty('<?= $rowQtyId ?>', 1)" title="Increase">
                          <i class="bi bi-plus-circle" style="font-size:16px;"></i>
                        </button>
                        <input
                          class="qty-input"
                          type="number"
                          id="<?= $rowQtyId ?>"
                          name="<?= $batchName ?>"
                          min="0"
                          value="0"
                        />
                        <button type="button" onclick="changeQty('<?= $rowQtyId ?>', -1)" title="Decrease">
                          <i class="bi bi-dash-circle" style="font-size:16px;"></i>
                        </button>
                      </div>
                    </td>

                    <td class="td-expiry">
                      <div class="expiry-wrap">
                        <span><?= $expiryDisplay ?></span>
                        
                      </div>
                    </td>

                    <td class="td-price">₱ <?= $displayPrice ?></td>

                    <td class="td-status">
                      <span class="status-badge <?= $badgeClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                    </td>

                    <td class="td-actions">
                      <div class="actions-wrap">
                        <!-- Eye icon → opens Edit overlay (no page reload) -->
                        <button
                          type="button"
                          class="btn-action"
                          title="Edit this product"
                          onclick="openEditOverlay(<?= (int)$p['product_id'] ?>)"
                        >
                          <i class="bi bi-eye"></i>
                        </button>

                        <!-- Delete -->
                        
                        <button
                          type="button"
                          class="btn-action"
                          title="Delete"
                          onclick="deleteProduct(<?= (int)$p['product_id'] ?>)"
                        >
                          <i class="bi bi-trash3"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Pagination Row -->
        <div class="pagination-row">
          <?php if ($current_page > 1): ?>
            <a href="<?= htmlspecialchars(pageUrl($current_page - 1, $tab, $search)) ?>" class="btn-page">
              <i class="bi bi-arrow-left"></i> Prev
            </a>
          <?php else: ?>
            <button class="btn-page" disabled style="opacity:0.4;cursor:default;">
              <i class="bi bi-arrow-left"></i> Prev
            </button>
          <?php endif; ?>

          <?php if ($current_page < $total_pages): ?>
            <a href="<?= htmlspecialchars(pageUrl($current_page + 1, $tab, $search)) ?>" class="btn-page">
              Next <i class="bi bi-arrow-right"></i>
            </a>
          <?php else: ?>
            <button class="btn-page" disabled style="opacity:0.4;cursor:default;">
              Next <i class="bi bi-arrow-right"></i>
            </button>
          <?php endif; ?>
        </div>

        <!-- Bottom Actions -->
        <div class="bottom-actions">
          <button type="button" class="btn-cancel" onclick="window.location.href='restock.php'">Cancel</button>
          <button type="submit" class="btn-complete" onclick="return handleComplete(event)">Complete</button>
        </div>

      </form><!-- /batch-restock-form -->

    </div><!-- /table-section -->

  </div><!-- /main-body -->
</div><!-- /page-wrapper -->


<!-- ════════════════════════════════════════════════════════════
     EDIT PRODUCT OVERLAY
════════════════════════════════════════════════════════════ -->
<div id="edit-product-overlay" role="dialog" aria-modal="true" aria-label="Edit Product">
  <div class="edit-overlay-card">

    <!-- Header -->
    <div class="eo-header">
      <h1 class="eo-title">EDIT PRODUCT</h1>
      <button class="eo-close-btn" type="button" onclick="closeEditOverlay()" title="Close">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>

    <!-- Flash -->
    <div class="eo-flash" id="eo-flash"></div>

    <!-- Beige form card -->
    <section class="eo-form-card">
      <form method="post" enctype="multipart/form-data" id="edit-product-form">
        <input type="hidden" name="action"     value="update"/>
        <input type="hidden" name="product_id" id="eo-product-id" value=""/>

        <!-- Top row: image + name/category -->
        <div class="eo-top-row">
          <div class="eo-img-box" id="eo-img-box">
            <div class="eo-img-placeholder" id="eo-img-placeholder">
              <i class="bi bi-image-fill"></i>
              <span>Click to upload</span>
            </div>
            <img class="eo-img-preview" id="eo-img-preview" src="" alt="Product Image"/>
            <input type="file" name="product_image" id="eo-img-input" accept="image/*"
                   onchange="eoPreviewImage(this)"/>
          </div>

          <div class="eo-right-fields">
            <div class="eo-field-group">
              <div class="eo-label">Product Name</div>
              <div class="eo-input-field">
                <input type="text" id="eo-name" name="product_name"
                       placeholder="Enter Here" required autocomplete="off"/>
              </div>
            </div>
            <div class="eo-field-group">
              <div class="eo-label">Category</div>
              <div class="eo-input-field">
                <input type="text" id="eo-category" name="category"
                       placeholder="Enter Here" list="eo-cat-list" autocomplete="off"/>
              </div>
            </div>
          </div>
        </div>

        <!-- SKU + Expiry Date -->
        <div class="eo-fields-row" style="margin-top:8px;">
          <div class="eo-field-group">
            <div class="eo-label">SKU</div>
            <div class="eo-sku-field" id="eo-sku-display">—</div>
          </div>
          <div class="eo-field-group">
            <div class="eo-label">Expiry Date</div>
            <div class="eo-expiry-wrap">
              <div class="eo-date-picker">
                
                <input type="date" id="eo-expiry" name="expiry_date"/>
              </div>
              <div class="eo-none-part">
                <label for="eo-no-expiry">None</label>
                <input type="checkbox" id="eo-no-expiry" name="no_expiry"
                       onchange="eoToggleExpiry(this)"/>
              </div>
            </div>
          </div>
        </div>

        <!-- Stock Qty + Cost + Selling Price -->
        <div class="eo-fields-row" style="margin-top:8px;">
          <div class="eo-field-group">
            <div class="eo-label">Stock Quantity</div>
            <div class="eo-input-field">
              <input type="number" id="eo-qty" name="stock_quantity" min="1" required/>
                      
            </div>
          </div>
          <div class="eo-field-group">
            <div class="eo-label">Cost</div>
            <div class="eo-input-field">
              <span class="eo-peso">₱</span>
              <input type="number" id="eo-cost" name="cost"
                      step="0.01" min="1" required/>
            </div>
          </div>
          <div class="eo-field-group">
            <div class="eo-label">Selling Price</div>
            <div class="eo-input-field">
              <span class="eo-peso">₱</span>
              <input type="number" id="eo-retail" name="selling_price"
                     step="0.01" min="1" required/>
            </div>
          </div>
        </div>

        <!-- Additional Notes -->
        <div class="eo-fields-row" style="margin-top:8px;align-items:flex-start;">
          <div class="eo-field-group" style="flex:1;">
            <div class="eo-label">Additional Notes</div>
            <div class="eo-textarea-field">
              <textarea id="eo-notes" name="notes" placeholder="Enter Here"></textarea>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="eo-footer" style="margin-top:8px;">
          <button type="button" class="eo-btn-cancel" onclick="closeEditOverlay()">Cancel</button>
          <button type="submit" class="eo-btn-update">Update</button>
        </div>

      </form>
    </section>

  </div>
</div>

<!-- Category datalist for edit overlay -->
<datalist id="eo-cat-list">
  <?php foreach ($categories as $cat): ?>
    <option value="<?= htmlspecialchars($cat['category_name']) ?>">
  <?php endforeach; ?>
  <option value="Uncategorized">
</datalist>


<script>
'use strict';

/* ── Product data from PHP ───────────────────────────────── */
var productsData = <?= $productsDataJson ?>;

/* ── Edit Overlay ────────────────────────────────────────── */
var editOverlay = document.getElementById('edit-product-overlay');

function openEditOverlay(productId) {
  var p = productsData[productId];
  if (!p) { console.error('Product not found:', productId); return; }

  document.getElementById('eo-product-id').value = productId;
  document.getElementById('eo-name').value        = p.product_name    || '';
  document.getElementById('eo-category').value    = p.category_name   || '';
  document.getElementById('eo-sku-display').textContent = p.sku || '—';
  document.getElementById('eo-qty').value         = p.quantity;
  document.getElementById('eo-cost').value        = parseFloat(p.cost_price   || 0).toFixed(2);
  document.getElementById('eo-retail').value      = parseFloat(p.retail_price || 0).toFixed(2);
  document.getElementById('eo-notes').value       = p.notes || '';

  var expiryInput = document.getElementById('eo-expiry');
  var noExpiryChk = document.getElementById('eo-no-expiry');
  if (p.expiration_date) {
    expiryInput.value    = p.expiration_date;
    expiryInput.disabled = false;
    noExpiryChk.checked  = false;
  } else {
    expiryInput.value    = '';
    expiryInput.disabled = true;
    noExpiryChk.checked  = true;
  }

  var preview     = document.getElementById('eo-img-preview');
  var placeholder = document.getElementById('eo-img-placeholder');
  if (p.image_url) {
    preview.src           = p.image_url;
    preview.style.display = 'block';
    placeholder.style.display = 'none';
  } else {
    preview.src           = '';
    preview.style.display = 'none';
    placeholder.style.display = 'flex';
  }
  document.getElementById('eo-img-input').value = '';

  // Clear flash
  var flash = document.getElementById('eo-flash');
  flash.className = 'eo-flash';
  flash.textContent = '';

  editOverlay.classList.add('is-open');
  document.body.style.overflow = 'hidden';
}

function closeEditOverlay() {
  editOverlay.classList.remove('is-open');
  document.body.style.overflow = '';
}

editOverlay.addEventListener('click', function(e) {
  if (e.target === editOverlay) closeEditOverlay();
});

function eoToggleExpiry(checkbox) {
  var dateInput = document.getElementById('eo-expiry');
  dateInput.disabled = checkbox.checked;
  if (checkbox.checked) dateInput.value = '';
}

function eoPreviewImage(input) {
  var preview     = document.getElementById('eo-img-preview');
  var placeholder = document.getElementById('eo-img-placeholder');
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function(e) {
      preview.src           = e.target.result;
      preview.style.display = 'block';
      placeholder.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
  }
}

/* ── Qty Stepper ─────────────────────────────────────────── */
function changeQty(inputId, delta) {
  var input = document.getElementById(inputId);
  if (!input) return;
  var val = parseInt(input.value, 10);
  if (isNaN(val)) val = 0;
  val += delta;
  if (val < 0) val = 0;
  input.value = val;
}
/* ─────────────────────────────────────────────────────────────
   Expiry Date required: must have a date OR "None" checked
───────────────────────────────────────────────────────────── */
function validateExpiryRequired(formId, dateInputId, checkboxId) {
  var form     = document.getElementById(formId);
  if (!form) return;

  form.addEventListener('submit', function(e) {
    var dateInput = document.getElementById(dateInputId);
    var checkbox  = document.getElementById(checkboxId);

    var hasDate    = dateInput && dateInput.value.trim() !== '';
    var noneChecked = checkbox && checkbox.checked;

    if (!hasDate && !noneChecked) {
      e.preventDefault();
      dateInput.setCustomValidity('Please enter an expiry date or check "None".');
      dateInput.reportValidity();
    } else {
      if (dateInput) dateInput.setCustomValidity('');
    }
  });

  // Clear error once user picks a date
  var dateInput = document.getElementById(dateInputId);
  if (dateInput) {
    dateInput.addEventListener('change', function() {
      this.setCustomValidity('');
    });
  }
}

validateExpiryRequired('edit-product-form', 'eo-expiry', 'eo-no-expiry');

/* ── Complete (batch restock) ────────────────────────────── */
function handleComplete(e) {
  var inputs  = document.querySelectorAll('#batch-restock-form input[name^="batch["]');
  var hasAny  = false;
  inputs.forEach(function(inp) {
    if (parseInt(inp.value, 10) > 0) hasAny = true;
  });
  if (!hasAny) {
    e.preventDefault();
    alert('Please enter a quantity (greater than 0) for at least one product before completing the restock.');
    return false;
  }
  // Remove zero-qty inputs so they don't pollute POST (optional, PHP ignores them anyway)
  inputs.forEach(function(inp) {
    if (parseInt(inp.value, 10) <= 0) inp.disabled = true;
  });
  return true;
}

/* ── CSV Import ──────────────────────────────────────────── */
function handleCsvImport(fileInput) {
  if (!fileInput.files || !fileInput.files[0]) return;
  var file = fileInput.files[0];
  if (!file.name.endsWith('.csv')) {
    alert('Please select a valid CSV file.');
    fileInput.value = '';
    return;
  }
  alert('CSV file selected: ' + file.name + '\n(Connect this to your import endpoint.)');
  fileInput.value = '';
}

/* ── Searchbar: submit on Enter ──────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
  var searchInput = document.querySelector('.searchbar input[type="text"]');
  if (searchInput) {
    searchInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); this.closest('form').submit(); }
    });
  }
});

/* ── ESC closes edit overlay ─────────────────────────────── */
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && editOverlay.classList.contains('is-open')) closeEditOverlay();
});
function deleteProduct(productId) {
  document.getElementById('del-modal-overlay').style.display = 'flex';
  document.getElementById('del-modal-confirm-btn').onclick = function() {
    document.getElementById('del-modal-overlay').style.display = 'none';
    document.getElementById('delete-product-id').value = productId;
    document.getElementById('delete-product-form').submit();
  };
}

function cancelDelModal() {
  document.getElementById('del-modal-overlay').style.display = 'none';
}

function openImgPreview(src, name) {
  var overlay = document.getElementById('img-preview-overlay');
  document.getElementById('img-preview-src').src          = src;
  document.getElementById('img-preview-name').textContent = name;
  overlay.style.display        = 'flex';
  overlay.style.position       = 'fixed';
  overlay.style.inset          = '0';
  overlay.style.background     = 'rgba(0,0,0,0.65)';
  overlay.style.alignItems     = 'center';
  overlay.style.justifyContent = 'center';
  overlay.style.zIndex         = '10000';
  document.body.style.overflow = 'hidden';
}

function closeImgPreview() {
  document.getElementById('img-preview-overlay').style.display = 'none';
  document.body.style.overflow = '';
}

</script>

<div id="del-modal-overlay" style="display:none;" onclick="cancelDelModal()">
  <div id="del-modal-box" onclick="event.stopPropagation()">
    <div id="del-modal-icon">🗑️</div>
    <div id="del-modal-title">Delete Product</div>
    <div id="del-modal-body">Sure ka na bang idedelete? Hindi na to mababalik.</div>
    <div style="display:flex; gap:10px; justify-content:center; margin-top:20px;">
      <button onclick="cancelDelModal()" style="background:#6b7280; color:#fff; border:none; border-radius:8px; padding:10px 32px; font-size:1rem; cursor:pointer;">Cancel</button>
      <button id="del-modal-confirm-btn" style="background:#dc2626; color:#fff; border:none; border-radius:8px; padding:10px 32px; font-size:1rem; cursor:pointer;">Delete</button>
    </div>
  </div>
</div>

<div id="img-preview-overlay" style="display:none;" onclick="closeImgPreview()">
  <div id="img-preview-box" onclick="event.stopPropagation()">
    <button id="img-preview-close" onclick="closeImgPreview()"><i class="bi bi-x-lg"></i></button>
    <img id="img-preview-src" src="" alt=""/>
    <div id="img-preview-name"></div>
  </div>
</div>

<!-- Standalone delete form — must be inside body, outside batch form -->
<form method="post" id="delete-product-form" style="display:none;">
  <input type="hidden" name="action"     value="delete"/>
  <input type="hidden" name="product_id" id="delete-product-id" value=""/>
</form>

</body>
</html>