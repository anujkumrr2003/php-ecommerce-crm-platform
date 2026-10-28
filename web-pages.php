<?php
/* web-pages.php - Privacy Policy, Terms, Return Policy, About Us ... any page you want on the website */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/crud.php';
crm_guard();
require_once __DIR__ . '/includes/webcore/load.php';

crud_page([
    'title' => 'Website pages', 'item' => 'page', 'table' => 'web_pages', 'order' => 'sort_order, id', 'toggle' => 'is_active',
    'sub' => 'Policies and information pages. You can use HTML. Placeholders: {store_name} {store_email} {store_phone} {store_address} {return_days}',
    'defaults' => ['sort_order' => 100, 'is_active' => 1, 'show_in_footer' => 1],
    'columns' => [
        ['label' => 'Title', 'render' => fn($r) => '<b>' . crm_e(html_entity_decode($r['title'])) . '</b>'],
        ['label' => 'Address', 'render' => fn($r) => '<a target="_blank" href="' . crm_e(wc_site_url() . '/page/' . $r['slug']) . '">/page/' . crm_e($r['slug']) . '</a>'],
        ['label' => 'Footer', 'filter' => true, 'render' => fn($r) => $r['show_in_footer'] ? 'Yes' : 'No'],
        ['label' => 'Order', 'key' => 'sort_order'],
        ['label' => 'Status', 'filter' => true, 'render' => fn($r) => '<span class="badge-s b-' . ($r['is_active'] ? 'green' : 'gray') . '">' . ($r['is_active'] ? 'Published' : 'Hidden') . '</span>'],
        ['label' => 'Updated', 'render' => fn($r) => crm_e(date('j M Y', strtotime((string)$r['updated_at'])))],
    ],
    'fields' => [
        ['name' => 'title', 'label' => 'Page title', 'required' => true, 'max' => 150, 'col' => 8],
        ['name' => 'slug', 'label' => 'Web address (slug)', 'max' => 80, 'col' => 4, 'help' => 'Empty = from title'],
        ['name' => 'content', 'label' => 'Page content (HTML)', 'type' => 'textarea', 'rows' => 12, 'code' => true, 'col' => 12],
        ['name' => 'meta_title', 'label' => 'SEO title', 'max' => 160, 'col' => 6],
        ['name' => 'meta_description', 'label' => 'SEO description', 'max' => 300, 'col' => 6],
        ['name' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'step' => '1', 'col' => 4],
        ['name' => 'show_in_footer', 'label' => 'Show link in footer', 'type' => 'checkbox', 'col' => 4],
        ['name' => 'is_active', 'label' => 'Published', 'type' => 'checkbox', 'col' => 4],
    ],
    'before_save' => function (array &$d, ?array $old) {
        $slug = wc_slug(($d['slug'] ?? '') !== '' ? $d['slug'] : $d['title'], 'page');
        if (in_array($slug, ['faq', 'contact', 'cart', 'checkout', 'account', 'login', 'register', 'shop'], true)) {
            return 'This web address is reserved. Please choose another.';
        }
        if (crm_one('SELECT id FROM web_pages WHERE slug = ? AND id <> ?', 'si', [$slug, (int)($old['id'] ?? 0)])) {
            return 'Another page already uses this web address.';
        }
        $d['slug'] = $slug;
        return null;
    },
]);
