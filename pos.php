<?php
// ============================================================
//  pos.php  —  Point of Sale
// ============================================================
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once './utils/lhdb.php';

$user_id = (int) $_SESSION['user_id'];

try {
    $pdo  = getPDO();
    $stmt = $pdo->prepare(
        "SELECT product_id, product_name, sku, quantity AS current_stock, retail_price
         FROM   Product
         WHERE  user_id = :user_id
           AND  quantity > 0
           AND  status != 'Expired'
           AND  (expiration_date IS NULL OR expiration_date >= CURDATE())
         ORDER  BY product_name ASC"
    );
    $stmt->execute([':user_id' => $user_id]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("POS fetch error: " . $e->getMessage());
    $products = [];
}

$activePage = 'pos';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Point of Sale – ListaHub</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"/>
  <link rel="stylesheet" href="global_sidebar.css"/>
  <link rel="stylesheet" href="global_pos.css"/>
  <link rel="stylesheet" href="sidebar.css"/>
  <link rel="stylesheet" href="pos.css"/>

  <style>
    .table-wrap {
      overflow-x: auto;
      overflow-y: auto;
    }
    .table-inner {
      max-height: 480px;
      overflow-y: auto;
    }
    #pos-tbody .tbody-row {
      display: flex;
      flex-direction: column;
    }
    #pos-modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.45);
  display: flex; align-items: center; justify-content: center;
  z-index: 9999;
}
#pos-modal-box {
  background: #fff;
  border-radius: 12px;
  padding: 32px 28px 24px;
  min-width: 300px; max-width: 420px;
  text-align: center;
  box-shadow: 0 8px 32px rgba(0,0,0,0.18);
  font-family: inherit;
}
#pos-modal-icon  { font-size: 2.4rem; margin-bottom: 8px; }
#pos-modal-title { font-size: 1.15rem; font-weight: 700; margin-bottom: 8px; color: #1a1a2e; }
#pos-modal-body  { font-size: 0.95rem; color: #444; line-height: 1.6; margin-bottom: 20px; white-space: pre-line; }
#pos-modal-btn {
  background: #4f46e5; color: #fff;
  border: none; border-radius: 8px;
  padding: 10px 32px; font-size: 1rem;
  cursor: pointer; transition: background .2s;
}
#pos-modal-btn:hover { background: #4338ca; }

/* type variants */
#pos-modal-box.success #pos-modal-btn { background: #16a34a; }
#pos-modal-box.success #pos-modal-btn:hover { background: #15803d; }
#pos-modal-box.error   #pos-modal-btn { background: #dc2626; }
#pos-modal-box.error   #pos-modal-btn:hover { background: #b91c1c; }
  </style>
</head>
<body>
<div id="pos-modal-overlay" style="display:none;" onclick="closePosModal()">
  <div id="pos-modal-box" onclick="event.stopPropagation()">
  <div id="pos-modal-icon"></div>
  <div id="pos-modal-title"></div>
  <div id="pos-modal-body"></div>
  <div style="display:flex; gap:10px; justify-content:center;">
    <button id="pos-modal-cancel-btn" onclick="cancelPosModal()" style="display:none; background:#6b7280; color:#fff; border:none; border-radius:8px; padding:10px 32px; font-size:1rem; cursor:pointer;">Cancel</button>
    <button id="pos-modal-btn" onclick="closePosModal()">OK</button>
  </div>
