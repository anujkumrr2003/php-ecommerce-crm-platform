<?php
/* web-reviews.php - approve or remove customer reviews */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
require_once __DIR__ . '/includes/webcore/load.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_csrf_require();
    $id = (int)($_POST['id'] ?? 0);
    $do = (string)($_POST['do'] ?? '');
    if (in_array($do, ['approved', 'rejected', 'pending'], true)) {
        wc_run('UPDATE web_reviews SET status = ? WHERE id = ?', 'si', [$do, $id]);
    } elseif ($do === 'delete') {
        wc_run('DELETE FROM web_reviews WHERE id = ?', 'i', [$id]);
    }
    crm_log('website', 'review_' . $do, '#' . $id);
    crm_flash('success', 'Review updated.');
    header('Location: web-reviews.php');
    exit;
}
$rows = wc_all('SELECT r.*, p.name AS product FROM web_reviews r JOIN products p ON p.id = r.product_id ORDER BY (r.status = \'pending\') DESC, r.id DESC LIMIT 3000');
$tone = ['pending' => 'amber', 'approved' => 'green', 'rejected' => 'red'];
$btn = fn(array $r, string $do, string $icon, string $title, string $cls = '') => '<form method="post" class="d-inline">' . crm_csrf_field() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="do" value="' . $do . '"><button class="btn btn-sm btn-icon ' . $cls . '" title="' . $title . '"><i class="bi bi-' . $icon . '"></i></button></form>';
crm_page_start('Reviews');
crm_page_head('Customer reviews', 'New reviews wait here until you approve them.');
echo crm_flash_html(); ?>
<div class="panel"><div class="table-responsive"><table class="table align-middle mb-0" data-crm-table>
  <thead><tr><th>Product</th><th>Customer</th><th data-filter="Rating">Rating</th><th>Review</th><th>Date</th><th data-filter="Status">Status</th><th class="act">Actions</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><b><?= crm_e($r['product']) ?></b></td><td><?= crm_e($r['name']) ?><?= $r['verified_buyer'] ? ' <i class="bi bi-patch-check-fill text-success" title="Verified buyer"></i>' : '' ?></td>
      <td data-value="<?= (int)$r['rating'] ?> star"><span class="text-warning"><?= str_repeat('&#9733;', (int)$r['rating']) ?></span><span class="text-muted"><?= str_repeat('&#9733;', 5 - (int)$r['rating']) ?></span></td>
      <td><b><?= crm_e($r['title']) ?></b><br><?= crm_e($r['body']) ?></td><td data-sort="<?= crm_e($r['created_at']) ?>"><?= crm_e(date('j M Y', strtotime((string)$r['created_at']))) ?></td>
      <td data-value="<?= crm_e(ucfirst($r['status'])) ?>"><span class="badge-s b-<?= $tone[$r['status']] ?>"><?= crm_e(ucfirst($r['status'])) ?></span></td>
      <td class="act text-nowrap"><?= $r['status'] !== 'approved' ? $btn($r, 'approved', 'check-circle', 'Approve', 'text-success') : '' ?><?= $r['status'] !== 'rejected' ? $btn($r, 'rejected', 'x-circle', 'Reject') : '' ?>
        <form method="post" class="d-inline" data-confirm="Delete this review?"><?= crm_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="do" value="delete"><button class="btn btn-sm btn-icon text-danger" title="Delete"><i class="bi bi-trash"></i></button></form></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php crm_page_end(); ?>
