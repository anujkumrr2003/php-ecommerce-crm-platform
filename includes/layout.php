<?php
/*
|--------------------------------------------------------------------------
| includes/layout.php  -  sidebar, top bar, bell, page frame
|--------------------------------------------------------------------------
| Old pages (customers.php, billing.php ...) keep their own <html> and call:
|     crm_head()     inside <head>        (extra CSS)
|     crm_sidebar()  where the sidebar goes
|     crm_topbar()   where the top bar goes
|     crm_foot()     before </body>       (table / bell / menu scripts)
| New pages use   crm_page_start('Title')  ...  crm_page_end()
|
| The menu is built here, filtered by the permissions of the logged-in user,
| so a Manager never even SEES a link he is not allowed to open.
| To add a menu item: add one line in crm_nav().
*/

function crm_asset(string $path): string
{
    $f = dirname(__DIR__) . '/' . $path;
    return $path . (is_file($f) ? '?v=' . filemtime($f) : '');
}

function crm_brand(): array
{
    $name = crm_setting('store_name', 'My Business');
    $logo = crm_setting('logo', '');
    if ($logo !== '' && !is_file(dirname(__DIR__) . '/' . $logo)) {
        $logo = '';
    }
    return ['name' => $name, 'logo' => $logo];
}

/** [href, icon, label, permission]  or  [groupId, icon, label, permission-or-'', [children...]] */
function crm_nav(): array
{
    return [
        ['index.php', 'grid-1x2', 'Dashboard', 'dashboard.view'],
        ['g1', 'people', 'Customers', '', [
            ['add-customer.php', 'Add Customer', 'customers.add'],
            ['customers.php', 'All Customers', 'customers.view'],
        ]],
        ['g6', 'box-seam', 'Products', '', [
            ['products.php?new=1', 'Add Product', 'products.add'],
            ['products.php', 'All Products', 'products.view'],
            ['products.php?view=low', 'Low Stock', 'products.view'],
        ]],
        ['g2', 'receipt', 'Billing', '', [
            ['create-bill.php', 'Create Bill', 'billing.create'],
            ['billing.php', 'All Bills', 'billing.view'],
            ['billing.php?view=pending', 'Pending Payments', 'billing.view'],
        ]],
        ['g8', 'shop-window', 'Online Store', '', [
            ['web-overview.php', 'Store Overview', 'web.orders.view'],
            ['web-orders.php', 'Orders', 'web.orders.view'],
            ['web-payments.php', 'Payments & Refunds', 'web.orders.view'],
            ['web-customers.php', 'Website Customers', 'web.customers.view'],
            ['web-products.php', 'Products on Website', 'web.content.manage'],
            ['web-categories.php', 'Categories', 'web.content.manage'],
            ['web-banners.php', 'Banners', 'web.content.manage'],
            ['web-home.php', 'Home Page Sections', 'web.content.manage'],
            ['web-pages.php', 'Pages (Policies, About)', 'web.content.manage'],
            ['web-faqs.php', 'FAQ', 'web.content.manage'],
            ['web-coupons.php', 'Coupons & Offers', 'web.marketing.manage'],
            ['web-reviews.php', 'Reviews', 'web.marketing.manage'],
            ['web-shipping.php', 'State-wise Shipping', 'web.settings.manage'],
            ['web-emails.php', 'E-mail Templates', 'web.settings.manage'],
            ['web-settings.php', 'Website Settings', 'web.settings.manage'],
        ]],
        ['g3', 'check2-square', 'Tasks & Reminders', '', [
            ['tasks.php', 'Tasks', 'tasks.view'],
            ['reminders.php', 'Birthday Reminders', 'reminders.view'],
            ['reminders.php?view=anniversary', 'Anniversary Reminders', 'reminders.view'],
            ['reminders.php?view=events', 'Upcoming Events', 'reminders.view'],
        ]],
        ['g4', 'chat-dots', 'Website Inquiries', '', [
            ['inquiries.php', 'All Inquiries', 'inquiries.view'],
            ['inquiries.php?view=new', 'New', 'inquiries.view'],
            ['inquiries.php?view=product', 'Product Inquiries', 'inquiries.view'],
            ['inquiries.php?view=contact', 'Contact Inquiries', 'inquiries.view'],
            ['inquiries.php?view=followups', 'Follow-ups', 'inquiries.view'],
            ['inquiries.php?view=converted', 'Converted Inquiries', 'inquiries.view'],
        ]],
        ['g7', 'shield-lock', 'Team & Access', '@super', [
            ['employees.php', 'Employees', '@super'],
            ['roles.php', 'Roles & Permissions', '@super'],
            ['activity-log.php', 'Activity Log', 'activity.view'],
        ]],
        ['g5', 'gear', 'Settings', '', [
            ['settings.php', 'General Settings', 'settings.view'],
            ['api-settings.php', 'API Integrations', 'api.manage'],
            ['custom-fields.php', 'Custom Registration Fields', 'fields.manage'],
            ['message-settings.php', 'Birthday/Anniversary Messages', 'messages.view'],
            ['message-settings.php#schedule', 'Reminder Scheduling', 'messages.view'],
            ['settings.php#notifications', 'Notification Settings', 'settings.view'],
            ['settings.php#billing', 'Billing Settings', 'settings.view'],
            ['notifications.php', 'All Notifications', ''],
            ['change-password.php', 'Change Password', ''],
        ]],
    ];
}