</div>
</div>
<div class="page-wrapper">
  <?php $activePage = 'pos'; include 'sidebar.php'; ?>

  <div class="main-body">

    <!-- ══════════════════════════════
         LEFT PANEL — product table
         ══════════════════════════════ -->
    <div class="overview">
      <h1 class="page-title">Point of Sale</h1>

      <div class="top-actions">
        <button class="btn-clear" id="btn-clear" type="button">clear</button>
        <a href="dashboard.php" class="btn-dashboard">Back to Dashboard</a>
      </div>

      <div class="searchbar">
        <i class="bi bi-search searchbar-icon"></i>
        <input id="search-input" type="text" placeholder="Search for product name / sku" autocomplete="off"/>
      </div>

      <div class="table-section">
        <div class="table-wrap">
          <div class="table-inner">
            <div class="table2">
              <div class="thead">
                <div class="thead-row">
                  <span class="col-name"><b>Product Name</b></span>
                  <span class="col-sku"><b>SKU</b></span>
                  <span class="col-stock"><b>Stock</b></span>
                  <span class="col-qty"><b>Add Qty</b></span>
                  <span class="col-price"><b>Price</b></span>
                  <span class="col-actions"><b>Actions</b></span>
                </div>
                <div class="thead-divider"></div>
              </div>

              <div class="tbody" id="pos-tbody">
                <?php if (empty($products)): ?>
                  <p class="no-products-msg">No products available.</p>
                <?php else: ?>
                  <?php foreach ($products as $p): ?>
                  <div class="tbody-row"
                       data-product-id="<?= (int)$p['product_id'] ?>"
                       data-price="<?= number_format((float)$p['retail_price'], 2, '.', '') ?>"
                       data-stock="<?= (int)$p['current_stock'] ?>"
                       data-hidden="">
                    <div class="tbody-row-content">
                      <span class="col-name col-name-val"><?= htmlspecialchars($p['product_name']) ?></span>
                      <span class="col-sku  col-sku-val"><?= htmlspecialchars($p['sku'] ?? '—') ?></span>
                      <span class="col-stock col-stock-val"><?= number_format((int)$p['current_stock']) ?></span>
                      <div class="col-qty">
                        <div class="qty-control">
                          <button class="qty-btn qty-add"   type="button"><i class="bi bi-plus-circle"></i></button>
                          <span   class="qty-val">0</span>
                          <button class="qty-btn qty-minus" type="button"><i class="bi bi-dash-circle"></i></button>
                        </div>
                      </div>
                      <span class="col-price col-price-val">₱ <?= number_format((float)$p['retail_price'], 2) ?></span>
                      <div class="col-actions actions-cell">
                        <button class="trash-btn" type="button"><i class="bi bi-trash3"></i></button>
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

        <div class="pagination">
          <button class="btn-page" id="btn-prev" type="button">
            <i class="bi bi-arrow-left"></i><span>Prev</span>
          </button>
          <button class="btn-page" id="btn-next" type="button">
            <span>Next</span><i class="bi bi-arrow-right"></i>
          </button>
        </div>
      </div>
    </div><!-- /overview -->

    <!-- ══════════════════════════════
         RIGHT PANEL — checkout
         ══════════════════════════════ -->
    <section class="co">

      <div class="total-view">
        <span class="total-label">TOTAL</span>
        <div class="total-amount-wrapper">
          <h2 class="total-amount" id="display-total">₱ 0.00</h2>
        </div>
      </div>

      <div class="subtotal-panel">
        <div class="subtotal-row">
          <span>Subtotal</span>
          <span id="display-items">( 0 items )</span>
        </div>

        <div class="payment-box">
          <span class="payment-label">Payment method</span>

          <div class="pay-method-btns">
            <button class="btn-cash" id="pay-cash" type="button">Cash</button>
            <div class="pay-other-btns">
              <button class="btn-pay-other" id="pay-gcash"  type="button">G-Cash</button>
              <button class="btn-pay-other" id="pay-utang"  type="button">Utang</button>
            </div>
          </div>

          <span class="amount-tendered-label">Amount Tendered</span>
          <div class="amount-tendered-btns">
            <input class="tendered-input" id="input-tendered" type="number" min="0" step="0.01" placeholder="0.00" value=""/>
            <div class="tendered-quick">
              <button class="btn-quick" type="button" data-amount="100">₱ 100</button>
              <button class="btn-quick" type="button" data-amount="500">₱ 500</button>
              <button class="btn-quick" type="button" data-amount="1000">₱ 1,000</button>
              <button class="btn-exact" type="button" id="btn-exact">Exact</button>
            </div>
          </div>

          <span class="change-label">Change Due</span>
          <div class="change-display" id="display-change">₱ 0.00</div>
        </div>
      </div>

      <div class="checkout-actions">
        <button class="btn-cancel"   id="btn-cancel"   type="button">Cancel</button>
        <button class="btn-checkout" id="btn-checkout" type="button">Check out</button>
      </div>

    </section>

  </div><!-- /main-body -->
