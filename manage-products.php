<?php
// ============================================================
//  manage-products.php
//  CRUD: Read, Delete products.
//  Add Product opens as an INLINE OVERLAY.
//  Edit Product opens as an INLINE OVERLAY (edit overlay).
//  Requirements: Prepared statements, try-catch, session guard
// ============================================================

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/debug_log.txt');
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once './utils/lhdb.php';

define('DEBUG_MODE', false);

$user_id        = (int) $_SESSION['user_id'];
$message        = '';
$error          = '';
$add_flash_msg  = '';
$add_flash_type = '';
$edit_flash_msg  = '';
$edit_flash_type = '';

$edit_product_id = (int) ($_POST['edit_product_id'] ?? $_GET['edit_product_id'] ?? 0);

// ── Handle POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $pdo = getPDO();

        // ── DELETE ──────────────────────────────────────────
        if ($action === 'delete') {
    $product_id = (int) ($_POST['product_id'] ?? 0);
    if ($product_id) {
        $snapStmt = $pdo->prepare(
            "SELECT quantity, product_name, expiration_date, status, retail_price
             FROM Product WHERE product_id = :id AND user_id = :user_id"
        );
        $snapStmt->execute([':id' => $product_id, ':user_id' => $user_id]);
        $snap = $snapStmt->fetch();

        if ($snap) {
            $is_expired = (!empty($snap['expiration_date']) && $snap['expiration_date'] < date('Y-m-d'))
                           || $snap['status'] === 'Expired';
            $snap_qty  = (int)$snap['quantity'];
            $snap_name = $snap['product_name'];

            $pdo->beginTransaction();
            $del = $pdo->prepare(
                "DELETE FROM Product WHERE product_id = :id AND user_id = :user_id"
            );
            $del->execute([':id' => $product_id, ':user_id' => $user_id]);
            $pdo->commit();

            // Log AFTER delete — product_id FK is SET NULL on delete so this is safe
            try {
                $pdo2 = getPDO();
                // Get retail price for the log
                        $priceSnap = $pdo2->prepare(
                            "SELECT retail_price FROM Product WHERE product_id = :id LIMIT 1"
                        );
                        // Product is deleted so fetch from snap or use 0
                        $snap_price = (float)($snap['retail_price'] ?? 0);

                        if ($is_expired && $snap_qty > 0) {
                            $logStmt = $pdo2->prepare(
                                "INSERT INTO Inventory_Log
                                    (product_id, user_id_snap, product_name_snap, movement_type,
                                     quantity_change, selling_price, stock_before, stock_after,
                                     reference_type, adjustment_reason)
                                 VALUES (NULL, :uid, :pname, 'out',
                                         :qty, :price, :before, 0,
                                         'expired_deletion', 'Expired Items')"
                            );
                            $logStmt->execute([
                                ':uid'    => $user_id,
                                ':pname'  => $snap_name,
                                ':qty'    => $snap_qty,
                                ':price'  => $snap_price,
                                ':before' => $snap_qty,
                            ]);
                        } elseif (!$is_expired && $snap_qty > 0) {
                            $logStmt = $pdo2->prepare(
                                "INSERT INTO Inventory_Log
                                    (product_id, user_id_snap, product_name_snap, movement_type,
                                     quantity_change, selling_price, stock_before, stock_after,
                                     reference_type, adjustment_reason)
                                 VALUES (NULL, :uid, :pname, 'out',
                                         :qty, :price, :before, 0,
                                         'manual', 'Other')"
                            );
                            $logStmt->execute([
                                ':uid'    => $user_id,
                                ':pname'  => $snap_name,
                                ':qty'    => $snap_qty,
                                ':price'  => $snap_price,
                                ':before' => $snap_qty,
                            ]);
                        }
            } catch (PDOException $logEx) {
                error_log("Delete log error: " . $logEx->getMessage());
            }

            $message = 'Product deleted successfully.';
        }
    }

        // ── ADD PRODUCT ─────────────────────────────────────
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

                $target_cat = !empty($category_name) ? $category_name : 'Uncategorized';

                $insCAT = $pdo->prepare("INSERT IGNORE INTO Category (category_name) VALUES (:name)");
                $insCAT->execute([':name' => $target_cat]);

                $catStmt = $pdo->prepare("SELECT category_id FROM Category WHERE category_name = :name LIMIT 1");
                $catStmt->execute([':name' => $target_cat]);
                $cat = $catStmt->fetch();

                if (!$cat || (int) $cat['category_id'] <= 0) {
                    $pdo->rollBack();
                    $add_flash_msg  = 'Could not resolve product category. Please try again.';
                    $add_flash_type = 'error';
                } else {
                    $cat_id       = (int) $cat['category_id'];
                    $final_expiry = ($no_expiry || empty($expiry_date)) ? null : $expiry_date;

                    $image_url = null;
                    if (!empty($_FILES['product_image']['tmp_name'])) {
                        $upload_dir = './uploads/products/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        $ext     = strtolower(pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION));
                        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                        if (in_array($ext, $allowed)) {
                            $filename = 'product_new_' . time() . '.' . $ext;
                            $dest     = $upload_dir . $filename;
                            if (move_uploaded_file($_FILES['product_image']['tmp_name'], $dest)) {
                                $image_url = $dest;
                            }
                        }
                    }

                    $ins = $pdo->prepare(
                        "INSERT INTO Product
                            (user_id, category_id, product_name, quantity,
                             cost_price, retail_price, expiration_date, notes, image_url)
                         VALUES
                            (:user_id, :category_id, :product_name, :quantity,
                             :cost_price, :retail_price, :expiry, :notes, :image_url)"
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
                        ':image_url'    => $image_url,
                    ]);

                    $new_id = (int) $pdo->lastInsertId();
                    $pdo->commit();

                    $skuStmt = $pdo->prepare("SELECT sku FROM Product WHERE product_id = :id");
                    $skuStmt->execute([':id' => $new_id]);
                    $added_sku = $skuStmt->fetchColumn() ?: '';

                    // Log product addition
                    // Log product addition
                    try {
                        $pdo2    = getPDO();
                        $log_qty = max(1, $quantity);
                        $logStmt = $pdo2->prepare(
                            "INSERT INTO Inventory_Log
                                (product_id, product_name_snap, movement_type,
                                 quantity_change, selling_price, stock_before, stock_after,
                                 reference_type, adjustment_reason)
                             VALUES
                                (:pid, :pname, 'in',
                                 :qty, :price, 0, :after,
                                 'product_addition', NULL)"
                        );
                        $logStmt->execute([
                            ':pid'   => $new_id,
                            ':pname' => $product_name,
                            ':qty'   => $log_qty,
                            ':price' => $retail_price,
                            ':after' => $quantity,
                        ]);
                    } catch (PDOException $logEx) {
                        error_log("Product addition log error: " . $logEx->getMessage());
                    }

                    $add_flash_msg  = 'Product "' . htmlspecialchars($product_name) . '" added successfully!' .
                                      ($added_sku ? ' (SKU: ' . htmlspecialchars($added_sku) . ')' : '');
                    $add_flash_type = 'success';
                }
            }

        // ── UPDATE PRODUCT ──────────────────────────────────
        } elseif ($action === 'update') {
            $product_id    = (int) ($_POST['product_id'] ?? 0);
            $edit_product_id = $product_id;
            $product_name  = trim($_POST['product_name']    ?? '');
            $category_name = trim($_POST['category']        ?? '');
            $expiry_date   = $_POST['expiry_date']           ?? null;
            $no_expiry     = isset($_POST['no_expiry']);
            $quantity      = (int)   ($_POST['stock_quantity'] ?? 0);
            $cost_price    = (float) ($_POST['cost']           ?? 0);
            $retail_price  = (float) ($_POST['selling_price']  ?? 0);
            $notes         = trim($_POST['notes']           ?? '');

            if (empty($product_name) || !$product_id) {
                $edit_flash_msg  = 'Product name is required.';
                $edit_flash_type = 'error';
            } else {
                $pdo->beginTransaction();

                $target_cat = !empty($category_name) ? $category_name : 'Uncategorized';

                $insCAT = $pdo->prepare("INSERT IGNORE INTO Category (category_name) VALUES (:name)");
                $insCAT->execute([':name' => $target_cat]);

                $catStmt = $pdo->prepare("SELECT category_id FROM Category WHERE category_name = :name LIMIT 1");
                $catStmt->execute([':name' => $target_cat]);
                $cat = $catStmt->fetch();

                if (!$cat || (int) $cat['category_id'] <= 0) {
                    $pdo->rollBack();
                    $edit_flash_msg  = 'Could not resolve product category.';
                    $edit_flash_type = 'error';
                } else {
                    $cat_id       = (int) $cat['category_id'];
                    $final_expiry = ($no_expiry || empty($expiry_date)) ? null : $expiry_date;

                    $image_url = null;
                    if (!empty($_FILES['product_image']['tmp_name'])) {
                        $upload_dir = './uploads/products/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        $ext     = strtolower(pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION));
                        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
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
                            "UPDATE Product SET
                                product_name    = :product_name,
                                category_id     = :category_id,
                                quantity        = :quantity,
                                cost_price      = :cost_price,
                                retail_price    = :retail_price,
                                expiration_date = :expiry,
                                notes           = :notes,
                                image_url       = :image_url
                             WHERE product_id = :id AND user_id = :user_id"
                        );
                        $upd->execute([
                            ':product_name' => $product_name,
                            ':category_id'  => $cat_id,
                            ':quantity'     => $quantity,
                            ':cost_price'   => $cost_price,
                            ':retail_price' => $retail_price,
                            ':expiry'       => $final_expiry,
                            ':notes'        => $notes,
                            ':image_url'    => $image_url,
                            ':id'           => $product_id,
                            ':user_id'      => $user_id,
                        ]);
                    } else {
                        $upd = $pdo->prepare(
                            "UPDATE Product SET
                                product_name    = :product_name,
                                category_id     = :category_id,
                                quantity        = :quantity,
                                cost_price      = :cost_price,
                                retail_price    = :retail_price,
                                expiration_date = :expiry,
                                notes           = :notes
                             WHERE product_id = :id AND user_id = :user_id"
                        );
                        $upd->execute([
                            ':product_name' => $product_name,
                            ':category_id'  => $cat_id,
                            ':quantity'     => $quantity,
                            ':cost_price'   => $cost_price,
                            ':retail_price' => $retail_price,
                            ':expiry'       => $final_expiry,
                            ':notes'        => $notes,
                            ':id'           => $product_id,
                            ':user_id'      => $user_id,
                        ]);
                    }

                    // Log the edit if quantity changed
                 // Snapshot BEFORE commit so we get old quantity
                    $oldStmt = $pdo->prepare(
                        "SELECT quantity, product_name FROM Product
                         WHERE product_id = :id AND user_id = :user_id"
                    );
                    $oldStmt->execute([':id' => $product_id, ':user_id' => $user_id]);
                    $oldSnap = $oldStmt->fetch();
                    $old_qty = (int)($oldSnap['quantity'] ?? 0);

                    $pdo->commit();

                    // Log product edit — always logs, qty_change=1 if no qty change
                    try {
                        $pdo2    = getPDO();
                        $qty_diff    = $quantity - $old_qty;
                        $move_type   = $qty_diff >= 0 ? 'in' : 'out';
                        $qty_change  = abs($qty_diff) > 0 ? abs($qty_diff) : 1;
                        $stock_after = $qty_diff !== 0 ? $quantity : $old_qty;

                        $logStmt = $pdo2->prepare(
                            "INSERT INTO Inventory_Log
                                (product_id, product_name_snap, movement_type,
                                 quantity_change, selling_price, stock_before, stock_after,
                                 reference_type, adjustment_reason)
                             VALUES
                                (:pid, :pname, :move,
                                 :change, :price, :before, :after,
                                 'product_edit', NULL)"
                        );
                        $logStmt->execute([
                            ':pid'    => $product_id,
                            ':pname'  => $product_name,
                            ':move'   => $move_type,
                            ':change' => $qty_change,
                            ':price'  => $retail_price,
                            ':before' => $old_qty,
                            ':after'  => $stock_after,
                        ]);
                    } catch (PDOException $logEx) {
                        error_log("Edit log error: " . $logEx->getMessage());
                    }

                    $edit_flash_msg  = 'Product updated successfully!';
                    $edit_flash_type = 'success';
                    $edit_product_id = 0;
                }
            }
        }

    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        error_log("Manage products error: " . $e->getMessage());
        $dev_detail = DEBUG_MODE ? ' — ' . $e->getMessage() : '';

        if ($action === 'add') {
            $add_flash_msg  = 'A database error occurred. Please try again.' . $dev_detail;
            $add_flash_type = 'error';
        } elseif ($action === 'update') {
            $edit_flash_msg  = 'A database error occurred. Please try again.' . $dev_detail;
            $edit_flash_type = 'error';
        } else {
            $error = 'A database error occurred. Please try again.' . $dev_detail;
        }
    }
}

