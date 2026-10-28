<?php
/* webcore/mail.php - sending e-mail (SMTP / PHP mail / log-only) + luxury e-mail layout */

/** run $fn after the page has been sent to the browser (so SMTP delay never slows the customer) */
function wc_after_response(callable $fn): void
{
    register_shutdown_function(function () use ($fn) {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        try {
            $fn();
        } catch (Throwable $e) {
            error_log('wc_after_response: ' . $e->getMessage());
        }
    });
}

function wc_mail_from(): array
{
    $b    = wc_brand();
    $name = wc_setting('web_mail_from_name', $b['name']);
    $host = preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $mail = wc_setting('web_mail_from_email', '') ?: ($b['email'] ?: 'no-reply@' . preg_replace('/:\d+$/', '', $host));
    return [$mail, $name];
}

/** @return array{0:bool,1:string} [sent, error] */
function wc_send_mail(string $to, string $subject, string $html, string $tpl = ''): array
{
    $to = trim($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Invalid e-mail address'];
    }
    $driver = wc_setting('web_mail_driver', 'mail');
    $ok = false;
    $err = '';
    try {
        [$fromMail, $fromName] = wc_mail_from();
        if ($driver === 'log') {                                  // development: save the mail as a file
            $dir = defined('WC_MAIL_OUTBOX') ? WC_MAIL_OUTBOX : dirname(__DIR__, 2) . '/logs/mail-outbox';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $ok = (bool)file_put_contents($dir . '/' . date('Ymd-His') . '-' . substr(md5($to . $subject . microtime()), 0, 6) . '.html',
                "<!-- To: $to | Subject: $subject -->\n" . $html);
        } else {
            require_once __DIR__ . '/phpmailer/Exception.php';
            require_once __DIR__ . '/phpmailer/PHPMailer.php';
            require_once __DIR__ . '/phpmailer/SMTP.php';
            $m = new PHPMailer\PHPMailer\PHPMailer(true);
            $m->CharSet = 'UTF-8';
            $m->Timeout = 15;
            if ($driver === 'smtp') {
                $m->isSMTP();
                $m->Host = wc_setting('web_smtp_host');
                $m->Port = (int)wc_setting('web_smtp_port', '587');
                $sec = wc_setting('web_smtp_secure', 'tls');
                if ($sec === 'ssl') {
                    $m->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($sec === 'tls') {
                    $m->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                } else {
                    $m->SMTPSecure = '';
                    $m->SMTPAutoTLS = false;
                }
                if (wc_setting('web_smtp_user') !== '') {
                    $m->SMTPAuth = true;
                    $m->Username = wc_setting('web_smtp_user');
                    $m->Password = wc_setting('web_smtp_pass');
                }
            } else {
                $m->isMail();
            }
            $m->setFrom($fromMail, $fromName);
            $reply = wc_brand()['email'];
            if ($reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL)) {
                $m->addReplyTo($reply, $fromName);
            }
            $m->addAddress($to);
            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body    = $html;
            $m->AltBody = trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/tr|\/h\d)>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
            $m->send();
            $ok = true;
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
        error_log('wc_send_mail: ' . $err);
    }
    try {
        wc_run('INSERT INTO web_email_log (to_email, subject, tpl_key, status, error) VALUES (?,?,?,?,?)', 'sssss',
            [$to, mb_substr($subject, 0, 200), $tpl, $ok ? 'sent' : 'failed', mb_substr($err, 0, 400)]);
    } catch (Throwable $e) {
    }
    return [$ok, $err];
}

/* ----------------------------------------------------------- layout */