</div><!-- /page-wrapper -->


<!-- ════════════════════════════════════════════════════════════
     CUSTOMER INFORMATION MODAL
     Opens when "Utang" payment method is chosen at checkout.
     ════════════════════════════════════════════════════════════ -->
<div class="cust-overlay" id="cust-overlay" role="dialog" aria-modal="true" aria-label="Customer Information">

  <div class="cust-modal">

    <div class="cust-modal-header">
      <h2 class="cust-modal-title">Customer Information</h2>
      <button class="cust-close-btn" id="cust-close-btn" type="button" aria-label="Close">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>

    <div class="cust-modal-body">

      <div class="cust-field">
        <label class="cust-label" for="cust-name">Customer Name</label>
        <div class="cust-input-wrap">
          <input class="cust-input" id="cust-name" type="text"
                 placeholder="Enter Here" autocomplete="off"/>
        </div>
      </div>

      <div class="cust-field">
        <label class="cust-label" for="cust-address">Contact Information</label>
        <div class="cust-input-wrap cust-contact-box">
          <input class="cust-input cust-contact-line" id="cust-address"
                 type="text" placeholder="Enter address here" autocomplete="off"/>
          <div class="cust-contact-divider"></div>
          <input class="cust-input cust-contact-line" id="cust-contact"
                 type="text" placeholder="Enter contact no." autocomplete="off"/>
        </div>
      </div>

      <div class="cust-field">
        <label class="cust-label" for="cust-amount">Amount Owed</label>
        <div class="cust-input-wrap cust-amount-wrap">
          <span class="cust-peso-sign">₱</span>
          <input class="cust-input cust-amount-input" id="cust-amount"
                 type="number" min="0" step="0.01" placeholder="0.00" readonly/>
        </div>
      </div>

      <div class="cust-field">
        <label class="cust-label" for="cust-notes">Additional Notes</label>
        <div class="cust-input-wrap">
          <textarea class="cust-input cust-textarea" id="cust-notes"
                    placeholder="Enter Here" rows="3"></textarea>
        </div>
      </div>

      <p class="cust-error" id="cust-error"></p>

      <div class="cust-modal-footer">
        <button class="cust-btn-cancel"   id="cust-btn-cancel"   type="button">Cancel</button>
        <button class="cust-btn-complete" id="cust-btn-complete" type="button">Complete</button>
      </div>

    </div><!-- /cust-modal-body -->
  </div><!-- /cust-modal -->
</div><!-- /cust-overlay -->


<script>
'use strict';

/* ─────────────────────────────────────────────────────────────
   Constants & State
───────────────────────────────────────────────────────────── */
var payMethod   = 'cash';
var currentPage = 1;
var ROWS_PER_PAGE = 10;

var ROW_DISPLAY_SHOW = 'flex';

var tbody         = document.getElementById('pos-tbody');
var searchInput   = document.getElementById('search-input');   // FIX 1: keep a ref
var displayTotal  = document.getElementById('display-total');
var displayItems  = document.getElementById('display-items');
var inputTendered = document.getElementById('input-tendered');
var displayChange = document.getElementById('display-change');
var custOverlay   = document.getElementById('cust-overlay');