// ── Fetch data for display ───────────────────────────────────
try {
    $pdo             = getPDO();
    $search          = trim($_GET['search']          ?? '');
    $category_filter = trim($_GET['category_filter'] ?? '');

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM Product WHERE user_id = :user_id");
    $countStmt->execute([':user_id' => $user_id]);
    $total_products = (int) $countStmt->fetchColumn();

    $statsStmt = $pdo->prepare(
    "SELECT
        SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END)                                                          AS out_of_stock,
        SUM(CASE WHEN expiration_date IS NOT NULL AND expiration_date < CURDATE() THEN 1 ELSE 0 END)            AS expired,
        SUM(CASE WHEN status = 'Low Stock' THEN 1 ELSE 0 END) AS low_stock,
        SUM(CASE WHEN expiration_date IS NOT NULL
                  AND expiration_date BETWEEN CURDATE()
                  AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END)                                  AS near_expiry
     FROM Product WHERE user_id = :user_id"
);
    $statsStmt->execute([':user_id' => $user_id]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    $sql = "SELECT p.product_id, p.product_name, p.sku, p.quantity, p.cost_price,
                   p.retail_price, p.expiration_date, p.status, p.image_url, p.notes,
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

    $catListStmt = $pdo->prepare(
        "SELECT DISTINCT c.category_name
         FROM Category c
         LEFT JOIN Product p ON p.category_id = c.category_id AND p.user_id = :user_id
         WHERE c.category_name = 'Uncategorized' OR p.product_id IS NOT NULL
         ORDER BY c.category_name"
    );
    $catListStmt->execute([':user_id' => $user_id]);
    $categories = $catListStmt->fetchAll(PDO::FETCH_ASSOC);

    $edit_product = null;
    if ($edit_product_id > 0) {
        $editStmt = $pdo->prepare(
            "SELECT p.*, COALESCE(c.category_name, 'Uncategorized') AS category_name
             FROM Product p
             LEFT JOIN Category c ON c.category_id = p.category_id
             WHERE p.product_id = :id AND p.user_id = :user_id
             LIMIT 1"
        );
        $editStmt->execute([':id' => $edit_product_id, ':user_id' => $user_id]);
        $edit_product = $editStmt->fetch(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("Manage products fetch error: " . $e->getMessage());
    $products       = [];
    $stats          = [];
    $categories     = [];
    $total_products = 0;
    $edit_product   = null;
}

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

$open_add_overlay  = ($add_flash_type !== '') ? 'true' : 'false';
$open_edit_overlay = ($edit_product !== null || $edit_flash_type !== '') ? 'true' : 'false';
$js_edit_product_id = $edit_product_id;

// Build safe JSON for product map — use JSON_UNESCAPED_UNICODE and HtmlSpecialChars for JS embedding
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

$activePage = 'manage-products';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Products – ListaHub</title>

  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Roboto:wght@400;500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"/>

  <link rel="stylesheet" href="global_sidebar.css"/>
  <link rel="stylesheet" href="global_manage-products.css"/>
  <link rel="stylesheet" href="sidebar.css"/>
  <link rel="stylesheet" href="manage-products.css"/>
  <link rel="stylesheet" href="main-body.css"/>

  <style>
  /* ── CSS Variables (from global.css of the edit overlay design) ── */
  :root {
    --color-cornsilk: rgba(253, 243, 219, 0.45);
    --color-floralwhite: rgba(252, 248, 238, 0);
    --color-gray-100: #212934;
    --color-gray-200: rgba(62, 44, 35, 0.8);
    --color-gray-300: rgba(43, 43, 43, 0.8);
    --color-gray-400:     rgba(62, 44, 35, 0.8);
    --color-khaki: rgba(235, 214, 101, 0.66);
    --text-brown: #3e2c23;
    --gap-4: 4px;
    --gap-8: 8px;
    --gap-10: 10px;
    --gap-15: 15px;
    --padding-0: 0px;
    --padding-01: 0;
    --padding-2: 2px;
    --padding-10: 10px;
    --padding-12: 12px;
    --padding-20: 20px;
    --br-2: 2px;
    --br-10: 10px;
    --font-inter: Inter;
    --fs-16: 16px;
    --fs-18: 18px;
    --border-1: 1px solid var(--color-gray-400);
    --height-19: 19px;
    --height-20: 20px;
    --height-41: 41px;
    --height-67: 67px;
  }

  /* ── Unified toolbar row ── */
  .toolbar-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .toolbar-search-form {
    display: flex;
    align-items: center;
    flex: 1;
    min-width: 160px;
    max-width: 260px;
  }
  .toolbar-search-form .searchbar { width: 100%; }

  /* Products count pill */
  .count-box {
    display: flex;
    align-items: center;
    gap: 6px;
    background: transparent;
    border: 1px solid rgba(62, 44, 35, 0.2);
    border-radius: var(--br-10);
    padding: 8px 16px;
    white-space: nowrap;
    height: 37px;
    flex-shrink: 0;
  }
  .count-label {
    font-size: 14px;
    font-weight: 500;
    color: rgba(62, 44, 35, 0.7);
    font-family: var(--font-inter);
  }
  .count-val {
    font-size: 14px;
    font-weight: 700;
    color: var(--text-brown);
    font-family: var(--font-inter);
  }

  /* Category dropdown — clean native select */
  .toolbar-cat-form {
    display: flex;
    align-items: center;
    flex-shrink: 0;
  }
  .category-select-pill {
    height: 37px;
    border-radius: var(--br-10);
    border: 1px solid rgba(62, 44, 35, 0.2);
    background: transparent;
    padding: 0 14px;
    font-family: var(--font-inter);
    font-size: 14px;
    font-weight: 500;
    color: var(--text-brown);
    cursor: pointer;
    appearance: auto;
    outline: none;
    transition: border-color 0.15s, background 0.15s;
  }
  .category-select-pill:hover {
    border-color: rgba(62, 44, 35, 0.4);
    background: rgba(255, 255, 255, 0.15);
  }
  .category-select-pill:focus { border-color: var(--text-brown); }

  /* ══════════════════════════════════════════════════
     OVERLAY BACKDROP (shared for both overlays)
  ══════════════════════════════════════════════════ */
  #add-product-overlay,
  #edit-product-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1000;
    /* Frosted glass backdrop matching the design */
    background: linear-gradient(
      180deg,
      rgba(235, 233, 225, 0.55),
      rgba(169, 174, 181, 0.55)
    );
    backdrop-filter: blur(51px);
    -webkit-backdrop-filter: blur(51px);
    box-shadow: 0 11.4px 22.3px rgba(53, 106, 185, 0.09) inset,
                0 -2px 1px rgba(151, 193, 255, 0.4) inset;
    align-items: center;
    justify-content: center;
    padding: 20px;
    overflow-y: auto;
  }
  #add-product-overlay.is-open,
  #edit-product-overlay.is-open {
    display: flex;
  }

  /* ══════════════════════════════════════════════════
     EDIT OVERLAY — modal card (matches the design CSS)
  ══════════════════════════════════════════════════ */
  .edit-modal-card {
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 753px;
    box-shadow: 36px 30px 13px transparent,
                23px 19px 12px rgba(62, 44, 35, 0.01),
                13px 11px 10px rgba(62, 44, 35, 0.05),
                6px 5px 8px rgba(62, 44, 35, 0.09),
                1px 1px 4px rgba(62, 44, 35, 0.1);
    backdrop-filter: blur(20.6px);
    -webkit-backdrop-filter: blur(20.6px);
    border-radius: 15px;
    background: linear-gradient(
        146.01deg,
        rgba(253, 253, 253, 0.58),
        rgba(254, 246, 227, 0.49) 49.52%,
        rgba(255, 244, 216, 0.6)
      ),
      linear-gradient(rgba(252, 248, 238, 0.2), rgba(252, 248, 238, 0.2));
    border: 2px solid var(--text-brown);
    overflow: hidden;
    padding: 17px 15px;
    gap: 0;
    animation: modalIn 0.2s ease;
  }

  /* Header row */
  .edit-modal-header {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--gap-10);
    margin-bottom: 12px;
  }
  .edit-modal-title {
    margin: 0;
    flex: 1;
    font-size: 32px;
    letter-spacing: -0.04em;
    font-weight: 800;
    font-family: var(--font-inter);
    color: var(--text-brown);
    min-width: 179px;
  }
  .edit-modal-close-btn {
    cursor: pointer;
    border: 0;
    padding: 0;
    background-color: transparent;
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
  .edit-modal-close-btn:hover {
    background: rgba(62,44,35,0.1);
  }

  /* Flash message */
  .edit-flash {
    display: none;
    padding: 8px 14px;
    border-radius: var(--br-10);
    font-family: var(--font-inter);
    font-size: 14px;
    margin-bottom: 10px;
  }
  .edit-flash.show { display: block; }
  .edit-flash.flash-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
  .edit-flash.flash-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

  /* Inner form section — beige card */
  .edit-form-card {
    box-shadow: 31px 57px 18px transparent,
                20px 36px 17px rgba(0,0,0,0.01),
                11px 20px 14px rgba(0,0,0,0.03),
                5px 9px 10px rgba(0,0,0,0.04),
                1px 2px 6px rgba(0,0,0,0.05);
    border-radius: var(--br-10);
    overflow: hidden;
    padding: var(--padding-12) 14px var(--padding-10);
    gap: var(--gap-8);
    display: flex;
    flex-direction: column;
    background: rgba(252, 248, 235, 0.85);
    font-family: var(--font-inter);
    font-size: var(--fs-18);
    color: var(--color-gray-100);
  }

  /* Top row: image + product name/category */
  .edit-top-row {
    display: flex;
    align-items: flex-start;
    gap: var(--gap-10);
    flex-wrap: wrap;
  }

  /* Image upload box */
  .edit-img-box {
    height: 201px;
    width: 232px;
    border-radius: var(--br-10);
    border: 2px dashed rgba(62,44,35,0.3);
    background: rgba(253,243,219,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    cursor: pointer;
    overflow: hidden;
    flex-shrink: 0;
  }
  .edit-img-box input[type="file"] {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    width: 100%;
    height: 100%;
  }
  .edit-img-box img.preview {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: var(--br-10);
    display: none;
  }
  .edit-img-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    pointer-events: none;
    color: var(--text-brown);
    opacity: 0.5;
  }
  .edit-img-placeholder i { font-size: 48px; }
  .edit-img-placeholder span { font-size: 12px; font-family: var(--font-inter); }

  /* Right fields */
  .edit-right-fields {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: var(--gap-8);
    min-width: 220px;
    justify-content: center;
    padding: 30px 0 28px;
  }

  /* Field group */
  .edit-field-group {
    display: flex;
    flex-direction: column;
    gap: var(--gap-4);
    align-self: stretch;
  }
  .edit-label {
    font-size: var(--fs-16);
    letter-spacing: -0.04em;
    color: var(--text-brown);
    font-weight: 500;
  }
  .edit-input-field {
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
  .edit-input-field input,
  .edit-input-field textarea {
    width: 100%;
    border: 0;
    outline: 0;
    font-family: var(--font-inter);
    font-size: var(--fs-16);
    background-color: transparent;
    letter-spacing: -0.04em;
    color: var(--color-gray-300);
    padding: 0;
  }
  .edit-input-field .peso-sign {
    font-size: var(--fs-16);
    color: var(--text-brown);
    flex-shrink: 0;
  }

  /* SKU display (read-only) */
  .edit-sku-field {
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
  .edit-expiry-wrap {
    height: 42px;
    border-radius: var(--br-10);
    background-color: var(--color-cornsilk);
    border: var(--border-1);
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--padding-10) var(--padding-12) var(--padding-10) var(--padding-20);
    gap: 0;
    font-size: 13px;
    color: var(--color-gray-200);
  }
  .edit-date-picker {
    display: flex;
    align-items: center;
    gap: var(--gap-10);
  }
  .edit-date-picker i { font-size: 16px; color: var(--text-brown); }
  .edit-date-picker input[type="date"] {
    border: 0;
    outline: 0;
    background: transparent;
    font-family: var(--font-inter);
    font-size: 13px;
    color: var(--color-gray-200);
    letter-spacing: -0.04em;
    cursor: pointer;
  }
  .edit-none-part {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--text-brown);
  }

  /* Inline row for multiple fields */
  .edit-fields-row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--gap-10);
    align-items: flex-start;
  }
  .edit-fields-row .edit-field-group {
    flex: 1;
    min-width: 140px;
  }

  /* Notes textarea */
  .edit-textarea-field {
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
  .edit-textarea-field textarea {
    width: 100%;
    border: 0;
    outline: 0;
    background: transparent;
    font-family: var(--font-inter);
    font-size: var(--fs-16);
    color: var(--color-gray-300);
    resize: none;
    height: 100%;
    letter-spacing: -0.04em;
  }

  /* Footer buttons */
  .edit-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding: 2px 0 0;
    gap: var(--gap-10);
  }
  .edit-btn-cancel {
    border-radius: 231px;
    background-color: rgba(252, 248, 238, 0);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--padding-10) var(--padding-20);
    font-family: var(--font-inter);
    font-size: var(--fs-16);
    font-weight: 500;
    letter-spacing: -0.04em;
    color: var(--text-brown);
    cursor: pointer;
    border: none;
    background: transparent;
  }
  .edit-btn-cancel:hover { text-decoration: underline; }
  .edit-btn-update {
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
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--font-inter);
    font-size: var(--fs-16);
    font-weight: 500;
    letter-spacing: -0.04em;
    color: var(--text-brown);
  }
  .edit-btn-update:hover {
    background-color: rgba(235, 214, 101, 0.9);
  }

  /* ── ADD overlay uses existing styles from manage-products.css ── */
  /* Keep the existing add overlay structure as-is */
  .add-modal-wrapper {
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 753px;
    box-shadow: 36px 30px 13px transparent,
                23px 19px 12px rgba(62,44,35,0.01),
                13px 11px 10px rgba(62,44,35,0.05),
                6px 5px 8px rgba(62,44,35,0.09),
                1px 1px 4px rgba(62,44,35,0.1);
    backdrop-filter: blur(20.6px);
    border-radius: 15px;
    background: linear-gradient(
        146.01deg,
        rgba(253,253,253,0.58),
        rgba(254,246,227,0.49) 49.52%,
        rgba(255,244,216,0.6)
      ),
      linear-gradient(rgba(252,248,238,0.2), rgba(252,248,238,0.2));
    border: 2px solid var(--text-brown);
    overflow: hidden;
    padding: 17px 15px;
    gap: 12px;
  }

  @media screen and (max-width: 800px) {
    .edit-modal-card,
    .add-modal-wrapper { max-width: 100%; width: calc(100% - 40px); }
    .edit-modal-title { font-size: 26px; }
  }
  @media screen and (max-width: 450px) {
    .edit-modal-title { font-size: 19px; }
    .edit-fields-row { flex-wrap: wrap; }
  }
  .main-body {
  flex: 1;
  padding: 20px 10px 10px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 18px;
  min-width: 0;
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
  </style>
</head>
<body>

<div class="page-wrapper">

  <?php $activePage = 'manage-products'; include 'sidebar.php'; ?>

  <div class="main-body">

    <?php if ($message): ?>
      <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- ── OVERVIEW ── -->
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
            <span class="stat-value"><?= (int)($stats['near_expiry'] ?? 0) ?> Product<?= ((int)($stats['near_expiry'] ?? 0)) !== 1 ? 's' : '' ?></span>
          </div>
        </div>

      </div>
    </section>

    <!-- ── TABLE SECTION ── -->
    <div class="container2">

      <div class="table-actions">

        <!-- Single unified row: search | count + category | action buttons -->
        <div class="toolbar-row">

          <!-- Search (submits with current category filter preserved) -->
          <form method="get" class="toolbar-search-form">
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

          <!-- Products count pill -->
          <div class="count-box">
            <span class="count-label">Products Count :</span>
            <span class="count-val"><?= count($products) ?></span>
          </div>

          <!-- Category dropdown (its own form so it submits only category_filter) -->
          <form method="get" class="toolbar-cat-form">
            <?php if (!empty($search)): ?>
              <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"/>
            <?php endif; ?>
            <select name="category_filter" class="category-select-pill" onchange="this.form.submit()">
              <option value="">All Categories</option>
              <?php foreach ($categories as $cat): ?>
                <option
                  value="<?= htmlspecialchars($cat['category_name']) ?>"
                  <?= ($category_filter === $cat['category_name']) ? 'selected' : '' ?>
                >
                  <?= htmlspecialchars($cat['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>

          <!-- Action buttons pushed to the right -->
          <div class="action-btns">
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

      </div><!-- /table-actions -->

      <!-- Table -->
      <div class="table-wrap">
        <div class="table-inner">
          <div class="table2">

            <div class="thead">
              <div class="thead-row">
                <b class="col-img" style="white-space:nowrap;">View Image</b>
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
                      <div class="col-img">
                        <?php if (!empty($p['image_url'])): ?>
                          <img
                            src="<?= htmlspecialchars($p['image_url']) ?>"
                            class="prod-thumb"
                            onclick="openImgPreview('<?= htmlspecialchars($p['image_url']) ?>', '<?= htmlspecialchars(addslashes($p['product_name'])) ?>')"
                            alt="<?= htmlspecialchars($p['product_name']) ?>"
                            style="width:60px;height:60px;object-fit:cover;border-radius:6px;border:1px solid rgba(62,44,35,0.2);cursor:pointer;display:block;flex-shrink:0;"
                          />
                        <?php else: ?>
                          <div class="prod-thumb-empty" style="width:60px;height:60px;border-radius:6px;border:1px dashed rgba(62,44,35,0.2);display:flex;align-items:center;justify-content:center;color:rgba(62,44,35,0.3);font-size:24px;flex-shrink:0;cursor:default;">
                              <i class="bi bi-image"></i>
                          </div>
                        <?php endif; ?> 
                      </div>
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
                          onclick="openEditOverlay(<?= (int)$p['product_id'] ?>)"
                        >
                          <i class="bi bi-eye"></i>
                        </button>

                        <form class="delete-form" method="post">
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
     ════════════════════════════════════════════════════════════ -->
<div id="add-product-overlay" role="dialog" aria-modal="true" aria-label="Add Product">
  <div class="edit-modal-card">

    <div class="edit-modal-header">
      <h1 class="edit-modal-title">ADD PRODUCTS</h1>
      <button class="edit-modal-close-btn" type="button" onclick="closeAddOverlay()" title="Close">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>

    <div class="edit-flash<?= $add_flash_msg ? ' show flash-' . $add_flash_type : '' ?>"
         id="add-flash"
    ><?= htmlspecialchars($add_flash_msg) ?></div>

    <section class="edit-form-card">
      <form method="post" enctype="multipart/form-data" id="add-product-form">
        <input type="hidden" name="action" value="add"/>

        <div class="edit-top-row">
          <div class="edit-img-box" id="add-img-box">
            <div class="edit-img-placeholder" id="add-img-placeholder">
              <i class="bi bi-image-fill"></i>
              <span>Click to upload</span>
            </div>
            <img class="preview" id="add-img-preview" src="" alt="Product Image"/>
            <input
              type="file"
              name="product_image"
              id="add-product-image-input"
              accept="image/*"
              onchange="previewAddImage(this)"
            />
          </div>

          <div class="edit-right-fields">
            <div class="edit-field-group">
              <div class="edit-label">Product Name</div>
              <div class="edit-input-field">
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
            <div class="edit-field-group">
              <div class="edit-label">Category</div>
              <div class="edit-input-field">
                <input
                  type="text"
                  id="product-category"
                  name="category"
                  placeholder="Enter Here (leave blank for Uncategorized)"
                  list="cat-list"
                  autocomplete="off"
                />
              </div>
            </div>
          </div>
        </div>

        <div class="edit-fields-row" style="margin-top:8px;">
          <div class="edit-field-group">
            <div class="edit-label">SKU</div>
            <div class="edit-sku-field">
              <span>Auto-generated on save</span>
            </div>
          </div>
          <div class="edit-field-group">
            <div class="edit-label">Expiry Date</div>
            <div class="edit-expiry-wrap">
              <div class="edit-date-picker">
                <input type="date" id="expiry-date" name="expiry_date"/>
              </div>
              <div class="edit-none-part">
                <label for="no-expiry">None</label>
                <input
                  type="checkbox"
                  id="no-expiry"
                  name="no_expiry"
                  onchange="toggleExpiry(this,'expiry-date')"
                />
              </div>
            </div>
          </div>
        </div>

        <div class="edit-fields-row" style="margin-top:8px;">
          <div class="edit-field-group">
            <div class="edit-label">Stock Quantity</div>
            <div class="edit-input-field">
              <input type="number" id="stock-qty" name="stock_quantity" min="1" required/>
            </div>
          </div>
          <div class="edit-field-group">
            <div class="edit-label">Cost</div>
            <div class="edit-input-field">
              <span class="peso-sign">₱</span>
              <input type="number" id="cost-price" name="cost"  step="0.01" min="1" required/>
            </div>
          </div>
          <div class="edit-field-group">
            <div class="edit-label">Selling Price</div>
            <div class="edit-input-field">
              <span class="peso-sign">₱</span>
              <input type="number" id="selling-price" name="selling_price"  step="0.01" min="1" required/>
            </div>
          </div>
        </div>

        <div class="edit-fields-row" style="margin-top:8px; align-items:flex-start;">
          <div class="edit-field-group" style="flex:1;">
            <div class="edit-label">Additional Notes</div>
            <div class="edit-textarea-field">
              <textarea id="notes" name="notes" placeholder="Enter Here"></textarea>
            </div>
          </div>
        </div>

        <div class="edit-footer" style="margin-top:8px;">
          <button type="button" class="edit-btn-cancel" onclick="closeAddOverlay()">Cancel</button>
          <button type="submit" class="edit-btn-update">Complete</button>
        </div>

      </form>
    </section>

  </div>
</div>


<!-- ════════════════════════════════════════════════════════════
     EDIT PRODUCT OVERLAY  (redesigned to match the uploaded image)
     ════════════════════════════════════════════════════════════ -->
<div id="edit-product-overlay" role="dialog" aria-modal="true" aria-label="Edit Product">
  <div class="edit-modal-card">

    <!-- Header -->
    <div class="edit-modal-header">
      <h1 class="edit-modal-title">EDIT PRODUCT</h1>
      <button class="edit-modal-close-btn" type="button" onclick="closeEditOverlay()" title="Close">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>

    <!-- Flash message -->
    <div
      class="edit-flash<?= $edit_flash_msg ? ' show flash-' . $edit_flash_type : '' ?>"
      id="edit-flash"
    ><?= htmlspecialchars($edit_flash_msg) ?></div>

    <!-- Form card (the beige inner section) -->
    <section class="edit-form-card">
      <form method="post" enctype="multipart/form-data" id="edit-product-form">
        <input type="hidden" name="action" value="update"/>
        <input type="hidden" name="product_id" id="edit-product-id" value="<?= $edit_product_id ?>"/>

        <!-- Top row: image + name/category -->
        <div class="edit-top-row">

          <!-- Image box -->
          <div class="edit-img-box" id="edit-img-box">
            <div class="edit-img-placeholder" id="edit-img-placeholder">
              <i class="bi bi-image-fill"></i>
              <span>Click to upload</span>
            </div>
            <img class="preview" id="edit-img-preview" src="" alt="Product Image"/>
            <input
              type="file"
              name="product_image"
              id="edit-product-image-input"
              accept="image/*"
              onchange="previewEditImage(this)"
            />
          </div>

          <!-- Right: name + category -->
          <div class="edit-right-fields">
            <div class="edit-field-group">
              <div class="edit-label">Product Name</div>
              <div class="edit-input-field">
                <input
                  type="text"
                  id="edit-product-name"
                  name="product_name"
                  placeholder="Enter Here"
                  required
                  autocomplete="off"
                />
              </div>
            </div>
            <div class="edit-field-group">
              <div class="edit-label">Category</div>
              <div class="edit-input-field">
                <input
                  type="text"
                  id="edit-product-category"
                  name="category"
                  placeholder="Enter Here"
                  list="cat-list"
                  autocomplete="off"
                />
              </div>
            </div>
          </div>

        </div><!-- /edit-top-row -->

        <!-- SKU + Expiry Date row -->
        <div class="edit-fields-row" style="margin-top:8px;">
          <div class="edit-field-group">
            <div class="edit-label">SKU</div>
            <div class="edit-sku-field" id="edit-sku-display">—</div>
          </div>
          <div class="edit-field-group">
            <div class="edit-label">Expiry Date</div>
            <div class="edit-expiry-wrap">
              <div class="edit-date-picker">
            
                <input type="date" id="edit-expiry-date" name="expiry_date"/>
              </div>
              <div class="edit-none-part">
                <label for="edit-no-expiry">None</label>
                <input
                  type="checkbox"
                  id="edit-no-expiry"
                  name="no_expiry"
                  onchange="toggleExpiry(this,'edit-expiry-date')"
                />
              </div>
            </div>
          </div>
        </div>

        <!-- Stock Quantity + Cost + Selling Price -->
        <div class="edit-fields-row" style="margin-top:8px;">
          <div class="edit-field-group">
            <div class="edit-label">Stock Quantity</div>
            <div class="edit-input-field">
              <input type="number" id="edit-stock-qty" name="stock_quantity"  min="1"/>
            </div>
          </div>
          <div class="edit-field-group">
            <div class="edit-label">Cost</div>
            <div class="edit-input-field">
              <span class="peso-sign">₱</span>
              <input type="number" id="edit-cost-price" name="cost"  step="0.01" min="1"/>
            </div>
          </div>
          <div class="edit-field-group">
            <div class="edit-label">Selling Price</div>
            <div class="edit-input-field">
              <span class="peso-sign">₱</span>
              <input type="number" id="edit-selling-price" name="selling_price"  step="0.01" min="1"/>
            </div>
          </div>
        </div>

        <!-- Additional Notes -->
        <div class="edit-fields-row" style="margin-top:8px;align-items:flex-start;">
          <div class="edit-field-group" style="flex:1;">
            <div class="edit-label">Additional Notes</div>
            <div class="edit-textarea-field">
              <textarea id="edit-notes" name="notes" placeholder="Enter Here"></textarea>
            </div>
          </div>
        </div>

        <!-- Footer buttons -->
        <div class="edit-footer" style="margin-top:8px;">
          <button type="button" class="edit-btn-cancel" onclick="closeEditOverlay()">Cancel</button>
          <button type="submit" class="edit-btn-update">Update</button>
        </div>

      </form>
    </section>

  </div>
</div>

<div id="del-modal-overlay" style="display:none;" onclick="cancelDelModal()">
  <div id="del-modal-box" onclick="event.stopPropagation()">
    <div id="del-modal-icon">🗑️</div>
    <div id="del-modal-title">Delete Product</div>
    <div id="del-modal-body">Are you sure you want to delete this product? This cannot be undone.</div>
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

<!-- Category autocomplete datalist (shared) -->
<datalist id="cat-list">
  <?php foreach ($categories as $cat): ?>
    <option value="<?= htmlspecialchars($cat['category_name']) ?>">
  <?php endforeach; ?>
  <option value="Uncategorized">
</datalist>


<script>
'use strict';

/* ─────────────────────────────────────────────────────────────
   OVERLAY HELPERS
───────────────────────────────────────────────────────────── */
function lockBody()   { document.body.style.overflow = 'hidden'; }
function unlockBody() { document.body.style.overflow = ''; }

/* ─────────────────────────────────────────────────────────────
   ADD PRODUCT OVERLAY
───────────────────────────────────────────────────────────── */
var addOverlay = document.getElementById('add-product-overlay');

function openAddOverlay() {
  addOverlay.classList.add('is-open');
  lockBody();
}
function closeAddOverlay() {
  addOverlay.classList.remove('is-open');
  unlockBody();
}
addOverlay.addEventListener('click', function(e) {
  if (e.target === addOverlay) closeAddOverlay();
});

// Auto-open after POST
var shouldOpenAdd = <?= $open_add_overlay ?>;
if (shouldOpenAdd) {
  openAddOverlay();
  <?php if ($add_flash_type === 'success'): ?>
  (function() {
    var form = document.getElementById('add-product-form');
    if (form) {
      form.reset();
      var preview = document.getElementById('add-img-preview');
      var icon    = document.querySelector('#add-img-upload-box > i');
      if (preview) { preview.style.display = 'none'; preview.src = ''; }
      if (icon)    { icon.style.display = ''; }
      var expiryInput = document.getElementById('expiry-date');
      if (expiryInput) expiryInput.disabled = false;
    }
    setTimeout(function() {
      var flash = document.getElementById('add-flash');
      if (flash) { flash.style.transition = 'opacity 0.5s'; flash.style.opacity = '0'; }
    }, 4000);
    setTimeout(function() {
      var flash = document.getElementById('add-flash');
      if (flash) { flash.style.display = 'none'; }
    }, 4600);
  })();
  <?php endif; ?>
}

function previewAddImage(input) {
  var preview = document.getElementById('add-img-preview');
  var icon    = document.querySelector('#add-img-upload-box > i');
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function(e) {
      preview.src           = e.target.result;
      preview.style.display = 'block';
      if (icon) icon.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
  }
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

validateExpiryRequired('add-product-form',  'expiry-date',      'no-expiry');
validateExpiryRequired('edit-product-form', 'edit-expiry-date', 'edit-no-expiry');

/* ─────────────────────────────────────────────────────────────
   EDIT PRODUCT OVERLAY
   Product data is embedded from PHP using safe JSON encoding.
───────────────────────────────────────────────────────────── */
var editOverlay  = document.getElementById('edit-product-overlay');

// Safe JSON embed — all special chars escaped via JSON_HEX_* flags
var productsData = <?= $productsDataJson ?>;

function openEditOverlay(productId) {
  var p = productsData[productId];
  if (!p) {
    console.error('openEditOverlay: product not found for id', productId);
    return;
  }

  // Hidden product_id field
  document.getElementById('edit-product-id').value = productId;

  // Name & category
  document.getElementById('edit-product-name').value     = p.product_name     || '';
  document.getElementById('edit-product-category').value = p.category_name    || '';

  // SKU (read-only display)
  document.getElementById('edit-sku-display').textContent = p.sku || '—';

  // Quantities & prices
  document.getElementById('edit-stock-qty').value     = p.quantity;
  document.getElementById('edit-cost-price').value    = parseFloat(p.cost_price   || 0).toFixed(2);
  document.getElementById('edit-selling-price').value = parseFloat(p.retail_price || 0).toFixed(2);

  // Notes
  document.getElementById('edit-notes').value = p.notes || '';

  // Expiry date
  var expiryInput = document.getElementById('edit-expiry-date');
  var noExpiryChk = document.getElementById('edit-no-expiry');
  if (p.expiration_date) {
    expiryInput.value    = p.expiration_date;
    expiryInput.disabled = false;
    noExpiryChk.checked  = false;
  } else {
    expiryInput.value    = '';
    expiryInput.disabled = true;
    noExpiryChk.checked  = true;
  }

  // Image preview
  var preview     = document.getElementById('edit-img-preview');
  var placeholder = document.getElementById('edit-img-placeholder');
  if (p.image_url) {
    preview.src           = p.image_url;
    preview.style.display = 'block';
    if (placeholder) placeholder.style.display = 'none';
  } else {
    preview.src           = '';
    preview.style.display = 'none';
    if (placeholder) placeholder.style.display = 'flex';
  }

  // Reset file input so change event fires reliably
  document.getElementById('edit-product-image-input').value = '';

  // Show overlay
  editOverlay.classList.add('is-open');
  lockBody();
}

function closeEditOverlay() {
  editOverlay.classList.remove('is-open');
  unlockBody();
}

// Close on backdrop click
editOverlay.addEventListener('click', function(e) {
  if (e.target === editOverlay) closeEditOverlay();
});

// Auto-open after failed update POST
var shouldOpenEdit   = <?= $open_edit_overlay ?>;
var jsEditProductId  = <?= (int)$js_edit_product_id ?>;
if (shouldOpenEdit && jsEditProductId > 0) {
  <?php if ($edit_product && $edit_flash_type !== ''): ?>
  openEditOverlay(jsEditProductId);
  var ef = document.getElementById('edit-flash');
  if (ef) {
    ef.classList.add('show');
    setTimeout(function() { ef.style.transition = 'opacity 0.5s'; ef.style.opacity = '0'; }, 3500);
  }
  <?php endif; ?>
}

<?php if ($edit_flash_type === 'success'): ?>
setTimeout(function() {
  var flash = document.getElementById('edit-flash');
  if (flash) { flash.style.transition = 'opacity 0.5s'; flash.style.opacity = '0'; }
}, 3500);
<?php endif; ?>

function previewEditImage(input) {
  var preview     = document.getElementById('edit-img-preview');
  var placeholder = document.getElementById('edit-img-placeholder');
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function(e) {
      preview.src           = e.target.result;
      preview.style.display = 'block';
      if (placeholder) placeholder.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
  }
}

/* ─────────────────────────────────────────────────────────────
   Shared helpers
───────────────────────────────────────────────────────────── */
function toggleExpiry(checkbox, inputId) {
  var dateInput = document.getElementById(inputId);
  if (!dateInput) return;
  dateInput.disabled = checkbox.checked;
  if (checkbox.checked) dateInput.value = '';
}

// ESC closes any open overlay
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    if (document.getElementById('img-preview-overlay').style.display === 'flex') closeImgPreview();
    else if (editOverlay.classList.contains('is-open')) closeEditOverlay();
    else if (addOverlay.classList.contains('is-open'))  closeAddOverlay();
  }
});

/* ─────────────────────────────────────────────────────────────
   Client-side search filter
───────────────────────────────────────────────────────────── */
var searchInput = document.querySelector('.searchbar input');
if (searchInput) {
  searchInput.addEventListener('input', function() {
    var q    = this.value.toLowerCase().trim();
    var rows = document.querySelectorAll('#inv-tbody .tbody-row');
    rows.forEach(function(row) {
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
  var visible = allRows.filter(function(r) { return r.dataset.hidden !== '1'; });
  var total   = visible.length;
  var maxPage = Math.max(1, Math.ceil(total / ROWS_PER_PAGE));
  if (currentPage > maxPage) currentPage = maxPage;

  var start = (currentPage - 1) * ROWS_PER_PAGE;
  var end   = start + ROWS_PER_PAGE;

  allRows.forEach(function(r)    { r.style.display = 'none'; });
  visible.forEach(function(r, i) { r.style.display = (i >= start && i < end) ? '' : 'none'; });

  var btnPrev = document.getElementById('btn-prev');
  var btnNext = document.getElementById('btn-next');
  if (btnPrev) btnPrev.disabled = currentPage <= 1;
  if (btnNext) btnNext.disabled = currentPage >= maxPage;
}

var btnPrev = document.getElementById('btn-prev');
var btnNext = document.getElementById('btn-next');
if (btnPrev) btnPrev.addEventListener('click', function() { if (currentPage > 1) { currentPage--; renderPage(); } });
if (btnNext) btnNext.addEventListener('click', function() { currentPage++; renderPage(); });

/* ─────────────────────────────────────────────────────────────
   Export
───────────────────────────────────────────────────────────── */
function exportInventory() {
  window.location.href = 'export_inventory.php';
}

var _pendingDeleteForm = null;

function cancelDelModal() {
  document.getElementById('del-modal-overlay').style.display = 'none';
  _pendingDeleteForm = null;
}

document.querySelectorAll('.delete-form').forEach(function(form) {
  form.addEventListener('submit', function(e) {
    e.preventDefault();
    _pendingDeleteForm = form;
    document.getElementById('del-modal-overlay').style.display = 'flex';
    document.getElementById('del-modal-confirm-btn').onclick = function() {
      document.getElementById('del-modal-overlay').style.display = 'none';
      _pendingDeleteForm.submit();
    };
  });
});

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

/* Init */
renderPage();
</script>
</body>
</html>