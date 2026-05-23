<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die("Not logged in. <a href='index.php'>Login first</a>");
}

require_once './utils/lhdb.php';
$user_id = (int) $_SESSION['user_id'];

echo "<h2>User ID in session: $user_id</h2>";

try {
    $pdo = getPDO();
    echo "<p style='color:green'>✅ DB Connection OK</p>";

    // 1. Check if user exists
    $s = $pdo->prepare("SELECT user_id, store_name FROM User WHERE user_id = :uid");
    $s->execute([':uid' => $user_id]);
    $user = $s->fetch();
    echo "<h3>1. User row:</h3><pre>" . print_r($user, true) . "</pre>";

    // 2. Check raw products
    $s = $pdo->prepare("SELECT product_id, product_name, quantity, cost_price, retail_price, status, expiration_date FROM Product WHERE user_id = :uid");
    $s->execute([':uid' => $user_id]);
    $products = $s->fetchAll();
    echo "<h3>2. Products (" . count($products) . " rows):</h3><pre>" . print_r($products, true) . "</pre>";

    // 3. Check raw sales
    $s = $pdo->prepare("SELECT s.sale_id, s.total_amount, s.sale_date FROM Sale s JOIN Sale_Item si ON si.sale_id = s.sale_id JOIN Product p ON p.product_id = si.product_id WHERE p.user_id = :uid LIMIT 10");
    $s->execute([':uid' => $user_id]);
    $sales = $s->fetchAll();
    echo "<h3>3. Sales joined to user (" . count($sales) . " rows):</h3><pre>" . print_r($sales, true) . "</pre>";

    // 4. Check view directly
    $s = $pdo->prepare("SELECT * FROM vw_manager_dashboard WHERE user_id = :uid LIMIT 5");
    $s->execute([':uid' => $user_id]);
    $view = $s->fetchAll();
    echo "<h3>4. vw_manager_dashboard (" . count($view) . " rows):</h3><pre>" . print_r($view, true) . "</pre>";

    // 5. Check the dashboard aggregate query
    $s = $pdo->prepare("SELECT
        COUNT(*)                                                                        AS total_products,
        SUM(current_stock)                                                              AS total_stock_units,
        SUM(CASE WHEN current_stock = 0 THEN 1 ELSE 0 END)                             AS out_of_stock_count,
        SUM(CASE WHEN current_stock > 0 AND current_stock < 10 THEN 1 ELSE 0 END)      AS low_stock_count,
        SUM(CASE WHEN expiration_date IS NOT NULL AND expiration_date < CURDATE() THEN 1 ELSE 0 END) AS expired_count,
        SUM(total_units_sold)                                                           AS total_units_sold,
        SUM(total_revenue)                                                              AS total_revenue,
        SUM(current_stock * cost_price)                                                 AS total_cost_value,
        SUM(current_stock * retail_price)                                               AS total_retail_value
     FROM vw_manager_dashboard WHERE user_id = :uid");
    $s->execute([':uid' => $user_id]);
    $agg = $s->fetch();
    echo "<h3>5. Dashboard aggregate query result:</h3><pre>" . print_r($agg, true) . "</pre>";

    // 6. Check customers
    $s = $pdo->prepare("SELECT customer_id, customer_name, balance FROM Customer WHERE user_id = :uid LIMIT 5");
    $s->execute([':uid' => $user_id]);
    $custs = $s->fetchAll();
    echo "<h3>6. Customer table (checking user_id column):</h3><pre>" . print_r($custs, true) . "</pre>";

} catch (PDOException $e) {
    echo "<p style='color:red'>❌ ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
}
// Customer debug
$test = $pdo->prepare(
    "SELECT DISTINCT s.customer_id
     FROM Sale s
     JOIN Sale_Item si ON si.sale_id = s.sale_id
     JOIN Product p ON p.product_id = si.product_id
     WHERE p.user_id = :uid AND s.customer_id IS NOT NULL"
);
$test->execute([':uid' => $user_id]);
echo "<h3>Customer IDs linked to user:</h3><pre>" . print_r($test->fetchAll(), true) . "</pre>";

$test2 = $pdo->query("SELECT customer_id, customer_name, total_outstanding FROM Customer");
echo "<h3>All customers in DB:</h3><pre>" . print_r($test2->fetchAll(), true) . "</pre>";

?>