/* ─────────────────────────────────────────────────────────────
   Helpers
───────────────────────────────────────────────────────────── */
function fmt(n)        { return '₱ ' + n.toFixed(2); }
function getTendered() { return Math.max(0, parseFloat(inputTendered.value) || 0); }

function getTotal() {
  var t = 0;
  tbody.querySelectorAll('.tbody-row').forEach(function(row) {
    t += (parseFloat(row.dataset.price) || 0) *
         (parseInt(row.querySelector('.qty-val').textContent, 10) || 0);
  });
  return t;
}

function getItemCount() {
  var c = 0;
  tbody.querySelectorAll('.tbody-row').forEach(function(row) {
    c += parseInt(row.querySelector('.qty-val').textContent, 10) || 0;
  });
  return c;
}

function updateSummary() {
  var total    = getTotal();
  var items    = getItemCount();
  var tendered = getTendered();
  displayTotal.textContent  = fmt(total);
  displayItems.textContent  = '( ' + items + ' item' + (items !== 1 ? 's' : '') + ' )';
  displayChange.textContent = fmt(Math.max(0, tendered - total));
}

/* ─────────────────────────────────────────────────────────────
   Table interactions (qty stepper + trash)
───────────────────────────────────────────────────────────── */
tbody.addEventListener('click', function(e) {
  var addBtn   = e.target.closest('.qty-add');
  var minusBtn = e.target.closest('.qty-minus');
  var trashBtn = e.target.closest('.trash-btn');

  if (addBtn) {
    var row   = addBtn.closest('.tbody-row');
    var valEl = row.querySelector('.qty-val');
    var stock = parseInt(row.dataset.stock, 10) || 0;
    var qty   = parseInt(valEl.textContent, 10) || 0;
    if (qty < stock) { valEl.textContent = qty + 1; updateSummary(); }
  }
  if (minusBtn) {
    var row   = minusBtn.closest('.tbody-row');
    var valEl = row.querySelector('.qty-val');
    var qty   = parseInt(valEl.textContent, 10) || 0;
    if (qty > 0) { valEl.textContent = qty - 1; updateSummary(); }
  }
  if (trashBtn) {
    var row = trashBtn.closest('.tbody-row');
    row.querySelector('.qty-val').textContent = '0';
    row.dataset.hidden = '1';
    updateSummary();
    renderPage();
  }
});

inputTendered.addEventListener('input', updateSummary);

/* ─────────────────────────────────────────────────────────────
   Clear
   FIX 1: also clear the search input value and reset all
   data-hidden flags so previously search-filtered rows reappear.
───────────────────────────────────────────────────────────── */
document.getElementById('btn-clear').addEventListener('click', function() {
  // Reset all rows — qty back to 0, visibility restored
  tbody.querySelectorAll('.tbody-row').forEach(function(row) {
    row.querySelector('.qty-val').textContent = '0';
    row.dataset.hidden = '';   // restore all rows (including trash-removed ones)
  });

  // FIX 1: clear the search box so the filter is fully reset
  searchInput.value = '';

  inputTendered.value = '';
  currentPage = 1;
  updateSummary();
  renderPage();
});

/* ─────────────────────────────────────────────────────────────
   Payment method toggle
───────────────────────────────────────────────────────────── */
function selectPayMethod(method) {
  payMethod = method;
  document.getElementById('pay-cash').classList.toggle('active',  method === 'cash');
  document.getElementById('pay-gcash').classList.toggle('active', method === 'gcash');
  document.getElementById('pay-utang').classList.toggle('active', method === 'utang');

  var tenderedSection = inputTendered.closest('.amount-tendered-btns');
  var tenderedLabel   = document.querySelector('.amount-tendered-label');
  var changeLabel     = document.querySelector('.change-label');
  var changeDisplay   = document.getElementById('display-change');

  if (method === 'utang') {
    tenderedSection.style.display = 'none';
    tenderedLabel.style.display   = 'none';
    changeLabel.style.display     = 'none';
    changeDisplay.style.display   = 'none';
    inputTendered.value = '';
  } else {
    tenderedSection.style.display = '';
    tenderedLabel.style.display   = '';
    changeLabel.style.display     = '';
    changeDisplay.style.display   = '';
  }
  updateSummary();
}

