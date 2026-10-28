<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
/*
|--------------------------------------------------------------------------
| custom-fields.php  -  Manage the extra questions of the customer form
|--------------------------------------------------------------------------
| This one file is both:
|   1) the admin page (HTML at the bottom), and
|   2) the JSON API used by assets/js/app.js:
|        GET  custom-fields.php?api=list
|        POST custom-fields.php?api=save    (id empty = add, id = edit)
|        POST custom-fields.php?api=delete  (id)
|
| Data goes to the table  customer_custom_fields.
| Customers' answers are saved by customer-form.php in customer_custom_values.
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
$csrf = $_SESSION['customer_form_csrf'];

class UserError extends Exception {}

function jsonOut(array $data, int $status = 200): void
{
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

/* "Preferred fabric" -> preferred_fabric  (unique, never changes after creation) */
function makeFieldKey(mysqli $conn, string $label): string
{
  $base = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $label), '_'));
  $base = $base === '' ? 'field' : substr($base, 0, 80);
  $key  = $base;
  $n    = 1;
  $stmt = mysqli_prepare($conn, 'SELECT 1 FROM customer_custom_fields WHERE field_key = ? LIMIT 1');
  while (true) {
    mysqli_stmt_bind_param($stmt, 's', $key);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    if (mysqli_stmt_num_rows($stmt) === 0) {
      break;
    }
    $n++;
    $key = $base . '_' . $n;
  }
  mysqli_stmt_close($stmt);
  return $key;
}

/* Rows -> the format app.js expects */
function fieldRows(mysqli $conn): array
{
  $res = mysqli_query(
    $conn,
    "SELECT f.id, f.label, f.field_key, f.field_type, f.placeholder, f.options_json, f.is_required,
                (SELECT COUNT(*) FROM customer_custom_values v WHERE v.field_id = f.id) AS used
           FROM customer_custom_fields f
          WHERE f.is_active = 1
          ORDER BY f.sort_order ASC, f.id ASC"
  );
  $out = [];
  while ($r = mysqli_fetch_assoc($res)) {
    $opts = json_decode($r['options_json'] ?? '[]', true);
    $out[] = [
      'id'   => (int)$r['id'],
      'key'  => $r['field_key'],
      'label' => $r['label'],
      'type' => $r['field_type'],
      'ph'   => $r['placeholder'] ?? '',
      'opts' => is_array($opts) ? implode(',', $opts) : '',
      'req'  => (int)$r['is_required'] === 1,
      'used' => (int)$r['used'],
    ];
  }
  return $out;
}

