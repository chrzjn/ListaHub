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
</head>
<body>

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
          <div class="table-scroll-indicator"></div>
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
     All JS wiring is below in the <script> block.
     ════════════════════════════════════════════════════════════ -->
<div class="cust-overlay" id="cust-overlay" role="dialog" aria-modal="true" aria-label="Customer Information">

  <div class="cust-modal">

    <!-- Header -->
    <div class="cust-modal-header">
      <h2 class="cust-modal-title">Customer Information</h2>
      <button class="cust-close-btn" id="cust-close-btn" type="button" aria-label="Close">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>

    <!-- Body / form fields -->
    <div class="cust-modal-body">

      <!-- Customer Name -->
      <div class="cust-field">
        <label class="cust-label" for="cust-name">Customer Name</label>
        <div class="cust-input-wrap">
          <input class="cust-input" id="cust-name" type="text"
                 placeholder="Enter Here" autocomplete="off"/>
        </div>
      </div>

      <!-- Contact Information — address + contact stacked with divider -->
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

      <!-- Amount Owed — readonly, filled by JS from cart total -->
      <div class="cust-field">
        <label class="cust-label" for="cust-amount">Amount Owed</label>
        <div class="cust-input-wrap cust-amount-wrap">
          <span class="cust-peso-sign">₱</span>
          <input class="cust-input cust-amount-input" id="cust-amount"
                 type="number" min="0" step="0.01" placeholder="0.00" readonly/>
        </div>
      </div>

      <!-- Additional Notes -->
      <div class="cust-field">
        <label class="cust-label" for="cust-notes">Additional Notes</label>
        <div class="cust-input-wrap">
          <textarea class="cust-input cust-textarea" id="cust-notes"
                    placeholder="Enter Here" rows="3"></textarea>
        </div>
      </div>

      <!-- Validation error -->
      <p class="cust-error" id="cust-error"></p>

      <!-- Footer buttons -->
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
   State
───────────────────────────────────────────────────────────── */
let payMethod   = 'cash';
let currentPage = 1;
const ROWS_PER_PAGE = 10;

const tbody         = document.getElementById('pos-tbody');
const displayTotal  = document.getElementById('display-total');
const displayItems  = document.getElementById('display-items');
const inputTendered = document.getElementById('input-tendered');
const displayChange = document.getElementById('display-change');
const custOverlay   = document.getElementById('cust-overlay');

/* ─────────────────────────────────────────────────────────────
   Helpers
───────────────────────────────────────────────────────────── */
function fmt(n)        { return '₱ ' + n.toFixed(2); }
function getTendered() { return Math.max(0, parseFloat(inputTendered.value) || 0); }

function getTotal() {
  let t = 0;
  tbody.querySelectorAll('.tbody-row').forEach(function(row) {
    t += (parseFloat(row.dataset.price) || 0) *
         (parseInt(row.querySelector('.qty-val').textContent, 10) || 0);
  });
  return t;
}

function getItemCount() {
  let c = 0;
  tbody.querySelectorAll('.tbody-row').forEach(function(row) {
    c += parseInt(row.querySelector('.qty-val').textContent, 10) || 0;
  });
  return c;
}

function updateSummary() {
  const total    = getTotal();
  const items    = getItemCount();
  const tendered = getTendered();
  displayTotal.textContent  = fmt(total);
  displayItems.textContent  = '( ' + items + ' item' + (items !== 1 ? 's' : '') + ' )';
  displayChange.textContent = fmt(Math.max(0, tendered - total));
}

/* ─────────────────────────────────────────────────────────────
   Table interactions (qty stepper + trash)
───────────────────────────────────────────────────────────── */
tbody.addEventListener('click', function(e) {
  const addBtn   = e.target.closest('.qty-add');
  const minusBtn = e.target.closest('.qty-minus');
  const trashBtn = e.target.closest('.trash-btn');

  if (addBtn) {
    const row   = addBtn.closest('.tbody-row');
    const valEl = row.querySelector('.qty-val');
    const stock = parseInt(row.dataset.stock, 10) || 0;
    let   qty   = parseInt(valEl.textContent, 10) || 0;
    if (qty < stock) { valEl.textContent = qty + 1; updateSummary(); }
  }
  if (minusBtn) {
    const row   = minusBtn.closest('.tbody-row');
    const valEl = row.querySelector('.qty-val');
    let   qty   = parseInt(valEl.textContent, 10) || 0;
    if (qty > 0) { valEl.textContent = qty - 1; updateSummary(); }
  }
  if (trashBtn) {
    const row = trashBtn.closest('.tbody-row');
    row.querySelector('.qty-val').textContent = '0';
    row.dataset.hidden = '1';
    updateSummary();
    renderPage();
  }
});

inputTendered.addEventListener('input', updateSummary);