document.getElementById('pay-cash').addEventListener('click',  function() { selectPayMethod('cash'); });
document.getElementById('pay-gcash').addEventListener('click', function() { selectPayMethod('gcash'); });
document.getElementById('pay-utang').addEventListener('click', function() { selectPayMethod('utang'); });

/* ─────────────────────────────────────────────────────────────
   Tendered quick-fill buttons
───────────────────────────────────────────────────────────── */
document.querySelectorAll('.btn-quick').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var current = parseFloat(inputTendered.value) || 0;
    inputTendered.value = (current + (parseFloat(this.dataset.amount) || 0)).toFixed(2);
    updateSummary();
  });
});

document.getElementById('btn-exact').addEventListener('click', function() {
  inputTendered.value = getTotal().toFixed(2);
  updateSummary();
});

/* ─────────────────────────────────────────────────────────────
   Reset POS
───────────────────────────────────────────────────────────── */
function resetPOS() {
  tbody.querySelectorAll('.tbody-row').forEach(function(row) {
    row.querySelector('.qty-val').textContent = '0';
    row.dataset.hidden = '';
  });
  // FIX 1: clear search on full reset too
  searchInput.value = '';
  inputTendered.value = '';
  payMethod = 'cash';
  selectPayMethod('cash');
  currentPage = 1;
  updateSummary();
  renderPage();
}

document.getElementById('btn-cancel').addEventListener('click', resetPOS);

/* ─────────────────────────────────────────────────────────────
   Build cart
───────────────────────────────────────────────────────────── */
function buildCart() {
  var items = [];
  tbody.querySelectorAll('.tbody-row').forEach(function(row) {
    var qty = parseInt(row.querySelector('.qty-val').textContent, 10) || 0;
    if (qty > 0) {
      items.push({
        product_id: parseInt(row.dataset.productId, 10),
        qty:        qty,
        price:      parseFloat(row.dataset.price)
      });
    }
  });
  return items;
}

/* ─────────────────────────────────────────────────────────────
   Checkout button
───────────────────────────────────────────────────────────── */
document.getElementById('btn-checkout').addEventListener('click', function() {
  var total    = getTotal();
  var items    = getItemCount();
  var tendered = getTendered();

if (items === 0) {
    showPosModal('warning', 'Empty Cart', 'No items added to the cart.');
    return;
  }
  if (payMethod !== 'utang' && tendered < total) {
    showPosModal('warning', 'Insufficient Amount',
      'Yung pera mo na (₱' + tendered.toFixed(2) + ') ay kulang sa amount ng mga binili mo (₱' + total.toFixed(2) + ').');
    return;
  }

  if (payMethod === 'utang') {
    openCustModal();
  } else {
    var change = Math.max(0, tendered - total);
    var payLabel = payMethod === 'gcash' ? 'G-CASH' : 'CASH';
    var msg = [
      'TOTAL:    ₱' + total.toFixed(2),
      'ITEMS:    ' + items,
      'PAYMENT:  ' + payLabel,
      'TENDERED: ₱' + tendered.toFixed(2),
      'CHANGE:   ₱' + change.toFixed(2),
      '', 'Proceed with checkout?'
    ].join('\n');
    showPosModal('info', 'Confirm Checkout', msg, function() {
    submitCashSale(total, tendered, change);
    });
  }
});

