<?php
/* tasks.php - follow-ups and to-dos (database) */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
$me = crm_user();

const TASK_VIEWS = [
    'today'   => ["Today's tasks", "t.status = 'Open' AND t.due_date <= CURDATE()"],
    'upcoming'=> ['Upcoming tasks', "t.status = 'Open' AND t.due_date > CURDATE()"],
    'mine'    => ['My tasks', 't.assigned_to = @me'],
    'done'    => ['Completed tasks', "t.status = 'Done'"],
    'all'     => ['All tasks', ''],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_csrf_require();
    $do = (string)($_POST['do'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if (!crm_can('tasks.manage')) {
            throw new RuntimeException('You do not have permission to do this.');
        }
        if ($do === 'add') {
            $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200);
            $due   = (string)($_POST['due_date'] ?? '');
            $d     = DateTime::createFromFormat('!Y-m-d', $due);
            if ($title === '' || !$d || $d->format('Y-m-d') !== $due) {
                throw new RuntimeException('Enter the task and a due date.');
            }
            $type = in_array($_POST['task_type'] ?? '', ['General', 'Follow-up', 'Birthday', 'Anniversary', 'Payment'], true) ? $_POST['task_type'] : 'General';
            $pri  = in_array($_POST['priority'] ?? '', ['Low', 'Medium', 'High'], true) ? $_POST['priority'] : 'Medium';
            $cust = (int)($_POST['customer_id'] ?? 0) ?: null;
            $as   = (int)($_POST['assigned_to'] ?? 0) ?: null;
            crm_run('INSERT INTO tasks (title, details, customer_id, task_type, due_date, priority, assigned_to, created_by) VALUES (?,?,?,?,?,?,?,?)', 'ssisssii',
                [$title, mb_substr(trim((string)($_POST['details'] ?? '')), 0, 500), $cust, $type, $due, $pri, $as, (int)$me['id']]);
            crm_log('tasks', 'add', $title);
            crm_flash('success', 'Task added.');
        } elseif ($do === 'toggle' && $id > 0) {
            crm_run("UPDATE tasks SET status = IF(status = 'Done', 'Open', 'Done'), completed_at = IF(status = 'Done', NOW(), NULL) WHERE id = ?", 'i', [$id]);
            crm_flash('success', 'Task updated.');
        } elseif ($do === 'delete' && $id > 0) {
            crm_run('DELETE FROM tasks WHERE id = ?', 'i', [$id]);
            crm_log('tasks', 'delete', "Task #$id");
            crm_flash('success', 'Task deleted.');
        }
    } catch (RuntimeException $ex) {
        crm_flash('danger', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log('tasks.php: ' . $ex->getMessage());
        crm_flash('danger', 'Could not save. Please try again.');
    }
    header('Location: tasks.php' . (!empty($_POST['view']) ? '?view=' . rawurlencode((string)$_POST['view']) : ''));
    exit;
}

$view = isset(TASK_VIEWS[$_GET['view'] ?? '']) ? (string)$_GET['view'] : 'today';
$cond = str_replace('@me', (string)(int)$me['id'], TASK_VIEWS[$view][1]);
$rows = crm_all('SELECT t.*, c.name AS cust_name, a.name AS assignee FROM tasks t
                   LEFT JOIN customers c ON c.id = t.customer_id LEFT JOIN users a ON a.id = t.assigned_to'
    . ($cond !== '' ? ' WHERE ' . $cond : '') . ' ORDER BY t.status = \'Done\', t.due_date, FIELD(t.priority, \'High\', \'Medium\', \'Low\') LIMIT 5000');
$staff = crm_all("SELECT id, name FROM users WHERE status = 'active' ORDER BY name");
$custs = crm_can('customers.view') ? crm_all('SELECT id, name, mobile FROM customers ORDER BY name LIMIT 3000') : [];
$canM  = crm_can('tasks.manage');
$today = date('Y-m-d');
$pt    = ['High' => 'red', 'Medium' => 'amber', 'Low' => 'gray'];

$views = '<div class="btn-group">';
foreach (TASK_VIEWS as $k => $v) {
    $views .= '<a class="btn btn-sm ' . ($view === $k ? 'btn-primary' : 'btn-outline-primary') . '" href="tasks.php?view=' . $k . '">' . crm_e($v[0]) . '</a>';
}
$views .= '</div>';
crm_page_start(TASK_VIEWS[$view][0]);
crm_page_head(TASK_VIEWS[$view][0], 'Follow-ups and customer tasks that need attention.',
    $views . ($canM ? '<button class="btn btn-gold" type="button" data-bs-toggle="modal" data-bs-target="#taskModal"><i class="bi bi-plus-lg me-1"></i>Add task</button>' : ''));
echo crm_flash_html();
?>
<div class="panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0" data-crm-table>
      <thead><tr><th>Task</th><th>Customer</th><th data-filter="Type">Type</th><th>Due</th><th data-filter="Priority">Priority</th><th data-filter="Assigned">Assigned</th><th data-filter="Status">Status</th><th class="act">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $done = $r['status'] === 'Done'; $late = !$done && $r['due_date'] < $today; ?>
        <tr>
          <td><span class="<?= $done ? 'text-decoration-line-through text-muted' : 'fw-semibold' ?>"><?= crm_e($r['title']) ?></span><?php if ($r['details'] !== ''): ?><br><small class="text-muted"><?= crm_e($r['details']) ?></small><?php endif; ?></td>
          <td><?= crm_e($r['cust_name'] ?: '-') ?></td>
          <td><?= crm_e($r['task_type']) ?></td>
          <td data-sort="<?= crm_e($r['due_date']) ?>"><span class="<?= $late ? 'text-danger fw-semibold' : '' ?>"><?= crm_e(date('j M Y', strtotime((string)$r['due_date']))) ?></span><?= $late ? '<br><small class="text-danger">Overdue</small>' : '' ?></td>
          <td data-value="<?= crm_e($r['priority']) ?>"><span class="badge-s b-<?= $pt[$r['priority']] ?>"><?= crm_e($r['priority']) ?></span></td>
          <td data-value="<?= crm_e($r['assignee'] ?: 'Unassigned') ?>"><?= crm_e($r['assignee'] ?: '-') ?></td>
          <td data-value="<?= $r['status'] ?>"><span class="badge-s b-<?= $done ? 'green' : 'blue' ?>"><?= $r['status'] ?></span></td>
          <td class="act text-nowrap">
            <?php if ($canM): ?>
              <form method="post" action="tasks.php" class="d-inline"><?= crm_csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="view" value="<?= crm_e($view) ?>">
                <button class="btn btn-sm btn-icon" title="<?= $done ? 'Reopen' : 'Mark done' ?>"><i class="bi bi-<?= $done ? 'arrow-counterclockwise' : 'check2-circle' ?>"></i></button></form>
              <form method="post" action="tasks.php" class="d-inline" data-confirm="Delete this task?"><?= crm_csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="view" value="<?= crm_e($view) ?>">
                <button class="btn btn-sm btn-icon text-danger" title="Delete"><i class="bi bi-trash"></i></button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canM): ?>