function wc_email_layout(string $subject, string $body): string
{
    $b  = wc_brand();
    $pr = wc_setting('web_color_primary', '#1b1b1f');
    $ac = wc_setting('web_color_accent', '#b8935a');
    $bg = wc_setting('web_color_bg', '#faf7f2');
    $url = wc_site_url() ?: '#';
    $head = $b['logo'] !== ''
        ? '<img src="' . wc_e($b['logo']) . '" alt="' . wc_e($b['name']) . '" height="46" style="height:46px;max-width:220px;display:inline-block;border:0">'
        : '<span style="font-family:Georgia,\'Times New Roman\',serif;font-size:28px;letter-spacing:4px;text-transform:uppercase;color:#fff">' . wc_e($b['name']) . '</span>';
    $social = '';
    foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook', 'youtube' => 'YouTube', 'twitter' => 'X', 'pinterest' => 'Pinterest'] as $k => $l) {
        $u = wc_setting('web_social_' . $k);
        if ($u !== '') {
            $social .= '<a href="' . wc_e($u) . '" style="color:' . $ac . ';text-decoration:none;margin:0 8px;font-size:12px">' . $l . '</a>';
        }
    }
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . wc_e($subject) . '</title></head>'
        . '<body style="margin:0;padding:0;background:' . $bg . ';font-family:Helvetica,Arial,sans-serif;color:#2a2a2e">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $bg . ';padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e8e1d5;border-radius:4px">'
        . '<tr><td align="center" style="background:' . $pr . ';padding:26px 20px;border-radius:4px 4px 0 0"><a href="' . wc_e($url) . '" style="text-decoration:none">' . $head . '</a></td></tr>'
        . '<tr><td style="height:3px;background:' . $ac . ';line-height:3px;font-size:0">&nbsp;</td></tr>'
        . '<tr><td style="padding:34px 34px 26px;font-size:15px;line-height:1.65;color:#2a2a2e">' . $body . '</td></tr>'
        . '<tr><td style="padding:22px 34px;background:#f6f2ea;border-top:1px solid #e8e1d5;border-radius:0 0 4px 4px;text-align:center;font-size:12px;line-height:1.7;color:#7a7468">'
        . ($social !== '' ? '<div style="margin-bottom:10px">' . $social . '</div>' : '')
        . 'Need help? <a href="mailto:' . wc_e($b['email']) . '" style="color:' . $ac . '">' . wc_e($b['email']) . '</a>' . ($b['phone'] !== '' ? ' &nbsp;|&nbsp; ' . wc_e($b['phone']) : '') . '<br>'
        . wc_e($b['addr']) . '<br>&copy; ' . date('Y') . ' ' . wc_e($b['name']) . '. All rights reserved.'
        . '</td></tr></table></td></tr></table></body></html>';
}

function wc_btn(string $url, string $label): string
{
    return '<p style="text-align:center;margin:26px 0 8px"><a href="' . wc_e($url) . '" style="display:inline-block;background:' . wc_setting('web_color_primary', '#1b1b1f')
        . ';color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:3px;font-size:13px;letter-spacing:2px;text-transform:uppercase">' . wc_e($label) . '</a></p>';
}

/* --------------------------------------------------------- templates */

function wc_email_vars_base(): array
{
    $b = wc_brand();
    return [
        'store_name' => $b['name'], 'store_url' => wc_site_url(), 'support_email' => $b['email'], 'support_phone' => $b['phone'],
        'year' => date('Y'), 'return_days' => wc_setting('web_return_days', '7'),
    ];
}

/** @return array{0:string,1:string} [subject, full html] */
function wc_render_template(string $key, array $vars): array
{
    $def = wc_email_defaults()[$key] ?? null;
    if (!$def) {
        return ['', ''];
    }
    $row  = wc_one('SELECT subject, body_html, is_active FROM web_email_templates WHERE tpl_key = ?', 's', [$key]);
    $subj = $row ? $row['subject'] : $def['subject'];
    $body = $row ? $row['body_html'] : $def['body'];
    $vars += wc_email_vars_base();
    $vars['accent']  = wc_setting('web_color_accent', '#b8935a');
    $vars['primary'] = wc_setting('web_color_primary', '#1b1b1f');
    $map = [];
    foreach ($vars as $k => $v) {
        $map['{{' . $k . '}}'] = substr($k, -5) === '_html' ? (string)$v : wc_e($v);
    }
    $plain = [];
    foreach ($vars as $k => $v) {
        $plain['{{' . $k . '}}'] = substr($k, -5) === '_html' ? strip_tags((string)$v) : (string)$v;
    }
    $subject = trim(preg_replace('/\{\{[a-z_]+\}\}/', '', strtr($subj, $plain)));
    $html    = preg_replace('/\{\{[a-z_]+\}\}/', '', strtr($body, $map));   // unknown placeholders vanish
    return [$subject, wc_email_layout($subject, $html)];
}

function wc_template_active(string $key): bool
{
    $row = wc_one('SELECT is_active FROM web_email_templates WHERE tpl_key = ?', 's', [$key]);
    return !$row || (int)$row['is_active'] === 1;
}

/** send one template. Returns [ok, error] */
function wc_send_template(string $key, string $to, array $vars): array
{
    if (!wc_template_active($key)) {
        return [true, 'template disabled'];
    }
    [$subject, $html] = wc_render_template($key, $vars);
    if ($subject === '') {
        return [false, 'Unknown template'];
    }
    return wc_send_mail($to, $subject, $html, $key);
}

/* ------------------------------------------------------ order e-mail data */

