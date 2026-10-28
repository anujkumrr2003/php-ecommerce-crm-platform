<?php
/* inquiries.php - website / portal inquiries (database). Website form posts to inquiry-api.php */
require_once __DIR__ . '/includes/inquiry.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
$me = crm_user();

const INQ_VIEWS = [
    'all'       => ['All inquiries', ''],
    'new'       => ['New inquiries', "i.status = 'New'"],
    'product'   => ['Product inquiries', "i.inquiry_type = 'Product'"],
    'contact'   => ['Contact inquiries', "i.inquiry_type = 'Contact'"],
    'followups' => ['Follow-ups', "i.status = 'Follow-up'"],
    'converted' => ['Converted inquiries', "i.status = 'Converted'"],
];

function inq_date(string $k): ?string
{
    $v = trim((string)($_POST[$k] ?? ''));
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_csrf_require();
    $do = (string)($_POST['do'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $need = $do === 'delete' ? 'inquiries.delete' : 'inquiries.manage';
    try {
        if (!crm_can($need)) {
            throw new RuntimeException('You do not have permission to do this.');
        }
        if ($do === 'add') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Enter the name.');
            }
            $newId = crm_inquiry_create([
                'name' => $name, 'mobile' => $_POST['mobile'] ?? '', 'email' => $_POST['email'] ?? '', 'message' => $_POST['message'] ?? '',
                'product' => $_POST['product'] ?? '', 'type' => $_POST['type'] ?? 'Contact', 'source' => $_POST['source'] ?? 'Walk-in', 'ip' => crm_ip(),
            ]);
            crm_log('inquiries', 'add', "Inquiry #$newId from $name");
            crm_flash('success', 'Inquiry added.');
        } elseif ($do === 'update' && $id > 0) {
            $status = (string)($_POST['status'] ?? '');
            $follow = inq_date('followup_date');
            $assign = (int)($_POST['assigned_to'] ?? 0) ?: null;
            if (!in_array($status, CRM_INQUIRY_STATUSES, true)) {
                throw new RuntimeException('Invalid status.');
            }
            if ($status === 'Follow-up' && $follow === null) {
                throw new RuntimeException('Choose a follow-up date.');
            }
            crm_run('UPDATE inquiries SET status = ?, followup_date = ?, assigned_to = ?, notes = ? WHERE id = ?', 'ssisi',
                [$status, $follow, $assign, mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 3000), $id]);
            crm_log('inquiries', 'update', "Inquiry #$id -> $status");
            crm_flash('success', 'Inquiry updated.');
        } elseif ($do === 'status' && $id > 0) {
            $status = (string)($_POST['status'] ?? '');
            if (!in_array($status, CRM_INQUIRY_STATUSES, true) || $status === 'Follow-up') {
                throw new RuntimeException('Use "Manage" to set a follow-up date.');
            }
            crm_run('UPDATE inquiries SET status = ? WHERE id = ?', 'si', [$status, $id]);
            crm_log('inquiries', 'status', "Inquiry #$id -> $status");
            crm_flash('success', 'Status changed to ' . $status . '.');
        } elseif ($do === 'convert' && $id > 0) {
            $q = crm_one('SELECT * FROM inquiries WHERE id = ?', 'i', [$id]);
            if (!$q) {
                throw new RuntimeException('Inquiry not found.');
            }
            if (!preg_match('/^[0-9]{10}$/', (string)$q['mobile'])) {
                throw new RuntimeException('This inquiry has no valid 10 digit mobile. Add the customer manually from "Add Customer".');
            }
            $c = crm_one('SELECT id FROM customers WHERE mobile = ? LIMIT 1', 's', [$q['mobile']]);
            if (!$c) {
                crm_run("INSERT INTO customers (name, mobile, email, gender, city, state, pin, clothing_preferences, notes, category, registration_date, source, whatsapp_consent, sms_consent, wa_status)
                         VALUES (?, ?, ?, 'Other', '-', '-', '000000', '[]', ?, 'Regular', CURDATE(), ?, 0, 0, 'skipped')",
                    'sssss', [$q['name'], $q['mobile'], $q['email'] !== '' ? $q['email'] : null, 'Converted from inquiry #' . $id, $q['source']]);
                $cid = crm_insert_id();
            } else {
                $cid = (int)$c['id'];
            }
            crm_run("UPDATE inquiries SET status = 'Converted', customer_id = ? WHERE id = ?", 'ii', [$cid, $id]);
            crm_log('inquiries', 'convert', "Inquiry #$id -> customer #$cid");
            crm_flash('success', $c ? 'Linked to the existing customer with this mobile number.' : 'Customer created. Open Customers to complete the details (city, birthday...).');
        } elseif ($do === 'delete' && $id > 0) {
            crm_run('DELETE FROM inquiries WHERE id = ?', 'i', [$id]);
            crm_log('inquiries', 'delete', "Inquiry #$id");
            crm_flash('success', 'Inquiry deleted.');
        } elseif ($do === 'newkey' && crm_can('api.manage')) {
            crm_website_key(true);
            crm_log('inquiries', 'api_key', 'Website API key regenerated');
            crm_flash('success', 'New API key created. Update it on your website.');
        }
    } catch (RuntimeException $ex) {
        crm_flash('danger', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log('inquiries.php: ' . $ex->getMessage());
        crm_flash('danger', 'Could not save. Please try again.');
    }
    header('Location: inquiries.php' . (!empty($_POST['view']) ? '?view=' . rawurlencode((string)$_POST['view']) : ''));
    exit;
}

