<?php
/* web-categories.php - picture, order and SEO text for every product category (categories come from your products) */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/crud.php';
crm_guard();
require_once __DIR__ . '/includes/webcore/load.php';
wc_sync_categories();

crud_page([
    'title' => 'Website categories', 'item' => 'category', 'table' => 'web_categories', 'order' => 'sort_order, name', 'toggle' => 'is_active',
    'sub' => 'Categories appear automatically from the products you add. Here you give each one a picture, order and SEO text.',
    'defaults' => ['sort_order' => 100, 'is_active' => 1, 'show_in_menu' => 1],
    'columns' => [
        ['label' => 'Image', 'render' => fn($r) => $r['image'] !== '' ? '<img src="' . crm_e(wc_upload_url('web', $r['image'])) . '" alt="" style="height:38px;border-radius:3px;border:1px solid #cdd5e1">' : '-'],
        ['label' => 'Category', 'render' => fn($r) => '<b>' . crm_e($r['name']) . '</b><br><small class="text-muted">/category/' . crm_e($r['slug']) . '</small>'],
        ['label' => 'Products', 'render' => fn($r) => (string)(int)(crm_one('SELECT COUNT(*) c FROM products WHERE category = ?', 's', [$r['name']])['c'] ?? 0)],
        ['label' => 'Order', 'key' => 'sort_order'],
        ['label' => 'In menu', 'filter' => true, 'render' => fn($r) => $r['show_in_menu'] ? 'Yes' : 'No'],
        ['label' => 'Status', 'filter' => true, 'render' => fn($r) => '<span class="badge-s b-' . ($r['is_active'] ? 'green' : 'gray') . '">' . ($r['is_active'] ? 'Visible' : 'Hidden') . '</span>'],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Category name (same as in Products)', 'required' => true, 'max' => 60, 'readonly_on_edit' => true, 'col' => 6],
        ['name' => 'slug', 'label' => 'Web address (slug)', 'max' => 80, 'col' => 6, 'help' => 'Leave empty to create from the name.'],
        ['name' => 'image', 'label' => 'Category image', 'type' => 'image', 'col' => 6],
        ['name' => 'sort_order', 'label' => 'Order (small number first)', 'type' => 'number', 'step' => '1', 'col' => 6],
        ['name' => 'description', 'label' => 'Short description', 'type' => 'textarea', 'max' => 500, 'col' => 12],
        ['name' => 'meta_title', 'label' => 'SEO title', 'max' => 160, 'col' => 6],
        ['name' => 'meta_description', 'label' => 'SEO description', 'max' => 300, 'col' => 6],
        ['name' => 'show_in_menu', 'label' => 'Show in top menu', 'type' => 'checkbox', 'col' => 6],
        ['name' => 'is_active', 'label' => 'Visible on website', 'type' => 'checkbox', 'col' => 6],
    ],
    'before_save' => function (array &$d, ?array $old) {
        $name = $d['name'] ?? $old['name'];
        $slug = wc_slug(($d['slug'] ?? '') !== '' ? $d['slug'] : $name, 'category');
        $row = crm_one('SELECT id FROM web_categories WHERE slug = ? AND id <> ?', 'si', [$slug, (int)($old['id'] ?? 0)]);
        if ($row) {
            return 'This web address is already used by another category.';
        }
        $d['slug'] = $slug;
        return null;
    },
    'before_delete' => function (array $row) {
        $n = (int)(crm_one('SELECT COUNT(*) c FROM products WHERE category = ?', 's', [$row['name']])['c'] ?? 0);
        return $n > 0 ? "This category still has $n product(s). Move or delete them in Products first (or just hide the category)." : null;
    },
]);
