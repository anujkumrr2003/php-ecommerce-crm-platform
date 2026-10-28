<?php
/* web-home.php - which sections appear on the home page, in what order and with which heading */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/crud.php';
crm_guard();
require_once __DIR__ . '/includes/webcore/load.php';

$names = ['categories' => 'Category tiles', 'featured' => 'Featured products', 'promo' => 'Promo banners (from Banners -> Promo)', 'new_arrivals' => 'New arrivals',
    'features' => 'Trust badges (shipping, returns, secure payment)', 'bestsellers' => 'Best sellers', 'reviews' => 'Customer reviews', 'newsletter' => 'Newsletter signup'];
crud_page([
    'title' => 'Home page sections', 'item' => 'section', 'table' => 'web_home_sections', 'order' => 'sort_order, id', 'toggle' => 'is_active',
    'allow_add' => false, 'allow_delete' => false,
    'sub' => 'Turn sections on or off, change their heading and the order in which they appear below the hero slider. Hero slides are managed in Banners.',
    'columns' => [
        ['label' => 'Section', 'render' => fn($r) => '<b>' . crm_e($names[$r['section_key']] ?? $r['section_key']) . '</b>'],
        ['label' => 'Heading', 'render' => fn($r) => crm_e($r['title']) . '<br><small class="text-muted">' . crm_e($r['subtitle']) . '</small>'],
        ['label' => 'Items', 'key' => 'item_limit'],
        ['label' => 'Order', 'key' => 'sort_order'],
        ['label' => 'Status', 'filter' => true, 'render' => fn($r) => '<span class="badge-s b-' . ($r['is_active'] ? 'green' : 'gray') . '">' . ($r['is_active'] ? 'Shown' : 'Hidden') . '</span>'],
    ],
    'fields' => [
        ['name' => 'title', 'label' => 'Heading', 'max' => 120, 'col' => 6],
        ['name' => 'subtitle', 'label' => 'Sub heading', 'max' => 220, 'col' => 6],
        ['name' => 'item_limit', 'label' => 'How many items', 'type' => 'number', 'step' => '1', 'min' => 1, 'col' => 4],
        ['name' => 'sort_order', 'label' => 'Order (small number first)', 'type' => 'number', 'step' => '1', 'col' => 4],
        ['name' => 'is_active', 'label' => 'Show on home page', 'type' => 'checkbox', 'col' => 4],
    ],
]);
