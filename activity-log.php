<?php
/* activity-log.php - who did what (login, customers, bills, settings, employees ...) */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();

$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [1, 7, 30, 90, 365], true)) {
    $days = 30;
}
$rows = crm_all('SELECT * FROM activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY) ORDER BY id DESC LIMIT 5000');

crm_page_start('Activity log');
crm_page_head('Activity log', 'A record of important actions done by your team.');
?>
<div class="panel">
  <form method="get" class="d-flex gap-2 align-items-center mb-2" action="activity-log.php">
    <label class="small text-muted" for="days">Show last</label>
    <select class="form-select form-select-sm w-auto" id="days" name="days" data-autosubmit>
      <?php foreach ([1 => '24 hours', 7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $d => $l): ?>
        <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <div class="table-responsive">
    <table class="table align-middle mb-0" data-crm-table>
      <thead><tr><th>Time</th><th data-filter="User">User</th><th data-filter="Module">Module</th><th data-filter="Action">Action</th><th>Detail</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr><td data-sort="<?= crm_e($r['created_at']) ?>"><?= crm_e(date('j M Y, g:i a', strtotime((string)$r['created_at']))) ?></td>
          <td><?= crm_e($r['user_name'] ?: 'System') ?></td><td><?= crm_e($r['module']) ?></td><td><?= crm_e($r['action']) ?></td>
          <td><?= crm_e($r['detail']) ?></td><td><?= crm_e($r['ip_address']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php crm_page_end(); ?>
