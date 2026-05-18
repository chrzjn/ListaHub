<?php
// ============================================================
//  login.php
//  Requirements met:
//   ✅ PDO prepared statements
//   ✅ password_verify()
//   ✅ try-catch (no sensitive info exposed)
//   ✅ Centralized lhdb.php
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.php");
    exit;
}

require_once './utils/lhdb.php';

$login    = trim($_POST["login"]    ?? '');
$password =      $_POST["password"] ?? '';

if (empty($login) || empty($password)) {
    echo "<script>alert('Please enter your username/email and password.'); window.location='index.php';</script>";
    exit;
}

try {
    $pdo = getPDO();

    // Prepared Statement — joins users → stores in one query
    $stmt = $pdo->prepare(
        "SELECT u.id, u.username, u.email, u.password,
                s.id AS store_id, s.store_name
         FROM   users u
         LEFT JOIN stores s ON s.user_id = u.id
         WHERE  u.username = :username OR u.email = :email
         LIMIT  1"
    );
    $stmt->execute([
        ':username' => $login,
        ':email'    => $login,
    ]);
    $user = $stmt->fetch();

    // password_verify() requirement
    if (!$user || !password_verify($password, $user['password'])) {
        echo "<script>alert('Invalid username/email or password.'); window.location='index.php';</script>";
        exit;
    }

    // Prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id']    = $user['id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['email']      = $user['email'];
    $_SESSION['store_id']   = $user['store_id'];
    $_SESSION['store_name'] = $user['store_name'];

    header("Location: dashboard.php");
    exit;

} catch (PDOException $e) {
    error_log("Login error: " . $e->getMessage());
    echo "<script>alert('A server error occurred. Please try again.'); window.location='index.php';</script>";
    exit;
}
?>