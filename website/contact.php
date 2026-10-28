<?php
/* contact.php - the message becomes an Inquiry in the CRM (bell rings for the team) */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/cart.php';
require_once __DIR__ . '/inc/pricing.php';
require_once __DIR__ . '/inc/layout.php';

$u = user();
$f = ['name' => $u['name'] ?? '', 'email' => $u['email'] ?? '', 'mobile' => $u['mobile'] ?? '', 'message' => ''];
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $f = ['name' => post('name', 150), 'email' => post('email', 190), 'mobile' => preg_replace('/\D+/', '', post('mobile', 15)), 'message' => post('message', 2000)];
    if (post('website') !== '') {
        redirect('contact');
    }
    if (mb_strlen($f['name']) < 2) {
        $err = 'Please enter your name.';
    } elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL) && strlen($f['mobile']) < 10) {
        $err = 'Please give a valid email or mobile number so that we can reply.';
    } elseif (mb_strlen($f['message']) < 5) {
        $err = 'Please write your message.';
    } elseif (!wc_throttle('contact:' . wc_ip(), 5, 3600)) {
        $err = 'Too many messages. Please try later.';
    } else {
        $mobile = substr($f['mobile'], -10);
        wc_run("INSERT INTO inquiries (name, mobile, email, inquiry_type, message, source, ip_address) VALUES (?,?,?,'Contact',?,?,?)", 'ssssss', [$f['name'], $mobile, filter_var($f['email'], FILTER_VALIDATE_EMAIL) ? $f['email'] : '', $f['message'], 'Website', wc_ip()]);
        $id = wc_insert_id();
        wc_crm_notify('new_inquiry', 'New inquiry from ' . $f['name'], mb_strimwidth($f['message'], 0, 90, '...') . ' (Website)', 'inquiries.php?view=new', 'chat-dots', 'blue', 'inquiries.view', 'inq:' . $id);
        flash('success', 'Thank you! We have received your message and will reply soon.');
        redirect('contact');
    }
}
$b = wc_brand();
page_start(['title' => 'Contact us', 'description' => 'Write to us or call us - we are happy to help.']);
echo breadcrumb(['Contact us' => '']);
?>
<div class="container page-pad"><h1 style="text-align:center">Contact us</h1>
  <div class="row2" style="gap:44px;max-width:1000px;margin:26px auto 0;align-items:start">
    <div class="box"><h3>Get in touch</h3>
      <?php if ($b['addr'] !== ''): ?><p><i class="bi bi-geo-alt"></i> <?= e($b['addr']) ?></p><?php endif; ?>
      <?php if ($b['phone'] !== ''): ?><p><i class="bi bi-telephone"></i> <a href="tel:<?= e(preg_replace('/[^\d+]/', '', $b['phone'])) ?>"><?= e($b['phone']) ?></a></p><?php endif; ?>
      <?php if ($b['email'] !== ''): ?><p><i class="bi bi-envelope"></i> <a href="mailto:<?= e($b['email']) ?>"><?= e($b['email']) ?></a></p><?php endif; ?>
      <p class="muted small">We usually reply within one working day.</p></div>
    <div class="box"><h3>Send us a message</h3><?= $err !== '' ? '<div class="alert alert-danger">' . e($err) . '</div>' : flash_html() ?>
      <form method="post" action="<?= e(url('contact')) ?>"><?= csrf_field() ?><div style="position:absolute;left:-9999px" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
        <div class="field"><label>Your name</label><input name="name" value="<?= e($f['name']) ?>" required></div>
        <div class="row2"><div class="field"><label>Email</label><input type="email" name="email" value="<?= e($f['email']) ?>"></div><div class="field"><label>Mobile</label><input name="mobile" value="<?= e($f['mobile']) ?>" inputmode="numeric" maxlength="12"></div></div>
        <div class="field"><label>Message</label><textarea name="message" rows="5" required><?= e($f['message']) ?></textarea></div><button class="btn btn-primary">Send message</button></form></div></div></div>
<?php page_end();
