<?php
/*
| notifications.php
|   ?api=poll   GET   called by the bell every few seconds (AJAX)
|   ?api=read   POST  id=<n> or all=1
|   (no api)          full list of notifications
*/
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
$me = crm_user();

if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];
    try {
        if ($api === 'poll') {
            crm_notif_generate();
            $after = max(0, (int)($_GET['after'] ?? 0));
            $max   = crm_notif_max_id();
            $new   = [];
            if ($after > 0 && $max > $after) {
                $new = crm_all(
                    'SELECT n.id, n.title, n.message, n.icon, n.color, n.link FROM notifications n
                      LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = ?
                      WHERE n.id > ? AND r.user_id IS NULL AND ' . crm_notif_visible() . ' ORDER BY n.id ASC LIMIT 5',
                    'ii',
                    [(int)$me['id'], $after]
                );
            }
            $out = [
                'success' => true,
                'unread'  => crm_notif_unread(),
                'max_id'  => $max,
                'new'     => $new,
                'html'    => crm_notif_items_html(crm_notif_feed(8)),
            ];
            session_write_close();
            crm_json($out);
        }
        if ($api === 'read') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !crm_csrf_ok()) {
                crm_json(['success' => false, 'message' => 'Invalid request.'], 419);
            }
            crm_notif_mark_read(!empty($_POST['all']) ? null : (int)($_POST['id'] ?? 0));
            crm_json(['success' => true, 'unread' => crm_notif_unread()]);
        }
        crm_json(['success' => false, 'message' => 'Unknown request.'], 404);
    } catch (Throwable $ex) {
        error_log('notifications.php: ' . $ex->getMessage());
        crm_json(['success' => false, 'message' => 'Server error.'], 500);
    }
}

/* ---------- full page ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_csrf_require();
    crm_notif_mark_read(null);
    crm_flash('success', 'All notifications marked as read.');
    header('Location: notifications.php');
    exit;
}
crm_notif_generate();
$rows  = crm_notif_feed(300);
$types = ['emp_birthday' => 'Employee birthday', 'emp_anniversary' => 'Work anniversary', 'new_inquiry' => 'New inquiry', 'web_order' => 'Website order', 'cust_birthday' => 'Customer birthday',
    'cust_anniversary' => 'Customer anniversary', 'pending_pay' => 'Pending payment', 'low_stock' => 'Low stock', 'inq_followup' => 'Inquiry follow-up', 'tasks_due' => 'Tasks due'];

crm_page_start('Notifications');
crm_page_head('Notifications', 'New inquiries, employee birthdays and work anniversaries, reminders and alerts.',
    '<form method="post" action="notifications.php">' . crm_csrf_field() . '<button class="btn btn-outline-primary" type="submit"><i class="bi bi-check2-all me-1"></i>Mark all as read</button></form>');
echo crm_flash_html();
?>
<div class="panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0" data-crm-table>
      <thead><tr><th data-filter="Type">Type</th><th>Notification</th><th>Details</th><th>When</th><th data-filter="Status">Status</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $n): $link = $n['link'] !== '' ? $n['link'] : 'notifications.php'; ?>
        <tr>
          <td data-value="<?= crm_e($types[$n['type']] ?? 'Other') ?>"><span class="notif-ic <?= crm_e($n['color']) ?> d-inline-grid"><i class="bi bi-<?= crm_e($n['icon']) ?>"></i></span> <?= crm_e($types[$n['type']] ?? 'Other') ?></td>
          <td><a href="<?= crm_e($link) ?>" class="fw-semibold" data-nid="<?= (int)$n['id'] ?>"><?= crm_e($n['title']) ?></a></td>
          <td><?= crm_e($n['message']) ?></td>
          <td data-sort="<?= crm_e($n['created_at']) ?>"><?= crm_e(date('j M Y, g:i a', strtotime((string)$n['created_at']))) ?> <small class="text-muted">(<?= crm_e($n['ago']) ?>)</small></td>
          <td data-value="<?= $n['is_read'] ? 'Read' : 'Unread' ?>"><span class="badge-s b-<?= $n['is_read'] ? 'gray' : 'blue' ?>"><?= $n['is_read'] ? 'Read' : 'Unread' ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php crm_page_end(); ?>
