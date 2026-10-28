<?php
/* employees.php - Super Admin only: add employees, activate / deactivate, assign role, reset password */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
$me = crm_user();

function emp_text(string $k, int $max): string
{
    return mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
}
function emp_date(string $k): ?string
{
    $v = trim((string)($_POST[$k] ?? ''));
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_csrf_require();
    $do = (string)($_POST['do'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($do === 'save') {
            $name   = emp_text('name', 120);
            $email  = strtolower(emp_text('email', 190));
            $mobile = preg_replace('/\D+/', '', (string)($_POST['mobile'] ?? ''));
            $desig  = emp_text('designation', 80);
            $dob    = emp_date('dob');
            $join   = emp_date('joining_date');
            $role   = (int)($_POST['role_id'] ?? 0) ?: null;
            $status = in_array($_POST['status'] ?? '', ['pending', 'active', 'inactive'], true) ? (string)$_POST['status'] : 'pending';
            $pass   = (string)($_POST['password'] ?? '');

            if ($id > 0 && $id === (int)$me['id']) {          // own role / status fields are locked in the form
                $role   = (int)$me['role_id'];
                $status = 'active';
            }
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter the employee name and a valid email.');
            }
            if ($mobile !== '' && !preg_match('/^[0-9]{10}$/', $mobile)) {
                throw new RuntimeException('Mobile number must be 10 digits.');
            }
            if ($role !== null && !crm_one('SELECT id FROM roles WHERE id = ?', 'i', [$role])) {
                throw new RuntimeException('Selected role does not exist.');
            }
            if (crm_one('SELECT id FROM users WHERE email = ? AND id <> ?', 'si', [$email, $id])) {
                throw new RuntimeException('Another employee already uses this email.');
            }
            if ($status === 'active' && $role === null) {
                throw new RuntimeException('Assign a role (Admin or Manager) before activating the employee.');
            }

            if ($id > 0) {
                $old = crm_one('SELECT * FROM users WHERE id = ?', 'i', [$id]);
                if (!$old) {
                    throw new RuntimeException('Employee not found.');
                }
                // the last active Super Admin can never be demoted or switched off
                if ($old['role_id'] == 1 && ($role !== 1 || $status !== 'active') && (int)crm_one("SELECT COUNT(*) c FROM users WHERE role_id = 1 AND status = 'active' AND id <> ?", 'i', [$id])['c'] === 0) {
                    throw new RuntimeException('At least one active Super Admin is required.');
                }
                if ($id === (int)$me['id'] && ($status !== 'active' || $role !== (int)$me['role_id'])) {
                    throw new RuntimeException('You cannot change your own role or status.');
                }
                crm_run('UPDATE users SET name=?, email=?, mobile=?, designation=?, dob=?, joining_date=?, role_id=?, status=? WHERE id=?',
                    'ssssssisi', [$name, $email, $mobile, $desig, $dob, $join, $role, $status, $id]);
                if ($pass !== '') {
                    if (($pe = crm_password_error($pass)) !== '') {
                        throw new RuntimeException($pe);
                    }
                    crm_run('UPDATE users SET password_hash = ?, must_change_password = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?', 'si', [password_hash($pass, PASSWORD_DEFAULT), $id]);
                }
                crm_log('employees', 'update', "Updated $name ($email)");
                crm_flash('success', 'Employee updated.');
            } else {
                if (($pe = crm_password_error($pass)) !== '') {
                    throw new RuntimeException('Temporary password: ' . $pe);
                }
                crm_run('INSERT INTO users (name,email,mobile,designation,dob,joining_date,password_hash,must_change_password,role_id,status,created_by) VALUES (?,?,?,?,?,?,?,1,?,?,?)',
                    'sssssssisi', [$name, $email, $mobile, $desig, $dob, $join, password_hash($pass, PASSWORD_DEFAULT), $role, $status, (int)$me['id']]);
                crm_log('employees', 'add', "Added $name ($email)");
                crm_flash('success', 'Employee added. They must change the temporary password at first login.');
            }
        } elseif ($do === 'status' && $id > 0) {
            $to = (string)($_POST['to'] ?? '');
            $u  = crm_one('SELECT * FROM users WHERE id = ?', 'i', [$id]);
            if (!$u || !in_array($to, ['active', 'inactive'], true)) {
                throw new RuntimeException('Invalid request.');
            }
            if ($id === (int)$me['id']) {
                throw new RuntimeException('You cannot change your own status.');
            }
            if ($to === 'active' && !(int)$u['role_id']) {
                throw new RuntimeException('Assign a role first (Edit the employee), then activate.');
            }
            if ($to === 'inactive' && (int)$u['role_id'] === 1 && (int)crm_one("SELECT COUNT(*) c FROM users WHERE role_id = 1 AND status = 'active' AND id <> ?", 'i', [$id])['c'] === 0) {
                throw new RuntimeException('At least one active Super Admin is required.');
            }
            crm_run('UPDATE users SET status = ? WHERE id = ?', 'si', [$to, $id]);
            crm_log('employees', $to === 'active' ? 'activate' : 'deactivate', $u['name'] . ' (' . $u['email'] . ')');
            crm_flash('success', $u['name'] . ($to === 'active' ? ' is now active.' : ' has been deactivated.'));
        } elseif ($do === 'role' && $id > 0) {
            $role = (int)($_POST['role_id'] ?? 0) ?: null;
            $u    = crm_one('SELECT * FROM users WHERE id = ?', 'i', [$id]);
            if (!$u) {
                throw new RuntimeException('Employee not found.');
            }
            if ($id === (int)$me['id']) {
                throw new RuntimeException('You cannot change your own role.');
            }
            if ($role !== null && !crm_one('SELECT id FROM roles WHERE id = ?', 'i', [$role])) {
                throw new RuntimeException('Selected role does not exist.');
            }
            if ((int)$u['role_id'] === 1 && $role !== 1 && (int)crm_one("SELECT COUNT(*) c FROM users WHERE role_id = 1 AND status = 'active' AND id <> ?", 'i', [$id])['c'] === 0) {
                throw new RuntimeException('At least one active Super Admin is required.');
            }
            crm_run('UPDATE users SET role_id = ? WHERE id = ?', 'ii', [$role, $id]);
            $rn = $role ? crm_one('SELECT name FROM roles WHERE id = ?', 'i', [$role])['name'] : 'No role';
            crm_log('employees', 'role', $u['name'] . ' -> ' . $rn);
            crm_flash('success', $u['name'] . ' is now: ' . $rn . '.');
        } elseif ($do === 'delete' && $id > 0) {
            $u = crm_one('SELECT * FROM users WHERE id = ?', 'i', [$id]);
            if (!$u) {
                throw new RuntimeException('Employee not found.');
            }
            if ($id === (int)$me['id']) {
                throw new RuntimeException('You cannot delete your own account.');
            }
            if ((int)$u['role_id'] === 1 && (int)crm_one("SELECT COUNT(*) c FROM users WHERE role_id = 1 AND status = 'active' AND id <> ?", 'i', [$id])['c'] === 0) {
                throw new RuntimeException('At least one active Super Admin is required.');
            }
            crm_run('DELETE FROM users WHERE id = ?', 'i', [$id]);
            crm_log('employees', 'delete', $u['name'] . ' (' . $u['email'] . ')');
            crm_flash('success', 'Employee deleted.');
        }
    } catch (RuntimeException $ex) {
        crm_flash('danger', $ex->getMessage());
    } catch (Throwable $ex) {
        error_log('employees.php: ' . $ex->getMessage());
        crm_flash('danger', 'Could not save. Please try again.');
    }
    header('Location: employees.php' . (isset($_POST['view']) && $_POST['view'] !== '' ? '?view=' . rawurlencode((string)$_POST['view']) : ''));
    exit;
}

