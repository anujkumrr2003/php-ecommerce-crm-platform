<?php
/* web-settings.php - everything about the online store: address, theme, social, SEO, shipping, payments (Razorpay), e-mail */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/crud.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
require_once __DIR__ . '/includes/webcore/load.php';
crm_web_urls_autoset();

const WEB_FONTS_H = ['Cormorant Garamond', 'Playfair Display', 'Bodoni Moda', 'Cinzel', 'DM Serif Display', 'Libre Baskerville', 'Marcellus', 'Prata'];
const WEB_FONTS_B = ['Jost', 'Inter', 'Montserrat', 'Poppins', 'Lato', 'Nunito Sans', 'Raleway', 'DM Sans'];

/* which keys each tab may save: key => [type, max]   (types: text, url, num, bool, color, select:a|b, secret) */
$TABS = [
    'general'  => ['web_site_url' => ['url', 200], 'web_crm_url' => ['url', 200], 'web_tagline' => ['text', 160], 'web_announcement' => ['text', 200], 'web_announcement_on' => ['bool'], 'web_social_whatsapp' => ['text', 20], 'web_maintenance' => ['bool']],
    'theme'    => ['web_color_primary' => ['color'], 'web_color_accent' => ['color'], 'web_color_bg' => ['color'], 'web_color_text' => ['color'], 'web_color_footer' => ['color'],
                   'web_font_heading' => ['select:' . implode('|', WEB_FONTS_H)], 'web_font_body' => ['select:' . implode('|', WEB_FONTS_B)]],
    'social'   => ['web_social_instagram' => ['url', 200], 'web_social_facebook' => ['url', 200], 'web_social_youtube' => ['url', 200], 'web_social_twitter' => ['url', 200], 'web_social_pinterest' => ['url', 200]],
    'seo'      => ['web_meta_title' => ['text', 160], 'web_meta_description' => ['text', 300], 'web_analytics_id' => ['text', 30]],
    'shipping' => ['web_ship_flat' => ['num'], 'web_ship_free_above' => ['num'], 'web_cod_enabled' => ['bool'], 'web_cod_fee' => ['num'], 'web_cod_max' => ['num'], 'web_prices_include_gst' => ['bool'],
                   'web_min_order' => ['num'], 'web_return_days' => ['num'], 'web_unpaid_expiry_min' => ['num'], 'web_per_page' => ['num']],
    'payments' => ['web_rzp_enabled' => ['bool'], 'web_rzp_key_id' => ['text', 60], 'web_rzp_key_secret' => ['secret', 80], 'web_rzp_webhook_secret' => ['secret', 80]],
    'email'    => ['web_mail_driver' => ['select:mail|smtp|log'], 'web_smtp_host' => ['text', 120], 'web_smtp_port' => ['num'], 'web_smtp_secure' => ['select:tls|ssl|none'], 'web_smtp_user' => ['text', 120],
                   'web_smtp_pass' => ['secret', 120], 'web_mail_from_name' => ['text', 80], 'web_mail_from_email' => ['text', 120]],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_csrf_require();
    $tab = (string)($_POST['tab'] ?? '');
    try {
        if (!isset($TABS[$tab]) && !in_array($tab, ['test_rzp', 'test_mail'], true)) {
            throw new RuntimeException('Unknown settings tab.');
        }
        if ($tab === 'test_rzp') {
            [$ok, $res] = wc_rzp_call('GET', '/orders?count=1');
            crm_flash($ok ? 'success' : 'danger', $ok ? 'Razorpay connection works. Your Key ID and Key Secret are correct.' : 'Razorpay said: ' . $res);
        } elseif ($tab === 'test_mail') {
            $to = trim((string)($_POST['to'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid email address to send the test to.');
            }
            [$ok, $err] = wc_send_template('welcome', $to, ['name' => crm_user()['name']]);
            crm_flash($ok ? 'success' : 'danger', $ok ? 'Test email sent to ' . $to . '. Check the inbox (and spam folder).' : 'Email could not be sent: ' . $err);
        } else {
            foreach ($TABS[$tab] as $key => $spec) {
                $t = explode(':', $spec[0])[0];
                $v = trim((string)($_POST[$key] ?? ''));
                if ($t === 'bool') {
                    $v = !empty($_POST[$key]) ? '1' : '0';
                } elseif ($t === 'num') {
                    if ($v === '' || !is_numeric($v) || (float)$v < 0) {
                        throw new RuntimeException('Please enter a valid number for "' . str_replace('web_', '', $key) . '".');
                    }
                } elseif ($t === 'color') {
                    $v = preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : '#000000';
                } elseif ($t === 'select') {
                    if (!in_array($v, explode('|', substr($spec[0], 7)), true)) {
                        throw new RuntimeException('Invalid choice for "' . str_replace('web_', '', $key) . '".');
                    }
                } elseif ($t === 'url') {
                    if ($v !== '' && !preg_match('#^https?://#i', $v)) {
                        throw new RuntimeException('"' . str_replace('web_', '', $key) . '" must start with http:// or https://');
                    }
                    $v = rtrim($v, '/');
                } elseif ($t === 'secret') {
                    if ($v === '') {
                        continue;                                   // blank = keep the saved secret
                    }
                } elseif (isset($spec[1]) && mb_strlen($v) > $spec[1]) {
                    throw new RuntimeException('"' . str_replace('web_', '', $key) . '" is too long.');
                }
                wc_set($key, $v);
            }
            if ($tab === 'payments' && wc_setting('web_rzp_enabled') === '1' && (wc_setting('web_rzp_key_id') === '' || wc_setting('web_rzp_key_secret') === '')) {
                wc_set('web_rzp_enabled', '0');
                throw new RuntimeException('Enter both Razorpay Key ID and Key Secret before switching Razorpay on.');
            }
            if ($tab === 'seo' || $tab === 'general') {                // favicon / og image
                foreach (['web_favicon' => 'favicon_file', 'web_og_image' => 'og_file'] as $key => $input) {
                    $f = crm_upload_image($input, 'web', 2);
                    if ($f !== '') {
                        crm_delete_upload('web', wc_setting($key));
                        wc_set($key, $f);
                    }
                }
            }
            crm_log('website', 'settings', $tab);
            crm_flash('success', 'Settings saved.');
        }
    } catch (RuntimeException $e) {
        crm_flash('danger', $e->getMessage());
    } catch (Throwable $e) {
        error_log('web-settings: ' . $e->getMessage());
        crm_flash('danger', 'Could not save. Please try again.');
    }
    header('Location: web-settings.php#' . preg_replace('/^test_(rzp)$/', 'payments', preg_replace('/^test_mail$/', 'email', $tab)));
    exit;
}

$S = fn(string $k, string $d = '') => crm_e(wc_setting($k, $d));
$chk = fn(string $k) => wc_setting($k, '0') === '1' ? 'checked' : '';
$site = wc_site_url();
crm_page_start('Website settings');
crm_page_head('Website settings', 'Theme, payment gateway, shipping, e-mail and more for your online store.');
echo crm_flash_html();
$tabs = ['general' => 'General', 'theme' => 'Colours & fonts', 'social' => 'Social media', 'seo' => 'SEO', 'shipping' => 'Shipping & checkout', 'payments' => 'Payments (Razorpay)', 'email' => 'E-mail (SMTP)'];
echo '<ul class="nav nav-tabs mb-3" role="tablist">';
foreach ($tabs as $k => $l) {
    echo '<li class="nav-item"><button class="nav-link' . ($k === 'general' ? ' active' : '') . '" data-bs-toggle="tab" data-bs-target="#' . $k . '" type="button" role="tab">' . $l . '</button></li>';
}
echo '</ul><div class="tab-content">';
$open = fn(string $k, string $first = '') => '<div class="tab-pane fade' . ($k === 'general' ? ' show active' : '') . '" id="' . $k . '"><div class="panel"><form method="post" enctype="multipart/form-data" autocomplete="off">' . crm_csrf_field() . '<input type="hidden" name="tab" value="' . $k . '"><div class="row g-3">';
$close = '</div><div class="mt-3"><button class="btn btn-gold" type="submit"><i class="bi bi-check-lg me-1"></i>Save settings</button></div></form></div></div>';
$in = fn(string $k, string $label, string $help = '', int $col = 6, string $type = 'text', string $ph = '') => '<div class="col-md-' . $col . '"><label class="form-label">' . $label . '</label><input class="form-control" type="' . $type . '" name="' . $k . '" value="' . $S($k) . '"' . ($ph !== '' ? ' placeholder="' . crm_e($ph) . '"' : '') . '>' . ($help !== '' ? '<div class="form-text">' . $help . '</div>' : '') . '</div>';
$sw = fn(string $k, string $label, int $col = 6) => '<div class="col-md-' . $col . '"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" role="switch" name="' . $k . '" id="' . $k . '" value="1" ' . $chk($k) . '><label class="form-check-label" for="' . $k . '">' . $label . '</label></div></div>';

/* ---- general ---- */
echo $open('general');
echo $in('web_site_url', 'Website address (URL) <span class="text-danger">*</span>', 'Where your online store is installed, e.g. https://www.yourstore.com . Used in e-mails and links.', 6, 'url', 'https://www.yourstore.com');
echo $in('web_crm_url', 'CRM address (URL)', 'Detected automatically. Product and banner pictures are loaded from here.', 6, 'url');
echo $in('web_tagline', 'Tagline (shown in header / footer)', '', 6) . $in('web_social_whatsapp', 'WhatsApp number for chat button', 'With country code, e.g. 919876543210', 6);
echo $in('web_announcement', 'Announcement bar text', 'The thin bar at the very top of the website.', 8) . $sw('web_announcement_on', 'Show announcement bar', 4);
echo '<div class="col-md-6"><label class="form-label">Favicon (small icon in browser tab)</label><input class="form-control" type="file" name="favicon_file" accept="image/png,image/jpeg,image/webp">' . (wc_setting('web_favicon') !== '' ? '<div class="form-text">Current: ' . $S('web_favicon') . '</div>' : '') . '</div>';
echo $sw('web_maintenance', 'Maintenance mode (website shows "back soon" page)', 6);
echo '<div class="col-12 small text-muted">Store name, logo, phone, e-mail and address are taken from <a href="settings.php">General Settings</a>.</div>';
echo $close;

/* ---- theme ---- */
echo $open('theme');
foreach (['web_color_primary' => ['Primary / dark colour', '#1b1b1f'], 'web_color_accent' => ['Accent (gold) colour', '#b8935a'], 'web_color_bg' => ['Page background', '#faf7f2'], 'web_color_text' => ['Text colour', '#2a2a2e'], 'web_color_footer' => ['Footer background', '#141417']] as $k => [$l, $d]) {
    echo '<div class="col-6 col-md-2"><label class="form-label">' . $l . '</label><input type="color" class="form-control form-control-color w-100" name="' . $k . '" value="' . $S($k, $d) . '"></div>';
}
$sel = fn(string $k, string $label, array $opts) => '<div class="col-md-6"><label class="form-label">' . $label . '</label><select class="form-select" name="' . $k . '">' . implode('', array_map(fn($o) => '<option' . (wc_setting($k) === $o ? ' selected' : '') . '>' . crm_e($o) . '</option>', $opts)) . '</select></div>';
echo $sel('web_font_heading', 'Heading font (elegant serif)', WEB_FONTS_H) . $sel('web_font_body', 'Body font', WEB_FONTS_B);
echo '<div class="col-12"><div class="p-3 rounded" style="background:' . $S('web_color_bg', '#faf7f2') . ';border:1px solid #cdd5e1"><div style="font-family:\'' . $S('web_font_heading') . '\',serif;font-size:26px;color:' . $S('web_color_primary') . '">Preview heading</div><span style="color:' . $S('web_color_text') . '">Body text looks like this.</span> <span style="display:inline-block;background:' . $S('web_color_primary') . ';color:#fff;padding:4px 14px;border-radius:3px;margin-left:8px">Button</span> <span style="color:' . $S('web_color_accent') . '">Accent</span></div><div class="form-text">Preview shows the colours currently saved.</div></div>';
echo $close;

/* ---- social ---- */
echo $open('social');
foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook', 'youtube' => 'YouTube', 'twitter' => 'X (Twitter)', 'pinterest' => 'Pinterest'] as $k => $l) {
    echo $in('web_social_' . $k, $l . ' page link', '', 6, 'url', 'https://');
}
echo $close;

/* ---- seo ---- */
echo $open('seo');
echo $in('web_meta_title', 'Home page title (SEO)', 'Shown in Google results. 50-60 characters is ideal.', 12) . $in('web_meta_description', 'Home page description (SEO)', 'Up to 160 characters.', 12);
echo $in('web_analytics_id', 'Google Analytics ID', 'Example: G-XXXXXXXXXX (optional)', 6);
echo '<div class="col-md-6"><label class="form-label">Share image (when the link is shared on WhatsApp / Facebook)</label><input class="form-control" type="file" name="og_file" accept="image/png,image/jpeg,image/webp">' . (wc_setting('web_og_image') !== '' ? '<div class="form-text">Current: ' . $S('web_og_image') . '</div>' : '') . '</div>';
echo '<div class="col-12 small text-muted">Page URLs are search-friendly automatically (/product/..., /category/...). A sitemap is available at <b>' . crm_e($site ?: 'your-site') . '/sitemap.xml</b> - submit it in Google Search Console.</div>';
echo $close;

/* ---- shipping & checkout ---- */
echo $open('shipping');
echo $in('web_ship_flat', 'Shipping charge (Rs.)', 'Charged on every order below the free-shipping amount.', 4, 'number') . $in('web_ship_free_above', 'Free shipping above (Rs.)', '0 = never free', 4, 'number') . '<div class="col-md-4 pt-4"><a href="web-shipping.php">State-wise shipping rates &rarr;</a></div>';
echo $sw('web_cod_enabled', 'Allow Cash on Delivery', 4) . $in('web_cod_fee', 'COD handling fee (Rs.)', '', 4, 'number') . $in('web_cod_max', 'COD allowed up to order value (Rs.)', '0 = no limit', 4, 'number');
echo $sw('web_prices_include_gst', 'Product prices include GST (recommended)', 4) . $in('web_min_order', 'Minimum order value (Rs.)', '0 = none', 4, 'number') . $in('web_return_days', 'Return window (days after delivery)', '0 = returns not accepted', 4, 'number');
echo $in('web_unpaid_expiry_min', 'Hold stock for unpaid online orders (minutes)', 'After this the order is cancelled and stock released.', 4, 'number') . $in('web_per_page', 'Products per page', '', 4, 'number');
echo $close;

/* ---- payments ---- */
$hook = $site !== '' ? $site . '/payment/webhook' : '(set your website address first)/payment/webhook';
echo $open('payments');
echo $sw('web_rzp_enabled', 'Accept online payments with Razorpay (UPI, cards, net banking, wallets)', 12);
echo $in('web_rzp_key_id', 'Razorpay Key ID', 'From Razorpay Dashboard -> Settings -> API Keys. Starts with rzp_test_ (test) or rzp_live_ (live).', 6, 'text', 'rzp_live_xxxxxxxxxxxx');
echo '<div class="col-md-6"><label class="form-label">Razorpay Key Secret</label><input class="form-control" type="password" name="web_rzp_key_secret" autocomplete="new-password" placeholder="' . (wc_setting('web_rzp_key_secret') !== '' ? '******** saved - leave blank to keep' : 'Paste your key secret') . '"><div class="form-text">Stored privately. It is never shown again.</div></div>';
echo '<div class="col-md-6"><label class="form-label">Webhook secret (recommended)</label><input class="form-control" type="password" name="web_rzp_webhook_secret" autocomplete="new-password" placeholder="' . (wc_setting('web_rzp_webhook_secret') !== '' ? '******** saved - leave blank to keep' : 'Same secret you type in Razorpay webhook') . '"></div>';
echo '<div class="col-md-6"><label class="form-label">Webhook URL (paste in Razorpay)</label><input class="form-control" readonly value="' . crm_e($hook) . '" onfocus="this.select()"><div class="form-text">Razorpay Dashboard -> Webhooks -> Add. Events: payment.captured, payment.failed, order.paid, refund.processed.</div></div>';
echo '</div><div class="mt-3"><button class="btn btn-gold" type="submit"><i class="bi bi-check-lg me-1"></i>Save settings</button></div></form>';
echo '<hr><form method="post" class="d-flex flex-wrap gap-2 align-items-center">' . crm_csrf_field() . '<input type="hidden" name="tab" value="test_rzp"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-plug me-1"></i>Test Razorpay keys</button><span class="small text-muted">Save first, then test. Use test keys (rzp_test_...) while you are trying the store.</span></form></div></div>';
echo '<div class="tab-pane fade" id="email"><div class="panel"><form method="post" autocomplete="off">' . crm_csrf_field() . '<input type="hidden" name="tab" value="email"><div class="row g-3">';
echo '<div class="col-md-4"><label class="form-label">Send e-mails using</label><select class="form-select" name="web_mail_driver">' . implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . (wc_setting('web_mail_driver', 'mail') === $k ? ' selected' : '') . '>' . $l . '</option>', ['smtp', 'mail', 'log'], ['SMTP server (recommended)', 'Server default (PHP mail)', 'Do not send (save to logs folder - testing only)'])) . '</select></div>';
echo $in('web_smtp_host', 'SMTP host', 'e.g. smtp.hostinger.com or smtp.gmail.com', 4) . $in('web_smtp_port', 'Port', '587 (TLS) or 465 (SSL)', 2, 'number');
echo '<div class="col-md-2"><label class="form-label">Security</label><select class="form-select" name="web_smtp_secure">' . implode('', array_map(fn($o) => '<option' . (wc_setting('web_smtp_secure', 'tls') === $o ? ' selected' : '') . '>' . $o . '</option>', ['tls', 'ssl', 'none'])) . '</select></div>';
echo $in('web_smtp_user', 'SMTP username', '', 4) . '<div class="col-md-4"><label class="form-label">SMTP password</label><input class="form-control" type="password" name="web_smtp_pass" autocomplete="new-password" placeholder="' . (wc_setting('web_smtp_pass') !== '' ? '******** saved - leave blank to keep' : '') . '"></div>';
echo $in('web_mail_from_name', 'Sender name', 'Empty = store name', 4) . $in('web_mail_from_email', 'Sender e-mail', 'Use an address on your own domain. Empty = business e-mail.', 6);
echo '</div><div class="mt-3"><button class="btn btn-gold" type="submit"><i class="bi bi-check-lg me-1"></i>Save settings</button> <a class="btn btn-outline-primary" href="web-emails.php"><i class="bi bi-envelope-paper me-1"></i>Edit e-mail templates</a></div></form>';
echo '<hr><form method="post" class="row g-2 align-items-end">' . crm_csrf_field() . '<input type="hidden" name="tab" value="test_mail"><div class="col-md-5"><label class="form-label">Send a test e-mail to</label><input type="email" class="form-control" name="to" value="' . crm_e(crm_user()['email']) . '"></div><div class="col-auto"><button class="btn btn-outline-primary"><i class="bi bi-send me-1"></i>Send test</button></div></form></div></div>';
echo '</div>';
crm_page_end(); ?>
