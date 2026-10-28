<?php
/* web-banners.php - hero / promo banners of the website. Separate images for desktop and mobile. */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/crud.php';
crm_guard();
require_once __DIR__ . '/includes/webcore/load.php';

$thumb = function (array $r, string $k) {
    return $r[$k] !== '' ? '<img src="' . crm_e(wc_upload_url('web', $r[$k])) . '" alt="" style="height:38px;border-radius:3px;border:1px solid #cdd5e1">' : '<span class="text-muted">-</span>';
};
crud_page([
    'title' => 'Banners', 'item' => 'banner', 'table' => 'web_banners', 'order' => 'position, sort_order, id', 'toggle' => 'is_active',
    'sub' => 'Slides on the home page. Upload a wide image for desktop and a tall/square image for mobile (recommended: desktop 1920x800, mobile 800x1000).',
    'defaults' => ['position' => 'hero', 'sort_order' => 0, 'is_active' => 1, 'text_color' => '#ffffff'],
    'columns' => [
        ['label' => 'Desktop', 'render' => fn($r) => $thumb($r, 'image_desktop')],
        ['label' => 'Mobile', 'render' => fn($r) => $thumb($r, 'image_mobile')],
        ['label' => 'Title', 'render' => fn($r) => '<b>' . crm_e($r['title']) . '</b><br><small class="text-muted">' . crm_e($r['subtitle']) . '</small>'],
        ['label' => 'Position', 'key' => 'position', 'filter' => true],
        ['label' => 'Link', 'key' => 'link'],
        ['label' => 'Order', 'key' => 'sort_order'],
        ['label' => 'Status', 'filter' => true, 'render' => fn($r) => '<span class="badge-s b-' . ($r['is_active'] ? 'green' : 'gray') . '">' . ($r['is_active'] ? 'Active' : 'Hidden') . '</span>'],
    ],
    'fields' => [
        ['name' => 'position', 'label' => 'Position', 'type' => 'select', 'options' => ['hero' => 'Hero slider (top of home page)', 'promo' => 'Promo tiles (offers section)', 'strip' => 'Thin strip banner'], 'col' => 6],
        ['name' => 'sort_order', 'label' => 'Order (small number first)', 'type' => 'number', 'step' => '1', 'col' => 6],
        ['name' => 'title', 'label' => 'Heading', 'max' => 120, 'col' => 6],
        ['name' => 'subtitle', 'label' => 'Sub heading', 'max' => 220, 'col' => 6],
        ['name' => 'button_text', 'label' => 'Button text', 'max' => 40, 'col' => 4, 'placeholder' => 'Shop now'],
        ['name' => 'link', 'label' => 'Button link', 'max' => 255, 'col' => 5, 'placeholder' => '/shop or /category/sarees'],
        ['name' => 'text_color', 'label' => 'Text colour', 'type' => 'color', 'col' => 3, 'default' => '#ffffff'],
        ['name' => 'image_desktop', 'label' => 'Desktop image', 'type' => 'image', 'col' => 6],
        ['name' => 'image_mobile', 'label' => 'Mobile image (optional)', 'type' => 'image', 'col' => 6],
        ['name' => 'starts_at', 'label' => 'Show from (optional)', 'type' => 'date', 'col' => 4],
        ['name' => 'ends_at', 'label' => 'Show until (optional)', 'type' => 'date', 'col' => 4],
        ['name' => 'is_active', 'label' => 'Active', 'type' => 'checkbox', 'col' => 4],
    ],
]);