$roles = crm_all('SELECT id, name, slug FROM roles ORDER BY id');
$view  = in_array($_GET['view'] ?? '', ['pending', 'active', 'inactive'], true) ? (string)$_GET['view'] : '';
$rows  = crm_all('SELECT u.*, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id '
    . ($view !== '' ? "WHERE u.status = '" . $view . "' " : '') . 'ORDER BY u.id');
$cnt = ['' => 0, 'pending' => 0, 'active' => 0, 'inactive' => 0];
foreach (crm_all('SELECT status, COUNT(*) c FROM users GROUP BY status') as $c) {
    $cnt[$c['status']] = (int)$c['c'];
    $cnt[''] += (int)$c['c'];
}
$tone = ['active' => 'green', 'pending' => 'amber', 'inactive' => 'gray'];

crm_page_start('Employees');
crm_page_head('Employees', 'Add employees, activate them and give them the Admin or Manager role.',
    '<button class="btn btn-gold" type="button" data-bs-toggle="modal" data-bs-target="#empModal" data-emp=\'{"id":0}\'><i class="bi bi-person-plus me-1"></i>Add employee</button>');
echo crm_flash_html();
?>
<ul class="nav nav-pills mb-3 gap-1">
  <?php foreach (['' => 'All', 'pending' => 'Waiting for activation', 'active' => 'Active', 'inactive' => 'Inactive'] as $k => $l): ?>
    <li class="nav-item"><a class="nav-link py-1 <?= $view === $k ? 'active' : '' ?>" href="employees.php<?= $k !== '' ? '?view=' . $k : '' ?>"><?= $l ?> <span class="badge bg-light text-dark ms-1"><?= $cnt[$k] ?></span></a></li>
  <?php endforeach; ?>