/*
|--------------------------------------------------------------------------
| JSON API
|--------------------------------------------------------------------------
*/
if (isset($_GET['api'])) {
  try {
    $api = (string)$_GET['api'];

    /* every write needs the CSRF token (sent by app.js in a header) */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
      if (!is_string($sent) || !hash_equals($csrf, $sent)) {
        jsonOut(['success' => false, 'message' => 'Session expired. Please refresh the page.'], 403);
      }
    }

    /* ---------- list ---------- */
    if ($api === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
      jsonOut(['success' => true, 'fields' => fieldRows($conn)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      jsonOut(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    /* ---------- save (add / edit) ---------- */
    if ($api === 'save') {
      $id    = (int)($_POST['id'] ?? 0);
      $label = trim((string)($_POST['label'] ?? ''));
      $type  = (string)($_POST['type'] ?? 'text');
      $ph    = trim((string)($_POST['ph'] ?? ''));
      $optsS = trim((string)($_POST['opts'] ?? ''));
      $req   = !empty($_POST['req']) && $_POST['req'] !== '0' ? 1 : 0;

      if ($label === '') {
        throw new UserError('Enter a field label.');
      }
      if (mb_strlen($label) > 150) {
        throw new UserError('Label is too long (max 150 characters).');
      }
      if (mb_strlen($ph) > 255) {
        throw new UserError('Placeholder is too long (max 255 characters).');
      }
      if (!in_array($type, ['text', 'number', 'date', 'dropdown', 'textarea'], true)) {
        throw new UserError('Invalid field type.');
      }

      /* dropdown options: "Cotton, Silk ,Linen" -> ["Cotton","Silk","Linen"] */
      $optionsJson = null;
      if ($type === 'dropdown') {
        $opts = [];
        foreach (explode(',', $optsS) as $o) {
          $o = trim($o);
          if ($o !== '' && !in_array($o, $opts, true)) {
            if (mb_strlen($o) > 100) {
              throw new UserError('Each option must be under 100 characters.');
            }
            $opts[] = $o;
          }
        }
        if (count($opts) < 1) {
          throw new UserError('Add at least one dropdown option (comma separated).');
        }
        if (count($opts) > 50) {
          throw new UserError('A dropdown can have at most 50 options.');
        }
        $optionsJson = json_encode($opts, JSON_UNESCAPED_UNICODE);
      }
      $phDb = $ph !== '' ? $ph : null;

      if ($id > 0) {
        /* the key is NOT changed on edit, so old saved values stay linked */
        $stmt = mysqli_prepare(
          $conn,
          'UPDATE customer_custom_fields
                        SET label = ?, field_type = ?, placeholder = ?, options_json = ?, is_required = ?
                      WHERE id = ? AND is_active = 1'
        );
        mysqli_stmt_bind_param($stmt, 'ssssii', $label, $type, $phDb, $optionsJson, $req, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        jsonOut(['success' => true, 'message' => 'Field updated', 'id' => $id]);
      }

      $key   = makeFieldKey($conn, $label);
      $order = (int)mysqli_fetch_row(mysqli_query(
        $conn,
        'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM customer_custom_fields'
      ))[0];

      $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO customer_custom_fields
                    (label, field_key, field_type, placeholder, options_json, is_required, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
      );
      mysqli_stmt_bind_param($stmt, 'sssssii', $label, $key, $type, $phDb, $optionsJson, $req, $order);
      mysqli_stmt_execute($stmt);
      $newId = (int)mysqli_insert_id($conn);
      mysqli_stmt_close($stmt);

      jsonOut(['success' => true, 'message' => 'Field added', 'id' => $newId]);
    }

    /* ---------- delete ---------- */
    if ($api === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) {
        throw new UserError('Invalid field.');
      }

      $c = mysqli_prepare($conn, 'SELECT COUNT(*) FROM customer_custom_values WHERE field_id = ?');
      mysqli_stmt_bind_param($c, 'i', $id);
      mysqli_stmt_execute($c);
      mysqli_stmt_bind_result($c, $used);
      mysqli_stmt_fetch($c);
      mysqli_stmt_close($c);

      if ($used > 0) {
        /* customers already answered it: hide the field but keep their answers */
        $s = mysqli_prepare($conn, 'UPDATE customer_custom_fields SET is_active = 0 WHERE id = ?');
        $msg = 'Field removed from the form. ' . $used . ' saved answer(s) were kept.';
      } else {
        $s = mysqli_prepare($conn, 'DELETE FROM customer_custom_fields WHERE id = ?');
        $msg = 'Field deleted';
      }
      mysqli_stmt_bind_param($s, 'i', $id);
      mysqli_stmt_execute($s);
      mysqli_stmt_close($s);
      jsonOut(['success' => true, 'message' => $msg]);
    }

    jsonOut(['success' => false, 'message' => 'Unknown action.'], 400);
  } catch (UserError $e) {
    jsonOut(['success' => false, 'message' => $e->getMessage()], 422);
  } catch (Throwable $e) {
    error_log('custom-fields.php API error: ' . $e->getMessage());
    jsonOut(['success' => false, 'message' => 'Database error. Please try again.'], 500);
  }
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
  <title>Custom Registration Fields | <?= crm_e(crm_brand()['name']) ?> CRM</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="assets/css/style.css?v=1" rel="stylesheet">
  <?php crm_head(); ?>
  <style>
      /* ================================
   CUSTOM FIELDS PAGE
================================ */

.panel[data-table="fields"] {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 4px;
  box-shadow: 0 4px 18px rgba(15, 23, 42, 0.04);
  overflow: hidden;
}

/* Toolbar */
.panel[data-table="fields"] .toolbar {
  padding: 18px 20px;
  margin: 0;
  align-items: center;
  border-bottom: 1px solid #e5e7eb;
  background: #fff;
}

.panel[data-table="fields"] .input-group {
  border-radius: 4px;
  overflow: hidden;
}

.panel[data-table="fields"] .input-group-text {
  border-color: #dfe3e8;
  color: #64748b;
  padding: 10px 13px;
}

.panel[data-table="fields"] [data-search] {
  min-height: 42px;
  border-color: #dfe3e8;
  font-size: 14px;
  box-shadow: none;
}

.panel[data-table="fields"] [data-search]:focus {
  border-color: #c9a227;
  box-shadow: 0 0 0 3px rgba(201, 162, 39, 0.12);
}

.panel[data-table="fields"] [data-count] {
  font-size: 13px;
  font-weight: 500;
  color: #64748b;
}

/* Table wrapper */
.panel[data-table="fields"] .table-responsive {
  width: 100%;
  overflow-x: auto;
  scrollbar-width: thin;
}

/* Table */
.panel[data-table="fields"] table {
  width: 100%;
  min-width: 760px;
  margin: 0;
  border-collapse: separate;
  border-spacing: 0;
  background: #fff;
}

/* Table header */
.panel[data-table="fields"] table thead th {
  padding: 15px 18px;
  background: #f8fafc;
  color: #475569;
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.55px;
  white-space: nowrap;
  border-top: 0;
  border-bottom: 1px solid #dfe3e8;
  border-right: 1px solid #e5e7eb;
}

.panel[data-table="fields"] table thead th:last-child {
  border-right: 0;
}

/* Table cells */
.panel[data-table="fields"] table tbody td {
  padding: 3px 6px;
  color: #334155;
  font-size: 14px;
  vertical-align: middle;
  border-bottom: 1px solid #e5e7eb;
  border-right: 1px solid #edf0f2;
}

.panel[data-table="fields"] table tbody td:last-child {
  border-right: 0;
}

.panel[data-table="fields"] table tbody tr:last-child td {
  border-bottom: 0;
}

/* Alternating rows */
.panel[data-table="fields"] table tbody tr:nth-child(even) {
  background: #fafbfc;
}

.panel[data-table="fields"] table tbody tr:nth-child(odd) {
  background: #fff;
}

/* Hover effect */
.panel[data-table="fields"] table tbody tr {
  transition: background-color 0.2s ease;
}

.panel[data-table="fields"] table tbody tr:hover {
  background: #fff9e8;
}

/* Field labels */
.panel[data-table="fields"] table tbody td:first-child {
  color: #1e293b;
  font-weight: 600;
}

/* Field keys and code-like text */
.panel[data-table="fields"] code {
  padding: 4px 7px;
  color: #475569;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 5px;
  font-size: 12px;
}

/* Badges */
.panel[data-table="fields"] .badge {
  display: inline-block;
  padding: 6px 9px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.2px;
}

/* Action buttons */
.panel[data-table="fields"] .btn {
  transition: all 0.2s ease;
}

.panel[data-table="fields"] .btn-sm {
  padding: 6px 10px;
  border-radius: 4px;
  font-size: 12px;
}

.panel[data-table="fields"] .btn-outline-primary {
  color: #2563eb;
  border-color: #bfdbfe;
  background: #fff;
}

.panel[data-table="fields"] .btn-outline-primary:hover {
  color: #fff;
  background: #2563eb;
  border-color: #2563eb;
}

.panel[data-table="fields"] .btn-outline-danger {
  color: #dc2626;
  border-color: #fecaca;
  background: #fff;
}

.panel[data-table="fields"] .btn-outline-danger:hover {
  color: #fff;
  background: #dc2626;
  border-color: #dc2626;
}

/* Empty state */
.panel[data-table="fields"] .empty {
  padding: 55px 20px;
  color: #64748b;
  background: #fff;
}

.panel[data-table="fields"] .empty i {
  display: block;
  margin-bottom: 12px;
  color: #cbd5e1;
}

.panel[data-table="fields"] .empty p {
  font-size: 14px;
}

/* ================================
   ADD / EDIT FIELD MODAL
================================ */

#cfModal .modal-content {
  overflow: hidden;
  border: 1px solid #e5e7eb;
  border-radius: 4px;
  box-shadow: 0 20px 60px rgba(15, 23, 42, 0.18);
}

#cfModal .modal-header {
  padding: 20px 24px;
  background: #fff;
  border-bottom: 1px solid #e5e7eb;
}

#cfModal .modal-title {
  color: #1e293b;
  font-size: 19px;
  font-weight: 700;
}

#cfModal .modal-body {
  padding: 24px;
}

