<?php
// ============================================================
//  signup.php
//  Requirements met:
//   ✅ PDO prepared statements (no variable interpolation)
//   ✅ password_hash()
//   ✅ Transaction (BEGIN / COMMIT / ROLLBACK)
//   ✅ try-catch (no sensitive info exposed on failure)
//   ✅ Centralized db_config.php
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', 0); // OFF in production – log privately instead

session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

// Only process on POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: signup.php");
    exit;
}

require_once './utils/lhdb.php';

// ── 1. Collect & sanitize inputs ────────────────────────────
$username    = trim($_POST["username"]    ?? '');
$email       = trim($_POST["email"]       ?? '');
$password    =      $_POST["password"]    ?? '';
$confirm     =      $_POST["confirm"]     ?? '';
$store_name  = trim($_POST["store_name"]  ?? '');
$terms       =      $_POST["terms"]       ?? null;

// ── 2. Basic validation ──────────────────────────────────────
if (!$terms) {
    echo "<script>alert('You must agree to the terms and conditions.'); window.history.back();</script>";
    exit;
}

if (empty($username) || empty($email) || empty($password) || empty($store_name)) {
    echo "<script>alert('All fields are required.'); window.history.back();</script>";
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "<script>alert('Invalid email address.'); window.history.back();</script>";
    exit;
}

if (strlen($password) < 8) {
    echo "<script>alert('Password must be at least 8 characters.'); window.history.back();</script>";
    exit;
}

if ($password !== $confirm) {
    echo "<script>alert('Passwords do not match.'); window.history.back();</script>";
    exit;
}

// ── 3. Database operations ───────────────────────────────────
try {
    $pdo = getPDO();

    // ── 3a. Check for duplicate username or email (Prepared Statement) ──
    $checkStmt = $pdo->prepare(
        "SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1"
    );
    $checkStmt->execute([
        ':username' => $username,
        ':email'    => $email,
    ]);

    if ($checkStmt->fetch()) {
        echo "<script>alert('Username or email is already taken.'); window.history.back();</script>";
        exit;
    }

    // ── 3b. Hash password ──────────────────────────────────────
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // ── 3c. Transaction: insert user + store atomically ────────
    //  Requirement: Transactions (BEGIN / COMMIT / ROLLBACK)
    //  If inserting the store fails after the user is created,
    //  the whole signup is rolled back — no orphan records.
    $pdo->beginTransaction();

    // Insert into users table
    $userStmt = $pdo->prepare(
        "INSERT INTO users (username, email, password, created_at)
         VALUES (:username, :email, :password, NOW())"
    );
    $userStmt->execute([
        ':username' => $username,
        ':email'    => $email,
        ':password' => $hashedPassword,
    ]);

    $newUserId = (int) $pdo->lastInsertId();

    // Insert into stores table (ties store to this user)
    $storeStmt = $pdo->prepare(
        "INSERT INTO stores (user_id, store_name, created_at)
         VALUES (:user_id, :store_name, NOW())"
    );
    $storeStmt->execute([
        ':user_id'    => $newUserId,
        ':store_name' => $store_name,
    ]);

    $newStoreId = (int) $pdo->lastInsertId();

    $pdo->commit(); // ✅ Both inserts succeeded

    // ── 3d. Set session & redirect ─────────────────────────────
    $_SESSION['user_id']    = $newUserId;
    $_SESSION['username']   = $username;
    $_SESSION['email']      = $email;
    $_SESSION['store_id']   = $newStoreId;
    $_SESSION['store_name'] = $store_name;

    header("Location: dashboard.php");
    exit;

} catch (PDOException $e) {
    // ── ROLLBACK on any DB error ───────────────────────────────
    if ($pdo->inTransaction()) {
        $pdo->rollBack(); // ❌ Undo partial inserts
    }

    // Log privately, never show raw error to user
    error_log("Signup error: " . $e->getMessage());
    echo "<script>alert('Registration failed due to a server error. Please try again.'); window.history.back();</script>";
    exit;
}
?>