</ul>

<div class="panel">
  <div class="table-responsive">
    <table class="table align-middle mb-0" data-crm-table>
      <thead><tr>
        <th>Employee</th><th>Mobile</th><th data-filter="Role">Role</th><th data-filter="Status">Status</th>
        <th>Birthday</th><th>Joined</th><th>Last login</th><th class="act">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $self = (int)$r['id'] === (int)$me['id'];
          $payload = json_encode(['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'mobile' => $r['mobile'], 'designation' => $r['designation'],
              'dob' => $r['dob'], 'joining_date' => $r['joining_date'], 'role_id' => (int)$r['role_id'], 'status' => $r['status'], 'self' => $self], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG);
      ?>
        <tr>
          <td><div class="d-flex align-items-center gap-2"><span class="av"><?= crm_e(crm_initials($r['name'])) ?></span>
            <div><div class="fw-semibold"><?= crm_e($r['name']) ?><?= $self ? ' <small class="text-muted">(you)</small>' : '' ?></div><small class="text-muted"><?= crm_e($r['email']) ?><?= $r['designation'] ? ' · ' . crm_e($r['designation']) : '' ?></small></div></div></td>
          <td><?= crm_e($r['mobile'] ?: '-') ?></td>
          <td data-value="<?= crm_e($r['role_name'] ?: 'No role') ?>">
            <?php if ($self): ?><span class="badge-s b-gold"><?= crm_e($r['role_name']) ?></span>
            <?php else: ?>
            <form method="post" action="employees.php" class="d-inline"><?= crm_csrf_field() ?><input type="hidden" name="do" value="role"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="view" value="<?= crm_e($view) ?>">
              <select name="role_id" class="form-select form-select-sm role-pick" aria-label="Role" data-autosubmit>
                <option value="0">No role</option>
                <?php foreach ($roles as $ro): ?><option value="<?= (int)$ro['id'] ?>" <?= (int)$r['role_id'] === (int)$ro['id'] ? 'selected' : '' ?>><?= crm_e($ro['name']) ?></option><?php endforeach; ?>
              </select></form>
            <?php endif; ?>
          </td>
          <td data-value="<?= crm_e(ucfirst($r['status'])) ?>"><span class="badge-s b-<?= $tone[$r['status']] ?>"><?= crm_e(ucfirst($r['status'])) ?></span></td>
          <td><?= $r['dob'] ? crm_e(date('j M', strtotime((string)$r['dob']))) : '-' ?></td>
          <td><?= $r['joining_date'] ? crm_e(date('j M Y', strtotime((string)$r['joining_date']))) : '-' ?></td>
          <td><?= $r['last_login_at'] ? crm_e(date('j M Y, g:i a', strtotime((string)$r['last_login_at']))) : '<span class="text-muted">Never</span>' ?></td>
          <td class="act text-nowrap">
            <?php if (!$self): ?>
              <form method="post" action="employees.php" class="d-inline"><?= crm_csrf_field() ?><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="view" value="<?= crm_e($view) ?>">
                <?php if ($r['status'] === 'active'): ?><input type="hidden" name="to" value="inactive"><button class="btn btn-sm btn-light border" title="Deactivate"><i class="bi bi-person-dash"></i> Deactivate</button>
                <?php else: ?><input type="hidden" name="to" value="active"><button class="btn btn-sm btn-success" title="Activate"><i class="bi bi-person-check"></i> Activate</button><?php endif; ?>
              </form>
            <?php endif; ?>
            <button class="btn btn-sm btn-icon" type="button" title="Edit" data-bs-toggle="modal" data-bs-target="#empModal" data-emp='<?= $payload ?>'><i class="bi bi-pencil"></i></button>
            <?php if (!$self): ?>
              <form method="post" action="employees.php" class="d-inline" data-confirm="Delete <?= crm_e($r['name']) ?>? This cannot be undone."><?= crm_csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-icon text-danger" title="Delete"><i class="bi bi-trash"></i></button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="alert alert-light border mt-3 small mb-0"><i class="bi bi-info-circle me-1"></i>
  An employee can log in only when the status is <b>Active</b> and a <b>role</b> is assigned. New employees get a temporary password and must change it at first login.
  Birthday and joining date are used for the birthday / work-anniversary notifications.</div>

