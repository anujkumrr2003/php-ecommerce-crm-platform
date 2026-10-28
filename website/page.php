<?php
/* page.php - CMS pages from the CRM (privacy policy, terms, return policy, about us ...) */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/cart.php';
require_once __DIR__ . '/inc/pricing.php';
require_once __DIR__ . '/inc/layout.php';

$pg = wc_one('SELECT * FROM web_pages WHERE slug = ? AND is_active = 1', 's', [(string)($_GET['slug'] ?? '')]);
if (!$pg) {
    not_found('This page does not exist.');
}
$title = html_entity_decode($pg['title']);
page_start(['title' => $pg['meta_title'] ?: $title, 'description' => $pg['meta_description'] ?: mb_substr(trim(strip_tags($pg['content'])), 0, 160)]);
echo breadcrumb([$title => '']);
echo '<div class="container page-pad"><div class="prose"><h1 style="text-align:center">' . e($title) . '</h1><div>' . page_text((string)$pg['content']) . '</div></div></div>';
page_end();
