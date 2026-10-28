<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
/*
|--------------------------------------------------------------------------
| add-customer.php  -  Customer registration form (page)
|--------------------------------------------------------------------------
| - Creates the CSRF token
| - Loads active custom fields from MySQL and draws them here (server side)
| - Shows the error message + previously typed values if customer-form.php
|   rejected the form
| The form posts to customer-form.php
*/
date_default_timezone_set('Asia/Kolkata');

if (session_status() !== PHP_SESSION_ACTIVE) {
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}
require_once __DIR__ . '/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');

if (empty($_SESSION['customer_form_csrf'])) {
  $_SESSION['customer_form_csrf'] = bin2hex(random_bytes(32));
}

/* One-time messages from customer-form.php */
$formError = $_SESSION['customer_form_error'] ?? '';
$old       = $_SESSION['customer_form_old'] ?? [];
unset($_SESSION['customer_form_error'], $_SESSION['customer_form_old']);
$hasOld = !empty($old);

/* ---------- small output helpers ---------- */
function e($v): string
{
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function old(string $k, string $default = ''): string
{
  global $old;
  return isset($old[$k]) && !is_array($old[$k]) ? (string)$old[$k] : $default;
}
function selected(string $k, string $v): string
{
  return old($k) === $v ? ' selected' : '';
}
function oldCustom($id): string
{
  global $old;
  return isset($old['custom'][$id]) && !is_array($old['custom'][$id]) ? (string)$old['custom'][$id] : '';
}

/* ---------- active custom fields ---------- */
$customFields = [];
try {
  $res = mysqli_query(
    $conn,
    "SELECT id, label, field_type, placeholder, options_json, is_required
           FROM customer_custom_fields
          WHERE is_active = 1
          ORDER BY sort_order ASC, id ASC"
  );
  while ($row = mysqli_fetch_assoc($res)) {
    $opts = json_decode($row['options_json'] ?? '[]', true);
    $row['options'] = is_array($opts) ? $opts : [];
    $customFields[] = $row;
  }
} catch (Throwable $ex) {
  error_log('add-customer.php custom fields: ' . $ex->getMessage());
  $formError = $formError ?: 'Custom fields could not be loaded. Check the database tables.';
}

$prefList = crm_customer_prefs();
$oldPrefs = isset($old['preferences']) && is_array($old['preferences']) ? $old['preferences'] : [];
$states   = ['Delhi', 'Haryana', 'Uttar Pradesh', 'Punjab', 'Rajasthan', 'Maharashtra'];
$sources  = ['Walk-in', 'Website', 'Instagram', 'Referral', 'Facebook', 'Housing', '99acres', 'REI'];
$cats     = ['Regular', 'Premium', 'VIP', 'Wholesale'];
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="<?= e($_SESSION['customer_form_csrf']) ?>" />
  <title>Add Customer | <?= crm_e(crm_brand()['name']) ?> CRM</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap"
    rel="stylesheet" />
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet" />
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    rel="stylesheet" />
  <link href="assets/css/style.css" rel="stylesheet" />
  <?php crm_head(); ?>
</head>

<body>
  <div class="overlay"></div>
  <?php crm_sidebar(); ?>
  <div class="main">
    <?php crm_topbar(); ?>
    <main class="content">
      <div class="page-head">
        <div>
          <h1 id="pageTitle">Add customer</h1>
          <p class="text-muted mb-0">
            Register a new customer. Fields marked * are required.
          </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <a href="customers.php" class="btn btn-outline-primary">Back to customers</a>
        </div>
      </div>

      <?php if ($formError !== ''): ?>
        <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div><?= e($formError) ?></div>
        </div>
      <?php endif; ?>

      <form
        class="needs-validation"
        id="customerForm"
        method="POST"
        action="customer-form.php"
        novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['customer_form_csrf']) ?>" />

        <div class="row g-3">
          <div class="col-lg-8">
            <div class="d-grid gap-3">

              <!-- Personal details -->
              <div class="panel">
                <div class="panel-title">
                  <h5><i class="bi bi-person-vcard"></i>Personal details</h5>
                </div>
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label" for="name">Full name <span class="text-danger">*</span></label>
                    <input type="text" id="name" name="name" class="form-control" maxlength="150"
                      placeholder="e.g. Ananya Sharma" value="<?= e(old('name')) ?>" required />
                    <div class="form-text">Write the name as the customer wants it on bills.</div>
                    <div class="invalid-feedback">Please enter the customer's full name.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="mobile">Mobile number <span class="text-danger">*</span></label>
                    <input type="tel" id="mobile" name="mobile" class="form-control"
                      placeholder="10-digit number" value="<?= e(old('mobile')) ?>"
                      required pattern="[6-9][0-9]{9}" maxlength="10" inputmode="numeric" />
                    <div class="form-text">We use this for WhatsApp messages. Enter 10 digits without +91.</div>
                    <div class="invalid-feedback">Enter a 10-digit mobile number starting with 6, 7, 8 or 9.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" maxlength="190"
                      placeholder="name@example.com" value="<?= e(old('email')) ?>" />
                    <div class="form-text">Optional. Used to send invoices.</div>
                    <div class="invalid-feedback">Enter a valid email.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="gender">Gender <span class="text-danger">*</span></label>
                    <select id="gender" name="gender" class="form-select" required>
                      <option value="">Select...</option>
                      <?php foreach (['Female', 'Male', 'Other'] as $g): ?>
                        <option<?= selected('gender', $g) ?>><?= e($g) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback">Please choose an option.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="dob">Date of birth</label>
                    <input type="date" id="dob" name="dob" class="form-control" value="<?= e(old('dob')) ?>" />
                    <div class="form-text">Used to send birthday wishes.</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="anniv">Anniversary</label>
                    <input type="date" id="anniv" name="anniv" class="form-control" value="<?= e(old('anniv')) ?>" />
                    <div class="form-text">Used to send anniversary wishes.</div>
                  </div>
                </div>
              </div>

              <!-- Address -->
              <div class="panel">
                <div class="panel-title">
                  <h5><i class="bi bi-geo-alt"></i>Address</h5>
                </div>
                <div class="row g-3">
                  <div class="col-12">
                    <label class="form-label" for="addr">Address</label>
                    <textarea id="addr" name="addr" class="form-control" rows="2"
                      placeholder="House no, street, area"><?= e(old('addr')) ?></textarea>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="city">City <span class="text-danger">*</span></label>
                    <input type="text" id="city" name="city" class="form-control" maxlength="100"
                      value="<?= e(old('city')) ?>" required />
                    <div class="invalid-feedback">Enter a valid city.</div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="state">State <span class="text-danger">*</span></label>
                    <select id="state" name="state" class="form-select" required>
                      <option value="">Select...</option>
                      <?php foreach ($states as $s): ?>
                        <option<?= selected('state', $s) ?>><?= e($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback">Please choose an option.</div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="pin">PIN code <span class="text-danger">*</span></label>
                    <input type="text" id="pin" name="pin" class="form-control" placeholder="6 digits"
                      value="<?= e(old('pin')) ?>" required pattern="[0-9]{6}" maxlength="6" inputmode="numeric" />
                    <div class="form-text">6-digit area PIN code.</div>
                    <div class="invalid-feedback">Enter a 6-digit PIN code.</div>
                  </div>
                </div>
              </div>

              <!-- Preferences & notes -->
              <div class="panel">
                <div class="panel-title">
                  <h5><i class="bi bi-journal-text"></i>Preferences &amp; notes</h5>
                </div>
                <div class="row g-3">
                  <div class="col-12">
                    <label class="form-label d-block">Product preferences</label>
                    <?php foreach ($prefList as $i => $p): ?>
                      <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="p<?= $i ?>"
                          name="preferences[]" value="<?= e($p) ?>" <?= in_array($p, $oldPrefs, true) ? ' checked' : '' ?> />
                        <label class="form-check-label" for="p<?= $i ?>"><?= e($p) ?></label>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <div class="col-12">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea id="notes" name="notes" class="form-control" rows="3"
                      placeholder="Size, style, favourite brands, special requests"><?= e(old('notes')) ?></textarea>
                  </div>
                </div>
              </div>

              <!-- Additional (custom) fields: drawn by PHP from the database -->
              <div class="panel">
                <div class="panel-title">
                  <h5><i class="bi bi-ui-checks"></i>Additional fields</h5>
                </div>
                <div class="row g-3" id="customFields" data-server="1">
                  <?php foreach ($customFields as $f):
                    $fid   = (int)$f['id'];
                    $req   = (int)$f['is_required'] === 1;
                    $name  = 'custom[' . $fid . ']';
                    $domId = 'cf_' . $fid;
                    $val   = oldCustom($fid);
                    $ph    = $f['placeholder'] ?? '';
                  ?>
                    <div class="<?= $f['field_type'] === 'textarea' ? 'col-12' : 'col-md-6' ?>">
                      <label class="form-label" for="<?= $domId ?>">
                        <?= e($f['label']) ?><?= $req ? ' <span class="text-danger">*</span>' : '' ?>
                      </label>
                      <?php if ($f['field_type'] === 'dropdown'): ?>
                        <select id="<?= $domId ?>" name="<?= $name ?>" class="form-select" <?= $req ? ' required' : '' ?>>
                          <option value="">Select...</option>
                          <?php foreach ($f['options'] as $opt): ?>
                            <option value="<?= e($opt) ?>" <?= $val === (string)$opt ? ' selected' : '' ?>><?= e($opt) ?></option>
                          <?php endforeach; ?>
                        </select>
                      <?php elseif ($f['field_type'] === 'textarea'): ?>
                        <textarea id="<?= $domId ?>" name="<?= $name ?>" class="form-control" rows="2"
                          maxlength="1000" placeholder="<?= e($ph) ?>" <?= $req ? ' required' : '' ?>><?= e($val) ?></textarea>
                      <?php else:
                        $type = in_array($f['field_type'], ['number', 'date'], true) ? $f['field_type'] : 'text'; ?>
                        <input type="<?= $type ?>" id="<?= $domId ?>" name="<?= $name ?>" class="form-control"
                          <?= $type === 'number' ? 'step="any"' : 'maxlength="1000"' ?>
                          placeholder="<?= e($ph) ?>" value="<?= e($val) ?>" <?= $req ? ' required' : '' ?> />
                      <?php endif; ?>
                      <div class="invalid-feedback">This field is required.</div>
                    </div>
                  <?php endforeach; ?>
                  <?php if (!$customFields): ?>
                    <p class="text-muted col-12 mb-0">No custom fields yet. Add them from Settings, Custom Registration Fields.</p>
                  <?php endif; ?>
                </div>
                <small class="text-muted d-block mt-3">
                  Manage these in <a href="custom-fields.php">Custom Registration Fields</a>.
                </small>
              </div>
            </div>
          </div>

          <!-- Right column -->
          <div class="col-lg-4">
            <div class="d-grid gap-3">
              <div class="panel">
                <div class="panel-title">
                  <h5><i class="bi bi-clipboard-check"></i>Registration</h5>
                </div>
                <div class="row g-3">
                  <div class="col-md-12">
                    <label class="form-label" for="cat">Customer category <span class="text-danger">*</span></label>
                    <select id="cat" name="cat" class="form-select" required>
                      <option value="">Select...</option>
                      <?php foreach ($cats as $c): ?>
                        <option<?= selected('cat', $c) ?>><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback">Please choose an option.</div>
                  </div>
                  <div class="col-md-12">
                    <label class="form-label" for="regdate">Registration date <span class="text-danger">*</span></label>
                    <input type="date" id="regdate" name="regdate" class="form-control"
                      value="<?= e(old('regdate', date('Y-m-d'))) ?>" required />
                    <div class="invalid-feedback">Enter a valid registration date.</div>
                  </div>
                  <div class="col-md-12">
                    <label class="form-label" for="src">Source <span class="text-danger">*</span></label>
                    <select id="src" name="src" class="form-select" required>
                      <option value="">Select...</option>
                      <?php foreach ($sources as $s): ?>
                        <option<?= selected('src', $s) ?>><?= e($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback">Please choose an option.</div>
                  </div>
                </div>
              </div>

              

              <div class="d-grid gap-2">
                <button class="btn btn-gold btn-lg" type="submit">Save customer</button>
                <a class="btn btn-light" href="add-customer.php">Clear form</a>
              </div>
            </div>
          </div>
        </div>
      </form>
    </main>
  </div>
  <div id="printArea"></div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/app.js"></script>
<?php crm_foot(); ?>
</body>

</html>