<div class="modal fade" id="empModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
    <form method="post" action="employees.php" autocomplete="off">
      <?= crm_csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="0" id="empId"><input type="hidden" name="view" value="<?= crm_e($view) ?>">
      <div class="modal-header"><h5 class="modal-title" id="empTitle">Add employee</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body"><div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="eName">Full name <span class="text-danger">*</span></label><input class="form-control" id="eName" name="name" maxlength="120" required></div>
        <div class="col-md-6"><label class="form-label" for="eEmail">Email (login) <span class="text-danger">*</span></label><input type="email" class="form-control" id="eEmail" name="email" maxlength="190" required></div>
        <div class="col-md-4"><label class="form-label" for="eMobile">Mobile</label><input class="form-control" id="eMobile" name="mobile" inputmode="numeric" maxlength="10"></div>
        <div class="col-md-4"><label class="form-label" for="eDesig">Designation</label><input class="form-control" id="eDesig" name="designation" maxlength="80" placeholder="e.g. Sales executive"></div>
        <div class="col-md-4"><label class="form-label" for="eRole">Role</label>
          <select class="form-select" id="eRole" name="role_id"><option value="0">No role (cannot log in)</option>
            <?php foreach ($roles as $ro): ?><option value="<?= (int)$ro['id'] ?>"><?= crm_e($ro['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="eDob">Date of birth</label><input type="date" class="form-control" id="eDob" name="dob"></div>
        <div class="col-md-4"><label class="form-label" for="eJoin">Joining date</label><input type="date" class="form-control" id="eJoin" name="joining_date"></div>
        <div class="col-md-4"><label class="form-label" for="eStatus">Status</label>
          <select class="form-select" id="eStatus" name="status"><option value="pending">Pending (not active yet)</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        <div class="col-12"><label class="form-label" for="ePass" id="ePassLbl">Temporary password <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="ePass" name="password" autocomplete="new-password" placeholder="e.g. Welcome@2026">
          <div class="form-text" id="ePassHelp">8+ characters with upper and lower case, a number and a symbol. The employee must change it at first login.</div></div>
      </div></div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-gold" type="submit"><i class="bi bi-check-lg me-1"></i>Save employee</button></div>
    </form>
  </div></div>
</div>
<?php
crm_page_end(<<<'JS'
<script>
/* fills the add / edit modal from the data-emp attribute of the button that opened it */
document.getElementById('empModal').addEventListener('show.bs.modal', function (ev) {
  var d = JSON.parse(ev.relatedTarget.getAttribute('data-emp') || '{"id":0}'), f = ev.target, g = function (i) { return f.querySelector(i); };
  g('#empId').value = d.id || 0;
  g('#empTitle').textContent = d.id ? 'Edit employee' : 'Add employee';
  g('#eName').value = d.name || ''; g('#eEmail').value = d.email || ''; g('#eMobile').value = d.mobile || '';
  g('#eDesig').value = d.designation || ''; g('#eDob').value = d.dob || ''; g('#eJoin').value = d.joining_date || '';
  g('#eRole').value = d.role_id || 0; g('#eStatus').value = d.status || 'pending'; g('#ePass').value = '';
  g('#eRole').disabled = g('#eStatus').disabled = !!d.self;
  g('#ePass').required = !d.id;
  g('#ePassLbl').firstChild.textContent = d.id ? 'Reset password (optional) ' : 'Temporary password ';
});
</script>
JS);