<div class="modal fade" id="taskModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="post" action="tasks.php"><?= crm_csrf_field() ?><input type="hidden" name="do" value="add"><input type="hidden" name="view" value="<?= crm_e($view) ?>">
    <div class="modal-header"><h5 class="modal-title">Add task</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><div class="row g-3">
      <div class="col-12"><label class="form-label">Task <span class="text-danger">*</span></label><input class="form-control" name="title" required maxlength="200" placeholder="e.g. Call customer about new arrivals"></div>
      <div class="col-md-6"><label class="form-label">Due date <span class="text-danger">*</span></label><input type="date" class="form-control" name="due_date" value="<?= crm_e($today) ?>" required></div>
      <div class="col-md-6"><label class="form-label">Priority</label><select class="form-select" name="priority"><option>Medium</option><option>High</option><option>Low</option></select></div>
      <div class="col-md-6"><label class="form-label">Type</label><select class="form-select" name="task_type"><option>General</option><option>Follow-up</option><option>Birthday</option><option>Anniversary</option><option>Payment</option></select></div>
      <div class="col-md-6"><label class="form-label">Assign to</label><select class="form-select" name="assigned_to"><option value="0">Unassigned</option><?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$me['id'] ? 'selected' : '' ?>><?= crm_e($s['name']) ?></option><?php endforeach; ?></select></div>
      <?php if ($custs): ?><div class="col-12"><label class="form-label">Customer (optional)</label><select class="form-select" name="customer_id"><option value="0">- None -</option><?php foreach ($custs as $c): ?><option value="<?= (int)$c['id'] ?>"><?= crm_e($c['name'] . ' (' . $c['mobile'] . ')') ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="col-12"><label class="form-label">Details</label><input class="form-control" name="details" maxlength="500"></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-gold">Save task</button></div>
  </form></div></div></div>
<?php endif; ?>
<?php crm_page_end(); ?>