#cfModal .form-label {
  margin-bottom: 8px;
  color: #334155;
  font-size: 13px;
  font-weight: 600;
}

#cfModal .form-control,
#cfModal .form-select {
  min-height: 44px;
  padding: 10px 12px;
  color: #334155;
  background-color: #fff;
  border: 1px solid #dbe1e8;
  border-radius: 4px;
  font-size: 14px;
  box-shadow: none;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

#cfModal textarea.form-control {
  min-height: 100px;
}

#cfModal .form-control:focus,
#cfModal .form-select:focus {
  border-color: #c9a227;
  box-shadow: 0 0 0 3px rgba(201, 162, 39, 0.12);
}

#cfModal .form-control::placeholder {
  color: #94a3b8;
}

#cfModal .form-check {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px 12px 42px;
  background: #f8fafc;
  border: 1px solid #e5e7eb;
  border-radius: 4px;
}

#cfModal .form-check-input {
  cursor: pointer;
  border-color: #cbd5e1;
  box-shadow: none;
}

#cfModal .form-check-input:checked {
  background-color: #c9a227;
  border-color: #c9a227;
}

#cfModal .form-check-label {
  color: #334155;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
}

#cfModal .modal-footer {
  padding: 16px 24px;
  background: #f8fafc;
  border-top: 1px solid #e5e7eb;
}

