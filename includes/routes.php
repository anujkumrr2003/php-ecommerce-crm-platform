<?php
/*
|--------------------------------------------------------------------------
| includes/routes.php  -  WHO CAN OPEN WHAT
|--------------------------------------------------------------------------
| One place that connects every page (and every ?api=... call inside a page)
| with a permission key. crm_guard() reads this file on every request.
|
|   '*'    => permission needed to open the page itself
|   'api'  => [ api-name => permission ]   (overrides '*' for that ?api= call)
|   'export' => permission for ?export=...
|   'super' => true   -> only Super Admin
|   'any'   => true   -> any logged-in user
|
| To protect a NEW page: add one line here, and call crm_guard() at the top
| of the page.   (Permission keys live in the `permissions` table.)
*/
return [
    'index.php'          => ['*' => 'dashboard.view'],

    'customers.php'      => [
        '*'      => 'customers.view',
        'export' => 'customers.export',
        'api'    => [
            'wa_resend'     => 'customers.edit',
            'update'        => 'customers.edit',
            'remark_add'    => 'customers.edit',
            'remark_done'   => 'customers.edit',
            'remark_delete' => 'customers.edit',
            'delete'        => 'customers.delete',
            'bill_delete'   => 'billing.delete',
        ],
    ],
    'add-customer.php'   => ['*' => 'customers.add'],
    'customer-form.php'  => ['*' => 'customers.add'],
    'custom-fields.php'  => ['*' => 'fields.manage'],

    'products.php'       => [
        '*'   => 'products.view',
        'api' => [
            'save'   => function () {
                return (int)($_POST['id'] ?? 0) > 0 ? 'products.edit' : 'products.add';
            },
            'delete' => 'products.delete',
        ],
    ],

    'billing.php'        => [
        '*'   => 'billing.view',
        'api' => ['paid' => 'billing.edit', 'delete' => 'billing.delete'],
    ],
    'create-bill.php'    => ['*' => 'billing.create'],

    'reminders.php'      => [
        '*'   => 'reminders.view',
        'api' => [
            'send_whatsapp'     => 'reminders.manage',
            'schedule_messages' => 'reminders.manage',
            'cancel_scheduled'  => 'reminders.manage',
            'retry_scheduled'   => 'reminders.manage',
            'mark_sent'         => 'reminders.manage',
            'event_save'        => 'reminders.manage',
            'event_delete'      => 'reminders.manage',
            'remark_add'        => 'reminders.manage',
            'remark_done'       => 'reminders.manage',
            'remark_delete'     => 'reminders.manage',
        ],
    ],

    'message-settings.php' => [
        '*'   => 'messages.view',
        'api' => [
            'send_whatsapp'     => 'messages.manage',
            'save_template'     => 'messages.manage',
            'save_schedule'     => 'messages.manage',
            'mark_sent'         => 'messages.manage',
            'schedule_messages' => 'messages.manage',
            'cancel_scheduled'  => 'messages.manage',
            'retry_scheduled'   => 'messages.manage',
            'run_now'           => 'messages.manage',
        ],
    ],

    'inquiries.php'      => ['*' => 'inquiries.view'],
    'tasks.php'          => ['*' => 'tasks.view'],

    'settings.php'       => [
        '*'   => 'settings.view',
        'api' => ['brand' => '', 'save_general' => 'settings.manage', 'save_billing' => 'settings.manage'],
    ],
    'api-settings.php'   => ['*' => 'api.manage'],


    /* ---- online store (website) screens ---- */
    'web-overview.php'   => ['*' => 'web.orders.view'],
    'web-orders.php'     => ['*' => 'web.orders.view'],
    'web-payments.php'   => ['*' => 'web.orders.view'],
    'web-customers.php'  => ['*' => 'web.customers.view'],
    'web-products.php'   => ['*' => 'web.content.manage'],
    'web-categories.php' => ['*' => 'web.content.manage'],
    'web-banners.php'    => ['*' => 'web.content.manage'],
    'web-home.php'       => ['*' => 'web.content.manage'],
    'web-pages.php'      => ['*' => 'web.content.manage'],
    'web-faqs.php'       => ['*' => 'web.content.manage'],
    'web-shipping.php'   => ['*' => 'web.settings.manage'],
    'web-coupons.php'    => ['*' => 'web.marketing.manage'],
    'web-reviews.php'    => ['*' => 'web.marketing.manage'],
    'web-settings.php'   => ['*' => 'web.settings.manage'],
    'web-emails.php'     => ['*' => 'web.settings.manage'],

    'activity-log.php'   => ['*' => 'activity.view'],
    'employees.php'      => ['super' => true],
    'roles.php'          => ['super' => true],

    'notifications.php'  => ['any' => true],
    'change-password.php'=> ['any' => true],
    'profile.php'        => ['any' => true],
];
