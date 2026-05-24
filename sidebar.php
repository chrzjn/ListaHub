<?php
// sidebar.php — shared sidebar include
// Usage: include 'sidebar.php'; (after session_start and auth check)
// Set $activePage before including:
//   'dashboard' | 'pos' | 'manage-products' | 'restock' | 'inventory-history' | 'sales' | 'customers'
$activePage = $activePage ?? '';

// Fetch current store_type if not in session
if (empty($_SESSION['store_type']) && !empty($_SESSION['user_id'])) {
    try {
        require_once './utils/lhdb.php';
        $pdo_sb = getPDO();
        $st = $pdo_sb->prepare("SELECT store_type FROM User WHERE user_id = :uid");
        $st->execute([':uid' => (int)$_SESSION['user_id']]);
        $row_st = $st->fetch();
        $_SESSION['store_type'] = $row_st['store_type'] ?? 'Sari-Sari Store';
    } catch (Exception $e) {
        $_SESSION['store_type'] = 'Sari-Sari Store';
    }
}
$current_store_type = $_SESSION['store_type'] ?? 'Sari-Sari Store';
$preset_types = ['Sari-Sari Store', 'Mini Grocery', 'Convenience Store', 'General Merchandise'];
$is_other = !in_array($current_store_type, $preset_types);
?>
<aside class="sidebar">

  <!-- ===== LOGO ===== -->
  <section class="logos">
    <!--
      TODO: Replace src with your ListaHub logo image path.
      e.g. src="pics_icons/ListaHub-logo-2-1.png"
    -->
    <img
      class="listahub-logo"
      alt="ListaHub"
      src="pics_icons/ListaHub-logo-2-1.png"
    />
  </section>

  <div class="sidebar-main">
    <!-- ===== DASHBOARD ===== -->
    <a href="dashboard.php" class="menus2 <?= $activePage === 'dashboard' ? 'active' : '' ?>">
      <!--
        TODO: Replace src with your home/dashboard icon.
        e.g. src="pics_icons/home-page.png"
      -->
      <img class="menu-icon" alt="" src="pics_icons/home-page.png" />
      <span class="menu">Dashboard</span>
    </a>

    <!-- ===== POS ===== -->
    <a href="pos.php" class="menus2 <?= $activePage === 'pos' ? 'active' : '' ?>">
      <!--
        TODO: Replace src with your POS/cashier icon.
        e.g. src="pics_icons/cash-register (2).svg"
      -->
      <img class="menu-icon" alt="" src="pics_icons/cash-register (2).svg" />
      <span class="menu">POS</span>
    </a>

    <!-- ===== INVENTORY ===== -->
    <section class="inventory-container">
      <div class="inventory-label">INVENTORY</div>

      <a href="manage-products.php" class="menus2 <?= $activePage === 'manage-products' ? 'active' : '' ?>">
        <!--
          TODO: Replace src with your manage-products icon.
          e.g. src="pics_icons/inventory-alt (1).svg"
        -->
        <img class="menu-icon" alt="" src="pics_icons/inventory-alt (1).svg" />
        <span class="menu">Manage Products</span>
      </a>

      <a href="restock.php" class="menus2 <?= $activePage === 'restock' ? 'active' : '' ?>">
        <!--
          TODO: Replace src with your restock icon.
          e.g. src="pics_icons/box-add (1).svg"
        -->
        <img class="menu-icon" alt="" src="pics_icons/box-add (1).svg" />
        <span class="menu">Restock</span>
      </a>

      <!-- ★ NEW: Inventory History ★ -->
      <a href="inv_history.php" class="menus2 <?= $activePage === 'inv_history' ? 'active' : '' ?>">
        <!--
          TODO: Replace src with your inventory-history / clock-history icon.
          e.g. src="pics_icons/material-symbols-history-rounded.svg"
        -->
        <img class="menu-icon" alt="" src="pics_icons/history.png" />
        <span class="menu">Inventory History</span>
      </a>
    </section>

    <!-- ===== SALES ===== -->
    <section class="inventory-container">
      <div class="inventory-label">SALES</div>

      <a href="sales.php" class="menus2 <?= $activePage === 'sales' ? 'active' : '' ?>">
        <!--
          TODO: Replace src with your sales/money icon.
          e.g. src="pics_icons/money (2).png"
        -->
        <img class="menu-icon" alt="" src="pics_icons/money (2).png" />
        <span class="menu">Sales Analytics</span>
      </a>
    </section>

    <!-- ===== CUSTOMER CREDIT ===== -->
    <section class="inventory-container">
      <div class="inventory-label">CUSTOMER CREDIT</div>

      <a href="customers.php" class="menus2 <?= $activePage === 'customers' ? 'active' : '' ?>">
        <!--
          TODO: Replace src with your credit-card icon.
          e.g. src="pics_icons/credit-card-buyer.svg"
        -->
        <img class="menu-icon" alt="" src="pics_icons/credit-card-buyer.svg" />
        <span class="menu">Manage Customers</span>
      </a>
    </section>
  </div>

