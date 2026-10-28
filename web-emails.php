<?php
/* web-emails.php - edit the e-mails your customers receive (OTP, order, shipping, refund ...) */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
require_once __DIR__ . '/includes/webcore/load.php';

$defs = wc_email_defaults();

/** example data for the preview / test e-mail */
function web_email_sample(): array
{
    $order = ['subtotal' => 4998, 'discount' => 500, 'coupon_code' => 'WELCOME10', 'shipping_fee' => 0, 'cod_fee' => 0, 'total' => 4498, 'ship_name' => 'Priya Sharma', 'ship_line1' => '12, Rose Garden Road', 'ship_line2' => 'Sector 15',
        'ship_city' => 'Noida', 'ship_state' => 'Uttar Pradesh', 'ship_pin' => '201301', 'ship_mobile' => '9876543210', 'courier' => 'Delhivery', 'tracking_no' => 'DL123456789', 'tracking_url' => '', 'cancel_reason' => 'Changed my mind',
        'order_no' => 'ORD-10025', 'placed_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s'), 'payment_method' => 'razorpay', 'id' => 0];
    $items = [['name' => 'Silk Blend Dupatta', 'size' => 'Free', 'color' => 'Ivory', 'qty' => 1, 'line_total' => 2499, 'image' => ''], ['name' => 'Classic Cotton Kurta', 'size' => 'M', 'color' => 'Navy', 'qty' => 1, 'line_total' => 2499, 'image' => '']];
    $site = wc_site_url();
    return ['name' => 'Priya', 'otp' => '482916', 'order_no' => $order['order_no'], 'order_total' => wc_money($order['total']), 'order_date' => date('j M Y'), 'payment_method' => 'Online payment (Razorpay)',
        'items_html' => wc_items_table_html($order, $items), 'address_html' => wc_address_html($order),
        'tracking_html' => '<div style="background:#faf7f2;border:1px solid #eee8dc;border-radius:3px;padding:12px 16px;font-size:14px">Courier: <b>Delhivery</b><br>Tracking number: <b>DL123456789</b></div>',
        'order_url' => $site . '/account', 'pay_url' => $site . '/account', 'tracking_url' => $site . '/account', 'reason' => 'Changed my mind', 'refund_amount' => wc_money(4498), 'payment_id' => 'pay_Nxxxxxxxxxxxx',
        'refund_note' => 'The amount will reach your original payment method within 5-7 working days.', 'admin_note' => 'Please keep the item in its original packing. Our courier will contact you.', 'unpaid_minutes' => '45'];
}

$key = (string)($_GET['edit'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_csrf_require();
    $key = (string)($_POST['key'] ?? '');
    if (isset($defs[$key])) {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'save') {
            $subj = trim((string)$_POST['subject']);
            $body = (string)$_POST['body_html'];
            if ($subj === '' || trim($body) === '') {
                crm_flash('danger', 'Subject and message cannot be empty.');
            } else {
                wc_run('INSERT INTO web_email_templates (tpl_key, subject, body_html, is_active) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE subject = VALUES(subject), body_html = VALUES(body_html), is_active = VALUES(is_active)', 'sssi', [$key, mb_substr($subj, 0, 200), $body, !empty($_POST['is_active']) ? 1 : 0]);
                crm_log('website', 'email_template', $key);
                crm_flash('success', 'Template saved.');
            }
        } elseif ($do === 'reset') {
            wc_run('DELETE FROM web_email_templates WHERE tpl_key = ?', 's', [$key]);
            crm_flash('success', 'Template reset to the original text.');
        } elseif ($do === 'test') {
            $to = trim((string)$_POST['to']);
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                crm_flash('danger', 'Enter a valid email address.');
            } else {
                [$subject, $html] = wc_render_template($key, web_email_sample());
                [$ok, $err] = wc_send_mail($to, '[TEST] ' . $subject, $html, $key);
                crm_flash($ok ? 'success' : 'danger', $ok ? 'Test e-mail sent to ' . $to : 'Could not send: ' . $err);
            }
        }
    }
    header('Location: web-emails.php?edit=' . urlencode($key));
    exit;
}

