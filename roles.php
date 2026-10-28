<?php
/* roles.php - Super Admin only: decide what Admin and Manager can see and do */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();

$roles = crm_all('SELECT id, slug, name, description FROM roles ORDER BY id');
$perms = crm_all('SELECT id, perm_key, module, label FROM permissions ORDER BY sort_order, id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_csrf_require();
    try {
        $given = $_POST['perm'] ?? [];                       // perm[roleId][] = permissionId
        $byId  = [];
        $byKey = [];
        foreach ($perms as $p) {
            $byId[(int)$p['id']] = $p;
            $byKey[$p['perm_key']] = (int)$p['id'];
        }
        mysqli_begin_transaction(crm_db());
        foreach ($roles as $r) {
            if ($r['slug'] === 'super_admin') {              // Super Admin always has everything
                continue;
            }
            $want = [];
            foreach ((array)($given[$r['id']] ?? []) as $pid) {
                $pid = (int)$pid;
                if (isset($byId[$pid])) {
                    $want[$pid] = true;
                }
            }
            // "edit / add / delete / manage" without "view" makes no sense: add the view permission automatically
            foreach (array_keys($want) as $pid) {
                $mod = explode('.', $byId[$pid]['perm_key'])[0];
                if (isset($byKey[$mod . '.view'])) {
                    $want[$byKey[$mod . '.view']] = true;
                }
            }
            crm_run('DELETE FROM role_permissions WHERE role_id = ?', 'i', [(int)$r['id']]);
            foreach (array_keys($want) as $pid) {
                crm_run('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', 'ii', [(int)$r['id'], $pid]);
            }
        }
        mysqli_commit(crm_db());
        crm_log('roles', 'permissions', 'Updated role permissions');
        crm_flash('success', 'Permissions saved. They apply immediately to every logged-in employee.');
    } catch (Throwable $ex) {
        mysqli_rollback(crm_db());
        error_log('roles.php: ' . $ex->getMessage());
        crm_flash('danger', 'Could not save permissions.');
    }
    header('Location: roles.php');
    exit;
}

$have = [];
foreach (crm_all('SELECT role_id, permission_id FROM role_permissions') as $x) {
    $have[(int)$x['role_id']][(int)$x['permission_id']] = true;
}
$mods = [];
foreach ($perms as $p) {
    $mods[$p['module']][] = $p;
}
$users = [];
foreach (crm_all("SELECT role_id, COUNT(*) c FROM users WHERE status = 'active' GROUP BY role_id") as $x) {
    $users[(int)$x['role_id']] = (int)$x['c'];
}

crm_page_start('Roles & permissions');
crm_page_head('Roles & permissions', 'Tick what each role is allowed to do. Menus and pages hide automatically for everything that is not ticked.');
echo crm_flash_html();
?>
<div class="row g-3 mb-3">
  <?php foreach ($roles as $r): ?>
    <div class="col-md-4"><div class="panel"><div class="d-flex justify-content-between"><h5 class="mb-1"><?= crm_e($r['name']) ?></h5><span class="badge-s b-blue"><?= (int)($users[(int)$r['id']] ?? 0) ?> active</span></div>
      <small class="text-muted"><?= crm_e($r['description']) ?></small></div></div>
  <?php endforeach; ?>
</div>

<form method="post" action="roles.php">
  <?= crm_csrf_field() ?>
  <div class="panel">
    <div class="table-responsive">
      <table class="table align-middle mb-0 perm-table">
        <thead><tr>
          <th style="width:30%">Permission</th>
          <?php foreach ($roles as $r): ?>
            <th class="text-center"><?= crm_e($r['name']) ?>
              <?php if ($r['slug'] !== 'super_admin'): ?><div class="mt-1"><button type="button" class="btn btn-sm btn-light border py-0" data-col-all="<?= (int)$r['id'] ?>">All</button> <button type="button" class="btn btn-sm btn-light border py-0" data-col-none="<?= (int)$r['id'] ?>">None</button></div><?php endif; ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($mods as $mod => $list): ?>
          <tr class="perm-module"><td colspan="<?= count($roles) + 1 ?>"><i class="bi bi-folder2-open me-1"></i><?= crm_e($mod) ?></td></tr>
          <?php foreach ($list as $p): ?>
            <tr>
              <td><?= crm_e($p['label']) ?><div class="text-muted small"><?= crm_e($p['perm_key']) ?></div></td>
              <?php foreach ($roles as $r):
                  $super = $r['slug'] === 'super_admin';
                  $on    = $super || isset($have[(int)$r['id']][(int)$p['id']]); ?>
                <td class="text-center"><input class="form-check-input" type="checkbox" data-role="<?= (int)$r['id'] ?>"
                    <?= $super ? 'disabled' : 'name="perm[' . (int)$r['id'] . '][]" value="' . (int)$p['id'] . '"' ?> <?= $on ? 'checked' : '' ?>
                    aria-label="<?= crm_e($r['name'] . ': ' . $p['label']) ?>"></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="d-flex gap-2 align-items-center mt-3 flex-wrap">
      <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Save permissions</button>
      <small class="text-muted">Super Admin always has complete access and cannot be limited. Employee, role and permission screens are for Super Admin only.</small>
    </div>
  </div>
</form>
<?php
crm_page_end(<<<'JS'
<script>
/* "All" / "None" buttons above every role column */
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-col-all],[data-col-none]'); if (!b) return;
  var id = b.dataset.colAll || b.dataset.colNone, on = b.hasAttribute('data-col-all');
  document.querySelectorAll('input[data-role="' + id + '"]:not(:disabled)').forEach(function (c) { c.checked = on; });
});
</script>
JS);