function crm_allowed(string $perm): bool
{
    return $perm === '@super' ? crm_is_super() : crm_can($perm);
}

/** which link is the "current page" (file + ?view=... match) */
function crm_active_href(array $hrefs): string
{
    $file  = basename((string)parse_url((string)$_SERVER['SCRIPT_NAME'], PHP_URL_PATH));
    $best  = '';
    $score = 0;
    foreach ($hrefs as $h) {
        $p = parse_url($h);
        if (basename((string)($p['path'] ?? '')) !== $file) {
            continue;
        }
        parse_str((string)($p['query'] ?? ''), $q);
        $s = 1;
        $match = true;
        foreach ($q as $k => $v) {
            if ((string)($_GET[$k] ?? '') !== (string)$v) {
                $match = false;
                break;
            }
            $s += 2;
        }
        if ($match && $s > $score) {
            $score = $s;
            $best  = $h;
        }
    }
    return $best;
}

function crm_initials(string $name): string
{
    $out = '';
    foreach (preg_split('/\s+/u', trim($name)) as $w) {
        if ($w !== '') {
            $out .= mb_strtoupper(mb_substr($w, 0, 1));
        }
        if (mb_strlen($out) >= 2) {
            break;
        }
    }
    return $out !== '' ? $out : '?';
}

/* ------------------------------------------------------------ head / foot */

function crm_head(): void
{
    echo '<link href="' . crm_e(crm_asset('assets/css/crm.css')) . '" rel="stylesheet" />' . "\n";
    echo '<meta name="crm-csrf" content="' . crm_e(crm_csrf()) . '" />' . "\n";
    echo '<meta name="crm-brand" content="' . crm_e(crm_brand()['name']) . '" />' . "\n";
}

function crm_foot(): void
{
    echo '<script src="' . crm_e(crm_asset('assets/js/layout.js')) . '"></script>' . "\n";
    echo '<script src="' . crm_e(crm_asset('assets/js/crm-table.js')) . '"></script>' . "\n";
    echo '<script src="' . crm_e(crm_asset('assets/js/notify.js')) . '"></script>' . "\n";
}

/* ---------------------------------------------------------------- sidebar */