$view = isset(INQ_VIEWS[$_GET['view'] ?? '']) ? (string)$_GET['view'] : 'all';
$sql  = 'SELECT i.*, u.name AS assignee FROM inquiries i LEFT JOIN users u ON u.id = i.assigned_to'
    . (INQ_VIEWS[$view][1] !== '' ? ' WHERE ' . INQ_VIEWS[$view][1] : '') . ' ORDER BY i.id DESC LIMIT 5000';
$rows  = crm_all($sql);
$staff = crm_all("SELECT id, name FROM users WHERE status = 'active' ORDER BY name");
$tone  = ['New' => 'blue', 'In Progress' => 'amber', 'Follow-up' => 'gold', 'Converted' => 'green', 'Closed' => 'gray'];
$canM  = crm_can('inquiries.manage');
$canD  = crm_can('inquiries.delete');
$canApi = crm_can('api.manage');
$today = date('Y-m-d');

$actions = '';
if ($canM) {
    $actions .= '<button class="btn btn-gold" type="button" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg me-1"></i>Add inquiry</button>';
}
if ($canApi) {
    $actions .= '<a href="api-settings.php" class="btn btn-outline-primary"><i class="bi bi-plug me-1"></i>API settings</a>';
}
crm_page_start(INQ_VIEWS[$view][0]);
crm_page_head(INQ_VIEWS[$view][0], 'Leads from your website and connected portals.', $actions);
echo crm_flash_html();
?>
<div class="panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0" data-crm-table>
      <thead><tr>
        <th>Inquiry</th><th data-filter="Type">Type</th><th>Product / message</th><th data-filter="Source">Source</th>
        <th>Received</th><th>Follow-up</th><th data-filter="Assigned">Assigned</th><th data-filter="Status">Status</th><th class="act">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $payload = json_encode(['id' => (int)$r['id'], 'name' => $r['name'], 'mobile' => $r['mobile'], 'email' => $r['email'], 'type' => $r['inquiry_type'], 'product' => $r['product_name'],
              'message' => $r['message'], 'source' => $r['source'], 'status' => $r['status'], 'followup' => $r['followup_date'], 'assigned' => (int)$r['assigned_to'], 'notes' => $r['notes'],
              'received' => date('j M Y, g:i a', strtotime((string)$r['created_at']))], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG);
          $over = $r['followup_date'] && $r['followup_date'] <= $today && in_array($r['status'], ['New', 'In Progress', 'Follow-up'], true);
      ?>
        <tr>
          <td><div class="fw-semibold"><?= crm_e($r['name']) ?></div><small class="text-muted"><?= crm_e($r['mobile'] ?: $r['email']) ?></small></td>
          <td><?= crm_e($r['inquiry_type']) ?></td>
          <td><?php if ($r['product_name'] !== ''): ?><b><?= crm_e($r['product_name']) ?></b><br><?php endif; ?><span class="d-inline-block text-truncate" style="max-width:260px"><?= crm_e($r['message']) ?></span></td>
          <td><?= crm_e($r['source']) ?></td>
          <td data-sort="<?= crm_e($r['created_at']) ?>"><?= crm_e(date('j M Y', strtotime((string)$r['created_at']))) ?><br><small class="text-muted"><?= crm_e(crm_ago((string)$r['created_at'])) ?></small></td>
          <td><?= $r['followup_date'] ? '<span class="' . ($over ? 'text-danger fw-semibold' : '') . '">' . crm_e(date('j M Y', strtotime((string)$r['followup_date']))) . '</span>' : '<span class="text-muted">-</span>' ?></td>
          <td data-value="<?= crm_e($r['assignee'] ?: 'Unassigned') ?>"><?= crm_e($r['assignee'] ?: '-') ?></td>
          <td data-value="<?= crm_e($r['status']) ?>"><span class="badge-s b-<?= $tone[$r['status']] ?>"><?= crm_e($r['status']) ?></span></td>
          <td class="act text-nowrap">
            <?php if ($r['mobile'] !== ''): ?><a class="btn btn-sm btn-icon" target="_blank" rel="noopener" title="WhatsApp" href="https://wa.me/91<?= crm_e($r['mobile']) ?>"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
            <button class="btn btn-sm btn-icon" type="button" title="<?= $canM ? 'Manage' : 'View' ?>" data-bs-toggle="modal" data-bs-target="#manageModal" data-inq='<?= $payload ?>'><i class="bi bi-<?= $canM ? 'pencil-square' : 'eye' ?>"></i></button>
            <?php if ($canM && $r['status'] !== 'Converted'): ?>
              <form method="post" action="inquiries.php" class="d-inline" data-confirm="Convert <?= crm_e($r['name']) ?> into a customer?"><?= crm_csrf_field() ?><input type="hidden" name="do" value="convert"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="view" value="<?= crm_e($view) ?>">
                <button class="btn btn-sm btn-icon" title="Convert to customer"><i class="bi bi-patch-check"></i></button></form>
            <?php endif; ?>
            <?php if ($canD): ?>
              <form method="post" action="inquiries.php" class="d-inline" data-confirm="Delete this inquiry?"><?= crm_csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="view" value="<?= crm_e($view) ?>">
                <button class="btn btn-sm btn-icon text-danger" title="Delete"><i class="bi bi-trash"></i></button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canApi): $key = crm_website_key(); $base = (crm_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.com') . rtrim(dirname((string)$_SERVER['SCRIPT_NAME']), '/\\') . '/inquiry-api.php'; ?>
<div class="panel mt-3">
  <div class="panel-title"><h5><i class="bi bi-globe2 me-1"></i>Website integration</h5>
    <form method="post" action="inquiries.php" data-confirm="Create a new key? The old key stops working immediately."><?= crm_csrf_field() ?><input type="hidden" name="do" value="newkey"><button class="btn btn-sm btn-light border">Generate new key</button></form></div>
  <p class="small text-muted">Your website sends every enquiry form to this address. Each submit creates an inquiry here and rings the bell for the team.</p>
  <div class="row g-2 small">
    <div class="col-lg-6"><label class="form-label">Endpoint (POST)</label><input class="form-control" readonly value="<?= crm_e($base) ?>" onfocus="this.select()"></div>
    <div class="col-lg-6"><label class="form-label">API key</label><input class="form-control" readonly value="<?= crm_e($key) ?>" onfocus="this.select()"></div>
    <div class="col-12"><label class="form-label">Example HTML form for your website</label>
<textarea class="form-control font-monospace" rows="9" readonly onfocus="this.select()">&lt;form method="post" action="<?= crm_e($base) ?>"&gt;
  &lt;input type="hidden" name="key" value="<?= crm_e($key) ?>"&gt;
  &lt;input type="hidden" name="source" value="Website"&gt;
  &lt;input type="text" name="hp" style="display:none" tabindex="-1" autocomplete="off"&gt;  &lt;!-- spam trap, keep empty --&gt;
  &lt;input name="name" placeholder="Your name" required&gt;
  &lt;input name="mobile" placeholder="Mobile"&gt;
  &lt;input name="product" placeholder="Product (optional)"&gt;
  &lt;textarea name="message"&gt;&lt;/textarea&gt;
  &lt;button&gt;Send&lt;/button&gt;
&lt;/form&gt;</textarea></div>
  </div>
</div>
<?php endif; ?>

<?php if ($canM): ?>
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="post" action="inquiries.php"><?= crm_csrf_field() ?><input type="hidden" name="do" value="add"><input type="hidden" name="view" value="<?= crm_e($view) ?>">
    <div class="modal-header"><h5 class="modal-title">Add inquiry</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><div class="row g-3">
      <div class="col-md-6"><label class="form-label">Name <span class="text-danger">*</span></label><input class="form-control" name="name" required maxlength="150"></div>
      <div class="col-md-6"><label class="form-label">Mobile</label><input class="form-control" name="mobile" inputmode="numeric" maxlength="10"></div>
      <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email"></div>
      <div class="col-md-6"><label class="form-label">Source</label><select class="form-select" name="source"><option>Walk-in</option><option>Phone call</option><option>Website</option><option>Housing</option><option>99acres</option><option>REI</option><option>Other</option></select></div>
      <div class="col-md-6"><label class="form-label">Type</label><select class="form-select" name="type"><option>Contact</option><option>Product</option></select></div>
      <div class="col-md-6"><label class="form-label">Product (if any)</label><input class="form-control" name="product" maxlength="150"></div>
      <div class="col-12"><label class="form-label">Message</label><textarea class="form-control" name="message" rows="3" maxlength="3000"></textarea></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-gold">Save inquiry</button></div>
  </form></div></div></div>
<?php endif; ?>

<div class="modal fade" id="manageModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
  <form method="post" action="inquiries.php"><?= crm_csrf_field() ?><input type="hidden" name="do" value="update"><input type="hidden" name="id" id="mId"><input type="hidden" name="view" value="<?= crm_e($view) ?>">
    <div class="modal-header"><h5 class="modal-title" id="mTitle">Inquiry</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <div class="row g-3 mb-3 small">
        <div class="col-md-4"><span class="text-muted">Mobile</span><div id="mMobile"></div></div>
        <div class="col-md-4"><span class="text-muted">Email</span><div id="mEmail"></div></div>
        <div class="col-md-4"><span class="text-muted">Received</span><div id="mRecv"></div></div>
        <div class="col-md-4"><span class="text-muted">Type / source</span><div id="mType"></div></div>
        <div class="col-md-8"><span class="text-muted">Product</span><div id="mProd"></div></div>
        <div class="col-12"><span class="text-muted">Message</span><div id="mMsg" style="white-space:pre-wrap"></div></div>
      </div>
      <fieldset <?= $canM ? '' : 'disabled' ?>><div class="row g-3">
        <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status" id="mStatus"><?php foreach (CRM_INQUIRY_STATUSES as $s): ?><option><?= crm_e($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Follow-up date</label><input type="date" class="form-control" name="followup_date" id="mFollow"></div>
        <div class="col-md-4"><label class="form-label">Assigned to</label><select class="form-select" name="assigned_to" id="mAssign"><option value="0">Unassigned</option><?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>"><?= crm_e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label">Notes (internal)</label><textarea class="form-control" name="notes" id="mNotes" rows="3" maxlength="3000"></textarea></div>
      </div></fieldset>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button><?php if ($canM): ?><button class="btn btn-gold">Save changes</button><?php endif; ?></div>
  </form></div></div></div>
<?php
crm_page_end(<<<'JS'
<script>
/* fills the "Manage inquiry" popup from the data-inq attribute of the row button */
document.getElementById('manageModal').addEventListener('show.bs.modal', function (ev) {
  var d = JSON.parse(ev.relatedTarget.getAttribute('data-inq')), f = ev.target, set = function (id, v) { f.querySelector(id).textContent = v || '-'; }, val = function (id, v) { f.querySelector(id).value = v; };
  f.querySelector('#mTitle').textContent = d.name;
  set('#mMobile', d.mobile); set('#mEmail', d.email); set('#mRecv', d.received); set('#mType', d.type + ' / ' + d.source); set('#mProd', d.product); set('#mMsg', d.message);
  val('#mId', d.id); val('#mStatus', d.status); val('#mFollow', d.followup || ''); val('#mAssign', d.assigned || 0); val('#mNotes', d.notes || '');
});
</script>
JS);