<!-- ===== USER / LOGOUT ===== -->
  <section class="logout-container">
    <div class="owner-container">
      <!-- Clicking the card opens the edit profile modal -->
      <div class="owner-card" onclick="openProfileModal()" title="Edit profile" style="cursor:pointer;">
        <div class="image-parent">
          <div class="image"></div>
          <img class="store-icon" alt="" src="pics_icons/store.png" />
        </div>
        <div class="owner-details">
          <div class="owner-name" id="sidebar-username"><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></div>
          <div class="owner-store" id="sidebar-storename"><?= htmlspecialchars($_SESSION['store_name'] ?? '') ?></div>
          <div style="
            font-size:11px;
            font-weight:500;
            letter-spacing:-0.03em;
            color:rgba(62,44,35,0.5);
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
            width:100%;
          " id="sidebar-storetype"><?= htmlspecialchars($current_store_type) ?></div>
        </div>
        <!-- Edit pencil icon -->
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
             fill="none" stroke="rgba(62,44,35,0.5)" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
          <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
          <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
        </svg>
      </div>
    </div>
    <form method="post" action="logout.php" style="align-self:stretch;">
      <button type="submit" class="menus7">
        <img class="menu-icon" alt="" src="pics_icons/exit.svg" />
        <span class="menu6">Log out</span>
      </button>
    </form>
  </section>

</aside>

<!-- ============================================================
     EDIT PROFILE MODAL
     Lives outside <aside> so it overlays the full page.
     Works on every page that includes sidebar.php.
     ============================================================ -->
