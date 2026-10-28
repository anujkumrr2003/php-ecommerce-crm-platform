<?php
/* web-products.php - which products show on the website, featured / new / best seller flags and SEO text.
   (Products themselves are added in Products - they appear on the website automatically.) */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
require_once __DIR__ . '/includes/webcore/load.php';
if (!function_exists('crm_web_urls_autoset')) {
    require_once __DIR__ . '/includes/crud.php';
}
crm_web_urls_autoset();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_csrf_require();
    $pid = (int)($_POST['id'] ?? 0);
    try {
        if (!crm_one('SELECT id FROM products WHERE id = ?', 'i', [$pid])) {
            throw new RuntimeException('Product not found.');
        }
        wc_run('INSERT IGNORE INTO web_product_meta (product_id) VALUES (?)', 'i', [$pid]);
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'flag' && in_array($_POST['f'] ?? '', ['show_on_website', 'is_featured', 'is_new', 'is_bestseller'], true)) {
            $f = $_POST['f'];
            wc_run("UPDATE web_product_meta SET `$f` = 1 - `$f` WHERE product_id = ?", 'i', [$pid]);
        } elseif ($do === 'save') {
            wc_run('UPDATE web_product_meta SET short_description = ?, meta_title = ?, meta_description = ?, sort_order = ? WHERE product_id = ?', 'sssii',
                [mb_substr(trim((string)$_POST['short_description']), 0, 300), mb_substr(trim((string)$_POST['meta_title']), 0, 160), mb_substr(trim((string)$_POST['meta_description']), 0, 300), (int)$_POST['sort_order'], $pid]);
            crm_flash('success', 'Saved.');
        }
        crm_log('website', 'product_listing', '#' . $pid);
    } catch (Throwable $e) {
        crm_flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'Could not save.');
    }
    header('Location: web-products.php');
    exit;
}

$rows = wc_all("SELECT p.id, p.name, p.category, p.price, p.status, COALESCE(SUM(v.stock),0) AS stock,
                       COALESCE(m.show_on_website,1) AS show_on_website, COALESCE(m.is_featured,0) AS is_featured, COALESCE(m.is_new,0) AS is_new, COALESCE(m.is_bestseller,0) AS is_bestseller,
                       COALESCE(m.short_description,'') AS short_description, COALESCE(m.meta_title,'') AS meta_title, COALESCE(m.meta_description,'') AS meta_description, COALESCE(m.sort_order,0) AS sort_order,
                       (SELECT file_name FROM product_images i WHERE i.product_id = p.id ORDER BY i.id LIMIT 1) AS img
                  FROM products p LEFT JOIN product_variants v ON v.product_id = p.id LEFT JOIN web_product_meta m ON m.product_id = p.id
                 GROUP BY p.id, m.product_id ORDER BY p.id DESC LIMIT 5000");
$flag = function (array $r, string $f, string $icon) {
    return '<form method="post" class="d-inline">' . crm_csrf_field() . '<input type="hidden" name="do" value="flag"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="f" value="' . $f . '">'
        . '<button class="btn btn-sm btn-icon" title="' . $f . '"><i class="bi bi-' . $icon . ($r[$f] ? '-fill text-warning' : '') . '"></i></button></form>';
};
crm_page_start('Products on website');
crm_page_head('Products on website', 'New products show on the website automatically. Use this screen only to hide a product or to mark it Featured / New / Best seller.',
    crm_can('products.add') ? '<a class="btn btn-gold" href="products.php?new=1"><i class="bi bi-plus-lg me-1"></i>Add product (Products)</a>' : '');
echo crm_flash_html(); ?>
<div class="panel"><div class="table-responsive"><table class="table align-middle mb-0" data-crm-table>
  <thead><tr><th>Product</th><th data-filter="Category">Category</th><th>Price</th><th>Stock</th><th data-filter="On website">On website</th><th>Featured</th><th>New</th><th>Best seller</th><th class="act">SEO</th></tr></thead><tbody>
  <?php foreach ($rows as $r):
      $vis = $r['status'] === 'Active' && $r['show_on_website'];
      $json = json_encode(['id' => (int)$r['id'], 'short_description' => $r['short_description'], 'meta_title' => $r['meta_title'], 'meta_description' => $r['meta_description'], 'sort_order' => (int)$r['sort_order']], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>
    <tr><td><div class="d-flex align-items-center gap-2"><?php if ($r['img']): ?><img src="<?= crm_e(wc_upload_url('products', $r['img'])) ?>" alt="" style="width:34px;height:42px;object-fit:cover;border-radius:3px;border:1px solid #cdd5e1"><?php endif; ?><b><?= crm_e($r['name']) ?></b></div></td>
      <td data-value="<?= crm_e($r['category']) ?>"><?= crm_e($r['category']) ?></td><td><?= wc_money($r['price']) ?></td>
      <td><?= (int)$r['stock'] <= 0 ? '<span class="text-danger">Out</span>' : (int)$r['stock'] ?></td>
      <td data-value="<?= $vis ? 'Visible' : 'Hidden' ?>"><?= $flag($r, 'show_on_website', 'eye') ?> <?= $vis ? '<span class="badge-s b-green">Visible</span>' : '<span class="badge-s b-gray">' . ($r['status'] !== 'Active' ? 'Inactive product' : 'Hidden') . '</span>' ?></td>
      <td><?= $flag($r, 'is_featured', 'star') ?></td><td><?= $flag($r, 'is_new', 'stars') ?></td><td><?= $flag($r, 'is_bestseller', 'trophy') ?></td>
      <td class="act"><button class="btn btn-sm btn-icon" type="button" data-bs-toggle="modal" data-bs-target="#crudModal" data-title="SEO &amp; description" data-row='<?= $json ?>'><i class="bi bi-pencil"></i></button></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<div class="modal fade" id="crudModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><?= crm_csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="0">
  <div class="modal-header"><h5 class="modal-title"><span data-fill-title></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-12"><label class="form-label">Short description (product cards &amp; search results)</label><textarea class="form-control" name="short_description" rows="2" maxlength="300"></textarea></div>
    <div class="col-12"><label class="form-label">SEO title</label><input class="form-control" name="meta_title" maxlength="160"></div>
    <div class="col-12"><label class="form-label">SEO description</label><textarea class="form-control" name="meta_description" rows="2" maxlength="300"></textarea></div>
    <div class="col-6"><label class="form-label">Sort order (small first)</label><input class="form-control" type="number" name="sort_order" step="1"></div>
  </div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-gold">Save</button></div></form></div></div></div>
<?php crm_page_end('<script>window.CRUD_UPLOAD="";</script>'); ?>