#cfModal .modal-footer .btn {
  min-height: 40px;
  padding: 9px 18px;
  border-radius: 4px;
  font-size: 13px;
  font-weight: 600;
}

#cfModal .modal-footer .btn-light {
  color: #475569;
  background: #fff;
  border: 1px solid #dbe1e8;
}

#cfModal .modal-footer .btn-light:hover {
  background: #f1f5f9;
}

#cfModal .modal-footer .btn-primary {
  background: #c9a227;
  border-color: #c9a227;
  color: #fff;
}

#cfModal .modal-footer .btn-primary:hover {
  background: #ad871b;
  border-color: #ad871b;
}

/* ================================
   PAGE ACTION BUTTONS
================================ */

.page-head .btn {
  min-height: 42px;
  padding: 10px 16px;
  border-radius: 4px;
  font-size: 13px;
  font-weight: 600;
  transition: all 0.2s ease;
}

.page-head .btn-outline-primary {
  color: #334155;
  border-color: #dbe1e8;
  background: #fff;
}

.page-head .btn-outline-primary:hover {
  color: #1d4ed8;
  border-color: #93c5fd;
  background: #eff6ff;
}

.page-head .btn-gold {
  color: #fff;
  background: #c9a227;
  border: 1px solid #c9a227;
}

.page-head .btn-gold:hover {
  color: #fff;
  background: #ad871b;
  border-color: #ad871b;
  transform: translateY(-1px);
}

