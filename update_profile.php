<?php
// ============================================================
//  update_profile.php
//  Handles username + store_name updates from the sidebar modal.
//  Called via POST from any page that includes sidebar.php.
// ============================================================
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once './utils/lhdb.php';

$user_id  = (int) $_SESSION['user_id'];
$redirect = $_POST['redirect'] ?? 'dashboard.php';

// Sanitize redirect — only allow relative .php pages
if (!preg_match('/^[a-zA-Z0-9_\-]+\.php$/', $redirect)) {
    $redirect = 'dashboard.php';
}

$new_username   = trim($_POST['username']   ?? '');
$new_store_name = trim($_POST['store_name'] ?? '');
$store_type_raw = trim($_POST['store_type'] ?? '');
$store_type_other = trim($_POST['store_type_other'] ?? '');

$allowed_types = ['Sari-Sari Store', 'Mini Grocery', 'Convenience Store', 'General Merchandise', 'Other'];
$new_store_type = in_array($store_type_raw, $allowed_types)
    ? ($store_type_raw === 'Other' ? $store_type_other : $store_type_raw)
    : 'Sari-Sari Store';

if (empty($new_username) || empty($new_store_name) || empty($new_store_type)) {
    header("Location: {$redirect}?profile_error=Fields+cannot+be+empty");
    exit;
}

try {
    $pdo = getPDO();

    // Check if username is taken by another user
    $check = $pdo->prepare(
        "SELECT user_id FROM User WHERE username = :username AND user_id != :user_id LIMIT 1"
    );
    $check->execute([':username' => $new_username, ':user_id' => $user_id]);

    if ($check->fetch()) {
        header("Location: {$redirect}?profile_error=Username+already+taken");
        exit;
    }

    $upd = $pdo->prepare(
        "UPDATE User SET username = :username, store_name = :store_name,
         store_type = :store_type WHERE user_id = :user_id"
    );
    $upd->execute([
        ':username'   => $new_username,
        ':store_name' => $new_store_name,
        ':store_type' => $new_store_type,
        ':user_id'    => $user_id,
    ]);

    // Update session so sidebar reflects changes immediately
    $_SESSION['username']   = $new_username;
    $_SESSION['store_name'] = $new_store_name;
    $_SESSION['store_type'] = $new_store_type;
    header("Location: {$redirect}?profile_success=1");
    exit;

} catch (PDOException $e) {
    error_log("Profile update error: " . $e->getMessage());
    header("Location: {$redirect}?profile_error=Database+error+please+try+again");
    exit;
}