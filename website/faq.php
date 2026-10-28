<?php
/* faq.php */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/cart.php';
require_once __DIR__ . '/inc/pricing.php';
require_once __DIR__ . '/inc/layout.php';

$faqs = wc_all('SELECT * FROM web_faqs WHERE is_active = 1 ORDER BY sort_order, id');
$ld = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags(html_entity_decode($f['answer']))]], $faqs)];
page_start(['title' => 'Frequently asked questions', 'description' => 'Answers about orders, payments, delivery and returns.', 'jsonld' => $faqs ? [json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)] : []]);
echo breadcrumb(['FAQ' => '']);
?>
<div class="container page-pad"><div class="prose faq"><h1 style="text-align:center">Frequently asked questions</h1>
<?php foreach ($faqs as $f): ?><details><summary><?= e($f['question']) ?></summary><div><?= $f['answer'] ?></div></details><?php endforeach; ?>
<?php if (!$faqs): ?><p class="muted" style="text-align:center">No questions yet.</p><?php endif; ?>
<p style="text-align:center;margin-top:30px">Still need help? <a href="<?= e(url('contact')) ?>"><b>Contact us</b></a></p></div></div>
<?php page_end();