function crm_sidebar(): void
{
    $b    = crm_brand();
    $p    = preg_split('/\s+/', trim($b['name'])) ?: [];
    $first = $p[0] ?? 'CRM';
    $rest  = implode(' ', array_slice($p, 1));

    $items = [];
    foreach (crm_nav() as $n) {
        if (isset($n[4])) {
            $kids = array_values(array_filter($n[4], fn($k) => crm_allowed($k[2])));
            if ($kids && ($n[3] === '' || crm_allowed($n[3]))) {
                $items[] = [$n, $kids];
            }
        } elseif (crm_allowed($n[3])) {
            $items[] = [$n, null];
        }
    }
    $hrefs = [];
    foreach ($items as [$n, $kids]) {
        if ($kids) {
            foreach ($kids as $k) {
                $hrefs[] = $k[0];
            }
        } else {
            $hrefs[] = $n[0];
        }
    }
    $active = crm_active_href($hrefs);

    echo '<aside class="sidebar" aria-label="Main navigation"><div class="brand">';
    echo $b['logo'] !== ''
        ? '<span class="brand-mark brand-logo"><img src="' . crm_e($b['logo']) . '" alt=""></span>'
        : '<span class="brand-mark">' . crm_e(mb_strtoupper(mb_substr($first, 0, 1))) . '</span>';
    echo '<span class="brand-text"><b>' . crm_e($first) . '</b><small>' . crm_e($rest !== '' ? $rest . ' CRM' : 'CRM') . '</small></span></div><nav>';

    foreach ($items as [$n, $kids]) {
        if ($kids) {
            $open = false;
            foreach ($kids as $k) {
                if ($k[0] === $active) {
                    $open = true;
                }
            }
            echo '<a class="nav-x' . ($open ? ' active' : ' collapsed') . '" data-bs-toggle="collapse" href="#' . crm_e($n[0]) . '" role="button" aria-expanded="' . ($open ? 'true' : 'false') . '">'
                . '<i class="bi bi-' . crm_e($n[1]) . '"></i><span class="label">' . crm_e($n[2]) . '</span><i class="bi bi-chevron-down caret ms-auto"></i></a>';
            echo '<div class="collapse sub' . ($open ? ' show' : '') . '" id="' . crm_e($n[0]) . '">';
            foreach ($kids as $k) {
                echo '<a href="' . crm_e($k[0]) . '"' . ($k[0] === $active ? ' class="active"' : '') . '>' . crm_e($k[1]) . '</a>';
            }
            echo '</div>';
        } else {
            echo '<a class="nav-x' . ($n[0] === $active ? ' active' : '') . '" href="' . crm_e($n[0]) . '"><i class="bi bi-' . crm_e($n[1]) . '"></i><span class="label">' . crm_e($n[2]) . '</span></a>';
        }
    }
    echo '</nav><div class="p-3"><a class="nav-x rounded" href="logout.php" style="padding:.6rem .9rem"><i class="bi bi-box-arrow-left"></i><span class="label">Log out</span></a></div></aside>';
}

/* ------------------------------------------------------------------ topbar */

function crm_topbar(): void
{
    $u      = crm_user();
    crm_notif_generate();
    $feed   = crm_notif_feed(8);
    $unread = crm_notif_unread();
    $maxId  = crm_notif_max_id();

    echo '<header class="topbar"><button class="btn btn-light" id="menuToggle" type="button" aria-label="Open or close menu"><i class="bi bi-list fs-5"></i></button>';
    if (crm_can('customers.view')) {
        echo '<form action="customers.php" method="get" role="search" class="d-none d-sm-block"><div class="input-group"><span class="input-group-text bg-white"><i class="bi bi-search"></i></span>'
            . '<input class="form-control" name="q" placeholder="Search customers by name, mobile or city" aria-label="Search customers"></div></form>';
    }
    echo '<div class="ms-auto d-flex align-items-center gap-2">';
    if (crm_can('customers.add')) {
        echo '<a href="add-customer.php" class="btn btn-gold d-none d-md-inline-flex"><i class="bi bi-person-plus me-1"></i>Add customer</a>';
    }

    /* ---- bell ---- */
    echo '<div class="dropdown notif-wrap"><button class="btn btn-light position-relative notif-btn" id="notifBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="Notifications" aria-expanded="false" data-last-id="' . (int)$maxId . '">'
        . '<i class="bi bi-bell fs-6"></i><span class="dot' . ($unread ? '' : ' d-none') . '" id="notifDot">' . ($unread > 99 ? '99+' : $unread) . '</span></button>';
    echo '<div class="dropdown-menu dropdown-menu-end notif-menu"><div class="notif-head"><div><b>Notifications</b><small class="d-block" id="notifCount">' . ($unread ? $unread . ' new' : 'All caught up') . '</small></div>'
        . '<span class="d-flex gap-2 align-items-center"><button type="button" id="notifSound" title="Sound on / off" aria-label="Sound on or off"><i class="bi bi-volume-up"></i></button>'
        . '<button type="button" id="notifReadAll"><i class="bi bi-check2-all me-1"></i>Mark all read</button></span></div>';
    echo '<div class="notif-list" id="notifList">' . crm_notif_items_html($feed) . '</div><a class="notif-foot" href="notifications.php">View all notifications</a></div></div>';

    /* ---- account ---- */
    echo '<div class="dropdown"><button class="btn btn-light d-flex align-items-center gap-2" data-bs-toggle="dropdown" aria-label="Account menu"><span class="av">' . crm_e(crm_initials((string)$u['name'])) . '</span>'
        . '<span class="d-none d-md-inline">' . crm_e($u['name']) . '</span><i class="bi bi-chevron-down small d-none d-md-inline"></i></button>'
        . '<ul class="dropdown-menu dropdown-menu-end shadow profile-menu"><li class="profile-head"><div class="fw-semibold">' . crm_e($u['name']) . '</div><small class="text-muted d-block">' . crm_e($u['email']) . '</small>'
        . '<span class="badge-s b-gold mt-1">' . crm_e($u['role_name']) . '</span></li>'
        . '<li><a class="dropdown-item" href="profile.php"><i class="bi bi-person"></i>My profile</a></li>'
        . '<li><a class="dropdown-item" href="change-password.php"><i class="bi bi-key"></i>Change password</a></li>';
    if (crm_is_super()) {
        echo '<li><a class="dropdown-item" href="employees.php"><i class="bi bi-people"></i>Employees</a></li><li><a class="dropdown-item" href="roles.php"><i class="bi bi-shield-check"></i>Roles &amp; permissions</a></li>';
    }
    echo '<li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right text-danger"></i>Log out</a></li></ul></div>';
    echo '</div></header>';
}