/* ─────────────────────────────────────────────────────────────
   Clear
───────────────────────────────────────────────────────────── */
document.getElementById('btn-clear').addEventListener('click', function() {
  tbody.querySelectorAll('.tbody-row').forEach(function(row) {
    row.querySelector('.qty-val').textContent = '0';
    row.dataset.hidden = '';
  });
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

  const tenderedSection = inputTendered.closest('.amount-tendered-btns');
  const tenderedLabel   = document.querySelector('.amount-tendered-label');
  const changeLabel     = document.querySelector('.change-label');
  const changeDisplay   = document.getElementById('display-change');

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
    const current = parseFloat(inputTendered.value) || 0;
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
  const items = [];
  tbody.querySelectorAll('.tbody-row').forEach(function(row) {
    const qty = parseInt(row.querySelector('.qty-val').textContent, 10) || 0;
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
  const total    = getTotal();
  const items    = getItemCount();
  const tendered = getTendered();

  if (items === 0) {
    alert('No items added to the cart.');
    return;
  }
  if (payMethod !== 'utang' && tendered < total) {
    alert('Amount tendered (₱' + tendered.toFixed(2) + ') is less than the total (₱' + total.toFixed(2) + ').');
    return;
  }

  if (payMethod === 'utang') {
    openCustModal();
  } else {
    const change = Math.max(0, tendered - total);
    const msg = [
      'TOTAL:    ₱' + total.toFixed(2),
      'ITEMS:    ' + items,
      'PAYMENT:  ' + payMethod.toUpperCase(),
      'TENDERED: ₱' + tendered.toFixed(2),
      'CHANGE:   ₱' + change.toFixed(2),
      '', 'Proceed with checkout?'
    ].join('\n');
    if (confirm(msg)) submitCashSale(total, tendered, change);
  }
});

/* ─────────────────────────────────────────────────────────────
   Cash sale submission
───────────────────────────────────────────────────────────── */
function submitCashSale(total, tendered, change) {
  const cartItems   = buildCart();
  const btnCheckout = document.getElementById('btn-checkout');
  btnCheckout.disabled    = true;
  btnCheckout.textContent = 'Processing…';

  fetch('process_sale.php', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({
      type:       'cash',
      pay_method: payMethod,
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
      alert('Transaction complete!\nReceipt #' + data.sale_id + '\nChange: ₱' + change.toFixed(2));
      resetPOS();
    } else {
      alert('Error: ' + (data.message || 'Unknown error.'));
    }
  })
  .catch(function(err) {
    btnCheckout.disabled    = false;
    btnCheckout.textContent = 'Check out';
    alert('Network error. Please try again.\n' + err.message);
  });
}

/* ─────────────────────────────────────────────────────────────
   Customer Information Modal
   Opens when "Utang" is selected and user clicks Check out
───────────────────────────────────────────────────────────── */
function openCustModal() {
  // Reset fields
  document.getElementById('cust-name').value    = '';
  document.getElementById('cust-address').value = '';
  document.getElementById('cust-contact').value = '';
  document.getElementById('cust-notes').value   = '';
  document.getElementById('cust-error').textContent = '';
  // Pre-fill amount owed from current cart total
  document.getElementById('cust-amount').value  = getTotal().toFixed(2);

  custOverlay.classList.add('open');
  document.body.style.overflow = 'hidden';
  // Focus first field for accessibility
  setTimeout(function() { document.getElementById('cust-name').focus(); }, 120);
}

function closeCustModal() {
  custOverlay.classList.remove('open');
  document.body.style.overflow = '';
}

// Close triggers
document.getElementById('cust-close-btn').addEventListener('click',  closeCustModal);
document.getElementById('cust-btn-cancel').addEventListener('click',  closeCustModal);
custOverlay.addEventListener('click', function(e) {
  if (e.target === custOverlay) closeCustModal();
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && custOverlay.classList.contains('open')) closeCustModal();
});

// Complete button — validate then submit
document.getElementById('cust-btn-complete').addEventListener('click', function() {
  const name    = document.getElementById('cust-name').value.trim();
  const address = document.getElementById('cust-address').value.trim();
  const contact = document.getElementById('cust-contact').value.trim();
  const notes   = document.getElementById('cust-notes').value.trim();
  const errEl   = document.getElementById('cust-error');

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
  const total       = getTotal();
  const cartItems   = buildCart();
  const btnCheckout = document.getElementById('btn-checkout');
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
      alert('Utang recorded!\nReceipt #' + data.sale_id + '\nCustomer: ' + custName + '\nAmount: ₱' + total.toFixed(2));
      resetPOS();
      window.location.href = 'customers.php';
    } else {
      alert('Error: ' + (data.message || 'Unknown error.'));
    }
  })
  .catch(function(err) {
    btnCheckout.disabled    = false;
    btnCheckout.textContent = 'Check out';
    alert('Network error. Please try again.\n' + err.message);
  });
}

/* ─────────────────────────────────────────────────────────────
   Search filter
───────────────────────────────────────────────────────────── */
document.getElementById('search-input').addEventListener('input', function() {
  const q = this.value.toLowerCase().trim();
  tbody.querySelectorAll('.tbody-row').forEach(function(row) {
    const name = (row.querySelector('.col-name-val') || {}).textContent || '';
    const sku  = (row.querySelector('.col-sku-val')  || {}).textContent || '';
    row.dataset.hidden = (q && !name.toLowerCase().includes(q) && !sku.toLowerCase().includes(q)) ? '1' : '';
  });
  currentPage = 1;
  renderPage();
});

/* ─────────────────────────────────────────────────────────────
   Pagination
───────────────────────────────────────────────────────────── */
function renderPage() {
  const allRows = Array.from(tbody.querySelectorAll('.tbody-row'));
  const visible = allRows.filter(function(r) { return r.dataset.hidden !== '1'; });
  const total   = visible.length;
  const maxPage = Math.max(1, Math.ceil(total / ROWS_PER_PAGE));
  if (currentPage > maxPage) currentPage = maxPage;
  const start = (currentPage - 1) * ROWS_PER_PAGE;
  const end   = start + ROWS_PER_PAGE;
  allRows.forEach(function(r)    { r.style.display = 'none'; });
  visible.forEach(function(r, i) { r.style.display = (i >= start && i < end) ? '' : 'none'; });
  document.getElementById('btn-prev').disabled = currentPage <= 1;
  document.getElementById('btn-next').disabled = currentPage >= maxPage;
}

document.getElementById('btn-prev').addEventListener('click', function() {
  if (currentPage > 1) { currentPage--; renderPage(); }
});
document.getElementById('btn-next').addEventListener('click', function() {
  currentPage++;
  renderPage();
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