/* ================================
   RESPONSIVE
================================ */

@media (max-width: 767.98px) {
  .panel[data-table="fields"] {
    border-radius: 4px;
  }

  .panel[data-table="fields"] .toolbar {
    padding: 14px;
  }

  .panel[data-table="fields"] table {
    min-width: 700px;
  }

  .panel[data-table="fields"] table thead th {
    padding: 12px 14px;
    font-size: 11px;
  }

  .panel[data-table="fields"] table tbody td {
    padding: 13px 14px;
    font-size: 13px;
  }

  .page-head {
    align-items: flex-start;
    gap: 16px;
  }

  .page-head .d-flex {
    width: 100%;
  }

  .page-head .btn {
    flex: 1;
    justify-content: center;
  }

  #cfModal .modal-body {
    padding: 18px;
  }

  #cfModal .modal-header,
  #cfModal .modal-footer {
    padding: 16px 18px;
  }
}

@media (max-width: 480px) {
  .page-head .d-flex {
    flex-direction: column;
  }

  .page-head .btn {
    width: 100%;
  }

  #cfModal .modal-dialog {
    margin: 10px;
  }

  #cfModal .modal-title {
    font-size: 17px;
  }
}
  </style>
</head>

<body>
  <div class="overlay"></div>
  <?php crm_sidebar(); ?>
  <div class="main">
    <?php crm_topbar(); ?>
    <main class="content">
      <div class="page-head">
        <div>
          <h1 id="pageTitle">Custom registration fields</h1>
          <p class="text-muted mb-0">Add extra questions to the customer registration form. They are saved in the database.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <a href="add-customer.php" class="btn btn-outline-primary"><i class="bi bi-eye me-1"></i>Preview form</a>
          <button class="btn btn-gold" data-act="cf-new"><i class="bi bi-plus-lg me-1"></i>Add field</button>
        </div>
      </div>

      <div class="panel">
        <div class="table-responsive">
          <table class="table align-middle mb-0" data-crm-table>
            <thead><tr><th>Field label</th><th data-filter="Type">Type</th><th>Placeholder</th><th data-filter="Required">Required</th><th class="act">Actions</th></tr></thead>
            <tbody id="cfRows"><tr><td colspan="5" class="text-center text-muted py-4">Loading...</td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- Add / edit popup -->
      <div class="modal fade" id="cfModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
          <form class="modal-content needs-validation" id="cfForm" novalidate data-custom>
            <div class="modal-header">
              <h5 class="modal-title" id="cfTitle">Add field</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="row g-3">
                <div class="col-12"><label class="form-label" for="cfl">Field label <span class="text-danger">*</span></label><input id="cfl" name="label" class="form-control" maxlength="150" placeholder="e.g. Preferred fabric" required>
                  <div class="invalid-feedback">Enter a label.</div>
                </div>
                <div class="col-md-6"><label class="form-label" for="cft">Field type</label><select id="cft" name="type" class="form-select">
                    <option value="text">Text</option>
                    <option value="number">Number</option>
                    <option value="date">Date</option>
                    <option value="dropdown">Dropdown</option>
                    <option value="textarea">Long text</option>
                  </select></div>
                <div class="col-md-6"><label class="form-label" for="cfp">Placeholder</label><input id="cfp" name="ph" class="form-control" maxlength="255"></div>
                <div class="col-12 d-none"><label class="form-label" for="cfo">Dropdown options <span class="text-danger">*</span></label><input id="cfo" name="opts" class="form-control" placeholder="Comma separated: Cotton, Silk, Linen">
                  <div class="invalid-feedback">Add at least one option.</div>
                </div>
                <div class="col-12">
                  <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="cfr" name="req"><label class="form-check-label" for="cfr">Required field</label></div>
                </div>
              </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save field</button></div>
          </form>
        </div>
      </div>
    </main>
  </div>
  <div id="printArea"></div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/app.js"></script>
<?php crm_foot(); ?>
</body>

</html>