crm_page_start('E-mail templates');
if ($key !== '' && isset($defs[$key])) {
    $row = wc_one('SELECT * FROM web_email_templates WHERE tpl_key = ?', 's', [$key]);
    $subject = $row ? $row['subject'] : $defs[$key]['subject'];
    $body    = $row ? $row['body_html'] : $defs[$key]['body'];
    [$pSubj, $pHtml] = wc_render_template($key, web_email_sample());
    crm_page_head($defs[$key]['label'], 'Edit the subject and the message. Use the placeholders shown below.', '<a class="btn btn-light border" href="web-emails.php"><i class="bi bi-arrow-left me-1"></i>All templates</a>');
    echo crm_flash_html(); ?>
<div class="row g-3">
  <div class="col-xl-6"><div class="panel"><form method="post"><?= crm_csrf_field() ?><input type="hidden" name="key" value="<?= crm_e($key) ?>"><input type="hidden" name="do" value="save">
    <label class="form-label">Subject</label><input class="form-control mb-2" name="subject" value="<?= crm_e($subject) ?>" maxlength="200" required>
    <label class="form-label">Message (HTML)</label><textarea class="form-control font-monospace mb-2" name="body_html" rows="16" required><?= crm_e($body) ?></textarea>
    <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" name="is_active" id="act" value="1" <?= !$row || $row['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="act">Send this e-mail</label></div>
    <button class="btn btn-gold"><i class="bi bi-check-lg me-1"></i>Save</button></form>
    <form method="post" class="d-inline" data-confirm="Go back to the original text?"><?= crm_csrf_field() ?><input type="hidden" name="key" value="<?= crm_e($key) ?>"><input type="hidden" name="do" value="reset"><button class="btn btn-light border mt-2">Reset to original</button></form>
    <form method="post" class="row g-2 mt-2 align-items-end"><?= crm_csrf_field() ?><input type="hidden" name="key" value="<?= crm_e($key) ?>"><input type="hidden" name="do" value="test">
      <div class="col"><label class="form-label">Send a test to</label><input class="form-control" type="email" name="to" value="<?= crm_e(crm_user()['email']) ?>"></div><div class="col-auto"><button class="btn btn-outline-primary"><i class="bi bi-send me-1"></i>Send test</button></div></form>
    <div class="small text-muted mt-3"><b>Placeholders:</b> {{name}} {{store_name}} {{store_url}} {{support_email}} {{order_no}} {{order_date}} {{order_total}} {{payment_method}} {{otp}} {{reason}} {{refund_amount}} {{admin_note}} {{items_html}} {{address_html}} {{tracking_html}} {{order_url}} {{tracking_url}} {{pay_url}} {{accent}} {{primary}}</div>
  </div></div>
  <div class="col-xl-6"><div class="panel"><div class="panel-title"><h5>Preview</h5><small class="text-muted"><?= crm_e($pSubj) ?></small></div><iframe style="width:100%;height:640px;border:1px solid #cdd5e1;border-radius:4px" srcdoc="<?= crm_e($pHtml) ?>"></iframe><small class="text-muted">Preview shows the last saved version with sample data.</small></div></div>
</div>
<?php } else {
    $custom = array_column(wc_all('SELECT tpl_key, is_active FROM web_email_templates'), 'is_active', 'tpl_key');
    $log = wc_all('SELECT * FROM web_email_log ORDER BY id DESC LIMIT 100');
    crm_page_head('E-mail templates', 'The professional e-mails your customers receive. Open one to change the wording.');
    echo crm_flash_html(); ?>
<div class="panel mb-3"><div class="table-responsive"><table class="table align-middle mb-0" data-crm-table>
  <thead><tr><th>E-mail</th><th>Subject</th><th data-filter="Version">Version</th><th class="act">Edit</th></tr></thead><tbody>
  <?php foreach ($defs as $k => $d): ?><tr><td><b><?= crm_e($d['label']) ?></b></td><td><?= crm_e(array_key_exists($k, $custom) ? wc_one('SELECT subject FROM web_email_templates WHERE tpl_key = ?', 's', [$k])['subject'] : $d['subject']) ?></td>
    <td data-value="<?= array_key_exists($k, $custom) ? ($custom[$k] ? 'Customised' : 'Switched off') : 'Original' ?>"><?= array_key_exists($k, $custom) ? ($custom[$k] ? '<span class="badge-s b-gold">Customised</span>' : '<span class="badge-s b-gray">Switched off</span>') : '<span class="badge-s b-gray">Original</span>' ?></td>
    <td class="act"><a class="btn btn-sm btn-icon" href="web-emails.php?edit=<?= crm_e($k) ?>"><i class="bi bi-pencil"></i></a></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="panel"><div class="panel-title"><h5>Recent e-mails sent</h5></div><div class="table-responsive"><table class="table align-middle mb-0" data-crm-table>
  <thead><tr><th>When</th><th>To</th><th>Subject</th><th data-filter="Result">Result</th></tr></thead><tbody>
  <?php foreach ($log as $l): ?><tr><td data-sort="<?= crm_e($l['created_at']) ?>"><?= crm_e(date('j M, g:i a', strtotime((string)$l['created_at']))) ?></td><td><?= crm_e($l['to_email']) ?></td><td><?= crm_e($l['subject']) ?></td>
    <td data-value="<?= crm_e($l['status']) ?>"><span class="badge-s b-<?= $l['status'] === 'sent' ? 'green' : 'red' ?>"><?= crm_e($l['status']) ?></span><?= $l['error'] !== '' ? ' <small class="text-danger">' . crm_e($l['error']) . '</small>' : '' ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php }
crm_page_end(); ?>