function wc_items_table_html(array $order, array $items): string
{
    $ac = wc_setting('web_color_accent', '#b8935a');
    $h  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:14px 0;font-size:14px">';
    foreach ($items as $it) {
        $var = trim($it['size'] . ($it['size'] !== '' && $it['color'] !== '' ? ' / ' : '') . $it['color']);
        $img = $it['image'] !== '' ? '<img src="' . wc_e(wc_upload_url('products', $it['image'])) . '" width="56" height="70" style="width:56px;height:70px;object-fit:cover;border-radius:3px;display:block">' : '';
        $h .= '<tr><td width="64" style="padding:10px 0;border-bottom:1px solid #eee8dc">' . $img . '</td>'
            . '<td style="padding:10px 10px;border-bottom:1px solid #eee8dc"><b>' . wc_e($it['name']) . '</b>'
            . ($var !== '' ? '<br><span style="color:#7a7468;font-size:12px">' . wc_e($var) . '</span>' : '') . '<br><span style="color:#7a7468;font-size:12px">Qty ' . (int)$it['qty'] . '</span></td>'
            . '<td align="right" style="padding:10px 0;border-bottom:1px solid #eee8dc;white-space:nowrap">' . wc_money($it['line_total']) . '</td></tr>';
    }
    $row = function (string $l, string $v, bool $bold = false) {
        return '<tr><td colspan="2" align="right" style="padding:4px 10px;color:#7a7468' . ($bold ? ';font-weight:bold;color:#2a2a2e;font-size:15px;padding-top:10px' : '') . '">' . $l . '</td><td align="right" style="padding:4px 0;white-space:nowrap' . ($bold ? ';font-weight:bold;font-size:15px;padding-top:10px' : '') . '">' . $v . '</td></tr>';
    };
    $h .= $row('Subtotal', wc_money($order['subtotal']));
    if ((float)$order['discount'] > 0) {
        $h .= $row('Discount' . ($order['coupon_code'] !== '' ? ' (' . wc_e($order['coupon_code']) . ')' : ''), '-' . wc_money($order['discount']));
    }
    $h .= $row('Shipping', (float)$order['shipping_fee'] > 0 ? wc_money($order['shipping_fee']) : 'Free');
    if ((float)$order['cod_fee'] > 0) {
        $h .= $row('COD fee', wc_money($order['cod_fee']));
    }
    $h .= $row('Total', '<span style="color:' . $ac . '">' . wc_money($order['total']) . '</span>', true);
    return $h . '</table>';
}

function wc_address_html(array $o): string
{
    return '<div style="background:#faf7f2;border:1px solid #eee8dc;border-radius:3px;padding:12px 16px;font-size:14px;line-height:1.6"><b>' . wc_e($o['ship_name']) . '</b><br>'
        . wc_e($o['ship_line1']) . ($o['ship_line2'] !== '' ? ', ' . wc_e($o['ship_line2']) : '') . '<br>'
        . wc_e($o['ship_city']) . ', ' . wc_e($o['ship_state']) . ' - ' . wc_e($o['ship_pin']) . '<br>Mobile: ' . wc_e($o['ship_mobile']) . '</div>';
}

function wc_order_email_vars(array $o, array $extra = []): array
{
    $items = wc_all('SELECT * FROM web_order_items WHERE order_id = ?', 'i', [(int)$o['id']]);
    $track = '';
    if ($o['courier'] !== '' || $o['tracking_no'] !== '') {
        $track = '<div style="background:#faf7f2;border:1px solid #eee8dc;border-radius:3px;padding:12px 16px;font-size:14px;line-height:1.7">Courier: <b>' . wc_e($o['courier'] ?: '-') . '</b><br>Tracking number: <b>' . wc_e($o['tracking_no'] ?: '-') . '</b></div>';
    }
    $site = wc_site_url();
    $v = [
        'name' => $o['ship_name'], 'order_no' => $o['order_no'], 'order_total' => wc_money($o['total']),
        'order_date' => date('j M Y', strtotime((string)($o['placed_at'] ?: $o['created_at']))),
        'payment_method' => $o['payment_method'] === 'cod' ? 'Cash on delivery' : 'Online payment (Razorpay)',
        'items_html' => wc_items_table_html($o, $items), 'address_html' => wc_address_html($o), 'tracking_html' => $track,
        'order_url' => $site . '/account/order/' . $o['order_no'], 'pay_url' => $site . '/account/order/' . $o['order_no'],
        'tracking_url' => $o['tracking_url'] !== '' ? $o['tracking_url'] : $site . '/account/order/' . $o['order_no'],
        'reason' => $o['cancel_reason'], 'unpaid_minutes' => wc_setting('web_unpaid_expiry_min', '45'),
    ];
    return $extra + $v;
}