/* ─────────────────────────────────────────────────────────────
   Cash / G-Cash sale submission
   FIX 2: pass pay_method ('cash' or 'gcash') to the server
   so it can be stored separately in the Sale table.
───────────────────────────────────────────────────────────── */
function submitCashSale(total, tendered, change) {
  var cartItems   = buildCart();
  var btnCheckout = document.getElementById('btn-checkout');
  btnCheckout.disabled    = true;
  btnCheckout.textContent = 'Processing…';

  fetch('process_sale.php', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({
      type:       'cash',
      pay_method: payMethod,   // 'cash' or 'gcash' — server stores this
      items:      cartItems,
      total:      total,
      tendered:   tendered,
      change:     change
    })
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    btnCheckout.disabled    = false;
    btnCheckout.textContent = 'Check out';
    if (data.success) {
      showPosModal('success', 'Transaction Complete!',
        'Receipt #' + data.sale_id + '\nChange: ₱' + change.toFixed(2),
        function() { window.location.reload(); });
    } else {
      showPosModal('error', 'Something went wrong', data.message || 'Unknown error.');
    }
  })
  .catch(function(err) {
    btnCheckout.disabled    = false;
    btnCheckout.textContent = 'Check out';
    showPosModal('error', 'Network Error', 'Please try again.\n' + err.message);
  });
}

/* ─────────────────────────────────────────────────────────────
   Customer Information Modal
───────────────────────────────────────────────────────────── */
function openCustModal() {
  document.getElementById('cust-name').value    = '';
  document.getElementById('cust-address').value = '';
  document.getElementById('cust-contact').value = '';
  document.getElementById('cust-notes').value   = '';
  document.getElementById('cust-error').textContent = '';
  document.getElementById('cust-amount').value  = getTotal().toFixed(2);

  custOverlay.classList.add('open');
  document.body.style.overflow = 'hidden';
  setTimeout(function() { document.getElementById('cust-name').focus(); }, 120);
}

function closeCustModal() {
  custOverlay.classList.remove('open');
  document.body.style.overflow = '';
}

document.getElementById('cust-close-btn').addEventListener('click',  closeCustModal);
document.getElementById('cust-btn-cancel').addEventListener('click',  closeCustModal);
custOverlay.addEventListener('click', function(e) {
  if (e.target === custOverlay) closeCustModal();
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && custOverlay.classList.contains('open')) closeCustModal();
});

document.getElementById('cust-btn-complete').addEventListener('click', function() {
  var name    = document.getElementById('cust-name').value.trim();
  var address = document.getElementById('cust-address').value.trim();
  var contact = document.getElementById('cust-contact').value.trim();
  var notes   = document.getElementById('cust-notes').value.trim();
  var errEl   = document.getElementById('cust-error');

  if (!name || !address || !contact) {
    errEl.textContent = 'Customer Name, Address, and Contact Number are required.';
    return;
  }
  errEl.textContent = '';
  closeCustModal();
  submitUtangSale(name, address, contact, notes);
});

/* ─────────────────────────────────────────────────────────────
   Utang / credit sale submission
───────────────────────────────────────────────────────────── */
function submitUtangSale(custName, custAddress, custContact, custNotes) {
  var total       = getTotal();
  var cartItems   = buildCart();
  var btnCheckout = document.getElementById('btn-checkout');
  btnCheckout.disabled    = true;
  btnCheckout.textContent = 'Processing…';

  fetch('process_sale.php', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({
      type:             'credit',
      customer_name:    custName,
      customer_contact: custContact,
      customer_address: custAddress,
      customer_notes:   custNotes,
      items:            cartItems,
      total:            total
    })
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    btnCheckout.disabled    = false;
    btnCheckout.textContent = 'Check out';
    if (data.success) {
      showPosModal('success', 'Utang Recorded!',
        'Receipt #' + data.sale_id + '\nCustomer: ' + custName + '\nAmount: ₱' + total.toFixed(2),
        function() { window.location.href = 'customers.php'; });
      resetPOS();
    } else {
      showPosModal('error', 'Something went wrong', data.message || 'Unknown error.');
    }
  })
  .catch(function(err) {
    btnCheckout.disabled    = false;
    btnCheckout.textContent = 'Check out';
    showPosModal('error', 'Network Error', 'Please try again.\n' + err.message);
  });
}