function crm_notif_items_html(array $feed): string
{
    if (!$feed) {
        return '<div class="p-4 text-center text-muted small"><i class="bi bi-bell-slash d-block fs-4 mb-1"></i>No notifications yet</div>';
    }
    $h = '';
    foreach ($feed as $n) {
        $link = $n['link'] !== '' ? $n['link'] : 'notifications.php';
        $h .= '<a class="notif-item' . ($n['is_read'] ? '' : ' unread') . '" href="' . crm_e($link) . '" data-nid="' . (int)$n['id'] . '">'
            . '<span class="notif-ic ' . crm_e($n['color']) . '"><i class="bi bi-' . crm_e($n['icon']) . '"></i></span>'
            . '<span class="min-w-0"><b>' . crm_e($n['title']) . '</b><small class="d-block">' . crm_e($n['message']) . '</small>'
            . '<small><i class="bi bi-clock me-1"></i>' . crm_e($n['ago']) . '</small></span></a>';
    }
    return $h;
}

/* ------------------------------------------------- frame for the NEW pages */

function crm_page_start(string $title, string $extraHead = ''): void
{
    $brand = crm_brand();
    echo '<!doctype html><html lang="en"><head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />'
        . '<title>' . crm_e($title) . ' | ' . crm_e($brand['name']) . ' CRM</title>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com" /><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet" />'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />'
        . '<link href="' . crm_e(crm_asset('assets/css/style.css')) . '" rel="stylesheet" />';
    crm_head();
    echo $extraHead . '</head><body><div class="overlay"></div>';
    crm_sidebar();
    echo '<div class="main">';
    crm_topbar();
    echo '<main class="content">';
}

function crm_page_end(string $scripts = ''): void
{
    echo '</main></div><div id="printArea"></div>'
        . '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>'
        . '<script src="' . crm_e(crm_asset('assets/js/app.js')) . '"></script>';
    crm_foot();
    echo $scripts . '</body></html>';
}

/** Standard page heading block */
function crm_page_head(string $title, string $sub = '', string $actionsHtml = ''): void
{
    echo '<div class="page-head"><div><h1 id="pageTitle">' . crm_e($title) . '</h1>'
        . ($sub !== '' ? '<p class="text-muted mb-0">' . crm_e($sub) . '</p>' : '')
        . '</div><div class="d-flex gap-2 flex-wrap">' . $actionsHtml . '</div></div>';
}
