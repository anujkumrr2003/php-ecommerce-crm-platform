<?php
/*
| webcore/emails.php - the default e-mail templates.
| The owner can change subject + text in CRM -> Website -> E-mail templates (saved in web_email_templates).
| Placeholders: {{name}} {{store_name}} {{order_no}} {{order_total}} {{otp}} ...  (names ending _html are inserted as HTML)
*/
function wc_email_defaults(): array
{
    $h = function (string $t) {
        return '<h2 style="font-family:Georgia,\'Times New Roman\',serif;font-weight:normal;font-size:26px;margin:0 0 14px;color:{{primary}}">' . $t . '</h2>';
    };
    $otp = '<div style="text-align:center;margin:24px 0"><span style="display:inline-block;font-family:Consolas,monospace;font-size:34px;letter-spacing:10px;padding:14px 26px;background:#faf7f2;border:1px dashed {{accent}};border-radius:4px;color:{{primary}}">{{otp}}</span></div>';
    $btn = function (string $url, string $label) {
        return '<p style="text-align:center;margin:26px 0 8px"><a href="' . $url . '" style="display:inline-block;background:{{primary}};color:#ffffff;text-decoration:none;padding:13px 32px;border-radius:3px;font-size:13px;letter-spacing:2px;text-transform:uppercase">' . $label . '</a></p>';
    };
    $meta = '<p style="margin:0 0 6px;color:#7a7468;font-size:13px">Order <b style="color:#2a2a2e">#{{order_no}}</b> &nbsp;|&nbsp; {{order_date}} &nbsp;|&nbsp; {{payment_method}}</p>';
    $sign = '<p style="margin:26px 0 0">With warm regards,<br><b>{{store_name}}</b></p>';

    return [
        'register_otp' => ['label' => 'Registration - e-mail OTP', 'subject' => '{{otp}} is your verification code - {{store_name}}',
            'body' => $h('Verify your email') . '<p>Hello {{name}},</p><p>Thank you for creating an account with {{store_name}}. Please enter this code to verify your email address:</p>' . $otp . '<p style="color:#7a7468;font-size:13px">The code is valid for 10 minutes. If you did not create an account, you can safely ignore this email.</p>' . $sign],
        'welcome' => ['label' => 'Welcome (after verification)', 'subject' => 'Welcome to {{store_name}}',
            'body' => $h('Welcome, {{name}}') . '<p>Your email is verified and your account is ready. You can now save your favourites, add delivery addresses and track every order in one place.</p>' . $btn('{{store_url}}', 'Start shopping') . $sign],
        'reset_otp' => ['label' => 'Password reset - OTP', 'subject' => '{{otp}} is your password reset code',
            'body' => $h('Reset your password') . '<p>Hello {{name}},</p><p>Use this code to set a new password for your {{store_name}} account:</p>' . $otp . '<p style="color:#7a7468;font-size:13px">Valid for 10 minutes. If you did not ask for this, please ignore this email - your password is unchanged.</p>' . $sign],
        'order_placed' => ['label' => 'Order confirmation', 'subject' => 'Order confirmed #{{order_no}} - {{store_name}}',
            'body' => $h('Thank you for your order') . '<p>Hello {{name}}, we have received your order and we are getting it ready.</p>' . $meta . '{{items_html}}<p style="margin:18px 0 6px"><b>Delivering to</b></p>{{address_html}}' . $btn('{{order_url}}', 'View your order') . $sign],
        'payment_success' => ['label' => 'Payment confirmation', 'subject' => 'Payment received for order #{{order_no}}',
            'body' => $h('Payment received') . '<p>Hello {{name}},</p><p>We have received your payment of <b>{{order_total}}</b> for order <b>#{{order_no}}</b>. Your payment reference is <b>{{payment_id}}</b>.</p><p>We will email you again as soon as your order ships.</p>' . $btn('{{order_url}}', 'View your order') . $sign],
        'payment_failed' => ['label' => 'Payment failed', 'subject' => 'Payment failed for order #{{order_no}}',
            'body' => $h('Your payment did not go through') . '<p>Hello {{name}},</p><p>The payment for order <b>#{{order_no}}</b> ({{order_total}}) could not be completed. No money has been taken, or if it has, it will be returned by your bank automatically.</p><p>Your items are reserved for the next {{unpaid_minutes}} minutes. You can try again from your account.</p>' . $btn('{{pay_url}}', 'Try payment again') . $sign],
        'order_shipped' => ['label' => 'Order dispatched / shipped', 'subject' => 'Your order #{{order_no}} has shipped',
            'body' => $h('Your order is on its way') . '<p>Hello {{name}}, good news - your order <b>#{{order_no}}</b> has been dispatched.</p>{{tracking_html}}' . $btn('{{tracking_url}}', 'Track your order') . '<p style="margin:18px 0 6px"><b>Delivering to</b></p>{{address_html}}' . $sign],
        'out_for_delivery' => ['label' => 'Out for delivery', 'subject' => 'Out for delivery - order #{{order_no}}',
            'body' => $h('Arriving today') . '<p>Hello {{name}}, your order <b>#{{order_no}}</b> is out for delivery. Please keep your phone handy.</p>{{tracking_html}}' . ($btn('{{order_url}}', 'View order')) . $sign],
        'order_delivered' => ['label' => 'Order delivered', 'subject' => 'Delivered - order #{{order_no}}',
            'body' => $h('Delivered') . '<p>Hello {{name}}, your order <b>#{{order_no}}</b> has been delivered. We hope you love it!</p><p>If anything is not right, you can request a return within {{return_days}} days from your account. We would also love to hear what you think - a quick review helps other shoppers.</p>' . $btn('{{order_url}}', 'View order') . $sign],
        'order_cancelled' => ['label' => 'Order cancelled', 'subject' => 'Order #{{order_no}} has been cancelled',
            'body' => $h('Order cancelled') . '<p>Hello {{name}},</p><p>Your order <b>#{{order_no}}</b> has been cancelled.</p><p><b>Reason:</b> {{reason}}</p><p>{{refund_note}}</p>' . $btn('{{store_url}}', 'Continue shopping') . $sign],
        'return_requested' => ['label' => 'Return request received', 'subject' => 'We received your return request - order #{{order_no}}',
            'body' => $h('Return request received') . '<p>Hello {{name}},</p><p>We have received your return request for order <b>#{{order_no}}</b>. Our team will review it and email you the next steps within 1-2 working days.</p>' . $btn('{{order_url}}', 'View order') . $sign],
        'return_approved' => ['label' => 'Return approved', 'subject' => 'Return approved - order #{{order_no}}',
            'body' => $h('Your return is approved') . '<p>Hello {{name}},</p><p>Your return request for order <b>#{{order_no}}</b> has been approved.</p><p>{{admin_note}}</p><p>Once we receive and check the item(s), your refund will be processed.</p>' . $btn('{{order_url}}', 'View order') . $sign],
        'return_rejected' => ['label' => 'Return declined', 'subject' => 'Update on your return request - order #{{order_no}}',
            'body' => $h('About your return request') . '<p>Hello {{name}},</p><p>We are sorry - we could not approve the return request for order <b>#{{order_no}}</b>.</p><p>{{admin_note}}</p><p>If you have questions, just reply to this email or write to {{support_email}}.</p>' . $sign],
        'refund_processed' => ['label' => 'Refund processed', 'subject' => 'Refund of {{refund_amount}} for order #{{order_no}}',
            'body' => $h('Your refund is on its way') . '<p>Hello {{name}},</p><p>We have processed a refund of <b>{{refund_amount}}</b> for order <b>#{{order_no}}</b>.</p><p>{{refund_note}}</p>' . $btn('{{order_url}}', 'View order') . $sign],
        'admin_new_order' => ['label' => 'New order alert (to the store)', 'subject' => 'New order #{{order_no}} - {{order_total}}',
            'body' => $h('New order received') . $meta . '<p>Customer: <b>{{name}}</b></p>{{items_html}}{{address_html}}'],
    ];
}