/* ─────────────────────────────────────────────────────────────
   Search filter
───────────────────────────────────────────────────────────── */
searchInput.addEventListener('input', function() {
  var q = this.value.toLowerCase().trim();
  tbody.querySelectorAll('.tbody-row').forEach(function(row) {
    var name = (row.querySelector('.col-name-val') || {}).textContent || '';
    var sku  = (row.querySelector('.col-sku-val')  || {}).textContent || '';
    if (row.dataset.hidden === '1') return;   // already trash-removed, skip
    row.dataset.hidden = (q && !name.toLowerCase().includes(q) && !sku.toLowerCase().includes(q)) ? 'search' : '';
  });
  currentPage = 1;
  renderPage();
});

/* ─────────────────────────────────────────────────────────────
   Pagination
───────────────────────────────────────────────────────────── */
function renderPage() {
  var allRows = Array.from(tbody.querySelectorAll('.tbody-row'));

  var visible = allRows.filter(function(r) {
    return r.dataset.hidden === '';
  });

  var total   = visible.length;
  var maxPage = Math.max(1, Math.ceil(total / ROWS_PER_PAGE));

  if (currentPage < 1)       currentPage = 1;
  if (currentPage > maxPage) currentPage = maxPage;

  var start = (currentPage - 1) * ROWS_PER_PAGE;
  var end   = start + ROWS_PER_PAGE;

  allRows.forEach(function(r) {
    r.style.display = 'none';
  });

  visible.forEach(function(r, i) {
    if (i >= start && i < end) {
      r.style.display = ROW_DISPLAY_SHOW;
    }
  });

  var btnPrev = document.getElementById('btn-prev');
  var btnNext = document.getElementById('btn-next');
  btnPrev.disabled = (currentPage <= 1);
  btnNext.disabled = (currentPage >= maxPage);
}

function showPosModal(type, title, body, onClose) {
  var icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
  var box = document.getElementById('pos-modal-box');
  box.className = type;
  document.getElementById('pos-modal-icon').textContent  = icons[type] || '';
  document.getElementById('pos-modal-title').textContent = title;
  document.getElementById('pos-modal-body').textContent  = body;
  box._onClose = onClose || null;

  // show Cancel button only on confirmation dialogs (those with an onClose callback)
  document.getElementById('pos-modal-cancel-btn').style.display = onClose ? 'inline-block' : 'none';

  document.getElementById('pos-modal-overlay').style.display = 'flex';
}

function closePosModal() {
  document.getElementById('pos-modal-overlay').style.display = 'none';
  var box = document.getElementById('pos-modal-box');
  if (typeof box._onClose === 'function') {
    box._onClose();
    box._onClose = null;
  }
}

function cancelPosModal() {
  document.getElementById('pos-modal-overlay').style.display = 'none';
  var box = document.getElementById('pos-modal-box');
  box._onClose = null;  // discard callback — sale does NOT proceed
}

document.getElementById('btn-prev').addEventListener('click', function() {
  if (currentPage > 1) {
    currentPage--;
    renderPage();
  }
});

document.getElementById('btn-next').addEventListener('click', function() {
  var allRows = Array.from(tbody.querySelectorAll('.tbody-row'));
  var visible = allRows.filter(function(r) { return r.dataset.hidden === ''; });
  var maxPage = Math.max(1, Math.ceil(visible.length / ROWS_PER_PAGE));
  if (currentPage < maxPage) {
    currentPage++;
    renderPage();
  }
});

/* ─────────────────────────────────────────────────────────────
   Init
───────────────────────────────────────────────────────────── */
selectPayMethod('cash');
updateSummary();
renderPage();
</script>
</body>
</html>