<div id="profile-modal-overlay" style="
  display:none; position:fixed; inset:0; z-index:2000;
  background:rgba(0,0,0,0.35);
  align-items:center; justify-content:center;">

  <div style="
    background: linear-gradient(146deg, rgba(253,253,253,0.95), rgba(254,246,227,0.95));
    border: 2px solid #3e2c23;
    border-radius: 15px;
    padding: 24px 20px 20px;
    width: 100%;
    max-width: 400px;
    box-shadow: 6px 5px 8px rgba(62,44,35,0.09), 1px 1px 4px rgba(62,44,35,0.1);
    font-family: Inter, sans-serif;
    box-sizing: border-box;
    position: relative;">

    <!-- Header -->
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
      <h2 style="margin:0; font-size:22px; font-weight:800; letter-spacing:-0.04em; color:#3e2c23;">
        Edit Profile
      </h2>
      <button onclick="closeProfileModal()" style="
        background:transparent; border:none; cursor:pointer;
        font-size:18px; color:#3e2c23; width:32px; height:32px;
        border-radius:50%; display:flex; align-items:center; justify-content:center;">
        &#x2715;
      </button>
    </div>

    <!-- Flash messages -->
    <?php if (!empty($_GET['profile_error'])): ?>
      <div style="background:#f8d7da; color:#721c24; border:1px solid #f5c6cb;
                  border-radius:8px; padding:8px 12px; font-size:13px; margin-bottom:12px;">
        <?= htmlspecialchars($_GET['profile_error']) ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($_GET['profile_success'])): ?>
      <div style="background:#d4edda; color:#155724; border:1px solid #c3e6cb;
                  border-radius:8px; padding:8px 12px; font-size:13px; margin-bottom:12px;">
        Profile updated successfully!
      </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="post" action="update_profile.php">
      <!-- Pass current page so we redirect back here after update -->
      <input type="hidden" name="redirect" value="<?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>"/>

      <!-- Username -->
      <div style="display:flex; flex-direction:column; gap:4px; margin-bottom:12px;">
        <label style="font-size:14px; font-weight:600; color:#3e2c23; letter-spacing:-0.03em;">
          Username
        </label>
        <input
          type="text"
          name="username"
          value="<?= htmlspecialchars($_SESSION['username'] ?? '') ?>"
          required
          autocomplete="off"
          style="
            height:41px; border-radius:10px;
            background:rgba(253,243,219,0.55);
            border:1px solid rgba(62,44,35,0.3);
            padding:0 14px; font-size:15px;
            font-family:Inter,sans-serif;
            color:rgba(43,43,43,0.85);
            outline:none; box-sizing:border-box; width:100%;"
        />
      </div>

      <!-- Store Name -->
      <div style="display:flex; flex-direction:column; gap:4px; margin-bottom:12px;">
        <label style="font-size:14px; font-weight:600; color:#3e2c23; letter-spacing:-0.03em;">
          Store Name
        </label>
        <input
          type="text"
          name="store_name"
          value="<?= htmlspecialchars($_SESSION['store_name'] ?? '') ?>"
          required
          autocomplete="off"
          style="
            height:41px; border-radius:10px;
            background:rgba(253,243,219,0.55);
            border:1px solid rgba(62,44,35,0.3);
            padding:0 14px; font-size:15px;
            font-family:Inter,sans-serif;
            color:rgba(43,43,43,0.85);
            outline:none; box-sizing:border-box; width:100%;"
        />
      </div>

      <!-- Store Type -->
      <div style="display:flex; flex-direction:column; gap:4px; margin-bottom:20px;">
        <label style="font-size:14px; font-weight:600; color:#3e2c23; letter-spacing:-0.03em;">
          Store Type
        </label>
        <select
          name="store_type"
          id="profile-store-type"
          onchange="toggleOtherStoreType(this)"
          required
          style="
            height:41px; border-radius:10px;
            background:rgba(253,243,219,0.55);
            border:1px solid rgba(62,44,35,0.3);
            padding:0 14px; font-size:15px;
            font-family:Inter,sans-serif;
            color:rgba(43,43,43,0.85);
            outline:none; box-sizing:border-box; width:100%;
            cursor:pointer; appearance:auto;">
          <option value="Sari-Sari Store"      <?= $current_store_type === 'Sari-Sari Store'      ? 'selected' : '' ?>>Sari-Sari Store</option>
          <option value="Mini Grocery"          <?= $current_store_type === 'Mini Grocery'          ? 'selected' : '' ?>>Mini Grocery</option>
          <option value="Convenience Store"     <?= $current_store_type === 'Convenience Store'     ? 'selected' : '' ?>>Convenience Store</option>
          <option value="General Merchandise"   <?= $current_store_type === 'General Merchandise'   ? 'selected' : '' ?>>General Merchandise</option>
          <option value="Other"                 <?= $is_other                                       ? 'selected' : '' ?>>Other</option>
        </select>

        <!-- "Other" text input — shown only when Other is selected -->
        <input
          type="text"
          name="store_type_other"
          id="profile-store-type-other"
          placeholder="Enter your store type"
          value="<?= $is_other ? htmlspecialchars($current_store_type) : '' ?>"
          autocomplete="off"
          style="
            display: <?= $is_other ? 'block' : 'none' ?>;
            margin-top:8px;
            height:41px; border-radius:10px;
            background:rgba(253,243,219,0.55);
            border:1px solid rgba(62,44,35,0.3);
            padding:0 14px; font-size:15px;
            font-family:Inter,sans-serif;
            color:rgba(43,43,43,0.85);
            outline:none; box-sizing:border-box; width:100%;"
        />
      </div>

      <!-- Footer -->
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" onclick="closeProfileModal()" style="
          background:transparent; border:none; cursor:pointer;
          font-family:Inter,sans-serif; font-size:15px; font-weight:500;
          color:#3e2c23; padding:10px 20px; border-radius:231px;">
          Cancel
        </button>
        <button type="submit" style="
          background:rgba(235,214,101,0.66);
          border:2px solid #3e2c23;
          border-radius:231px;
          padding:10px 24px;
          font-family:Inter,sans-serif;
          font-size:15px; font-weight:500;
          color:#3e2c23; cursor:pointer;">
          Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleOtherStoreType(select) {
  var otherInput = document.getElementById('profile-store-type-other');
  if (select.value === 'Other') {
    otherInput.style.display = 'block';
    otherInput.required = true;
  } else {
    otherInput.style.display = 'none';
    otherInput.required = false;
    otherInput.value = '';
  }
}
function openProfileModal() {
  var overlay = document.getElementById('profile-modal-overlay');
  overlay.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeProfileModal() {
  var overlay = document.getElementById('profile-modal-overlay');
  overlay.style.display = 'none';
  document.body.style.overflow = '';
}
// Close on backdrop click
document.getElementById('profile-modal-overlay').addEventListener('click', function(e) {
  if (e.target === this) closeProfileModal();
});
// Auto-open only on error — success closes and shows a toast instead
<?php if (!empty($_GET['profile_error'])): ?>
openProfileModal();
<?php endif; ?>
<?php if (!empty($_GET['profile_success'])): ?>
// Show a brief success toast without reopening the modal
(function() {
  var toast = document.createElement('div');
  toast.textContent = 'Profile updated successfully!';
  toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:3000;'
    + 'background:#159459;color:#fff;font-family:Inter,sans-serif;'
    + 'font-size:14px;font-weight:500;padding:12px 20px;'
    + 'border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,0.15);';
  document.body.appendChild(toast);
  setTimeout(function() {
    toast.style.transition = 'opacity 0.4s';
    toast.style.opacity = '0';
    setTimeout(function() { toast.remove(); }, 400);
  }, 3000);
})();
<?php endif; ?>
// ESC closes
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeProfileModal();
});
</script>

</aside>