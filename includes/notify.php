<?php
/*
|--------------------------------------------------------------------------
| includes/notify.php  -  notifications (bell + sound)
|--------------------------------------------------------------------------
| crm_notify([...])        create one notification (any page can call it)
| crm_notif_feed()         list for the current user
| crm_notif_unread()       unread count for the current user
| crm_notif_generate()     daily items: employee birthday / work anniversary,
|                          customer birthdays, pending payments, low stock,
|                          follow-ups and tasks due today
|
| The browser asks notifications.php?api=poll every few seconds. When a new
| id shows up, assets/js/notify.js rings the bell and plays the sound.
*/

/**
 * @param array $n  type, title, message, icon, color, link, perm (permission needed to see it),
 *                  ref (unique key: the same ref is never created twice), update (true = refresh text of an existing ref)
 */
function crm_notify(array $n): void
{
    try {
        $ref = isset($n['ref']) && $n['ref'] !== '' ? (string)$n['ref'] : null;
        $sql = 'INSERT ' . (empty($n['update']) ? 'IGNORE ' : '') . 'INTO notifications (type, title, message, icon, color, link, perm_key, ref_key)
                VALUES (?,?,?,?,?,?,?,?)';
        if (!empty($n['update'])) {
            $sql .= ' ON DUPLICATE KEY UPDATE title = VALUES(title), message = VALUES(message)';
        }
        $perm = isset($n['perm']) && $n['perm'] !== '' ? (string)$n['perm'] : null;
        crm_run($sql, 'ssssssss', [
            (string)($n['type'] ?? 'info'),
            mb_substr((string)$n['title'], 0, 190),
            mb_substr((string)($n['message'] ?? ''), 0, 500),
            (string)($n['icon'] ?? 'bell'),
            (string)($n['color'] ?? 'blue'),
            (string)($n['link'] ?? ''),
            $perm,
            $ref,
        ]);
    } catch (Throwable $ex) {
        error_log('crm_notify: ' . $ex->getMessage());
    }
}

/** SQL fragment: only notifications this user is allowed to see */
function crm_notif_visible(): string
{
    $window = "n.created_at >= DATE_SUB(NOW(), INTERVAL 45 DAY)";
    if (crm_is_super()) {
        return $window;
    }
    $list = [];
    foreach (array_keys(crm_perms()) as $k) {
        $list[] = "'" . mysqli_real_escape_string(crm_db(), (string)$k) . "'";
    }
    $in = $list ? ' OR n.perm_key IN (' . implode(',', $list) . ')' : '';
    return $window . ' AND (n.perm_key IS NULL' . $in . ')';
}

function crm_notif_unread(): int
{
    $u = crm_user();
    if (!$u) {
        return 0;
    }
    $r = crm_one(
        'SELECT COUNT(*) AS c FROM notifications n
          LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = ?
          WHERE r.user_id IS NULL AND ' . crm_notif_visible(),
        'i',
        [(int)$u['id']]
    );
    return (int)($r['c'] ?? 0);
}

function crm_notif_feed(int $limit = 10, int $offset = 0): array
{
    $u = crm_user();
    if (!$u) {
        return [];
    }
    $rows = crm_all(
        'SELECT n.id, n.type, n.title, n.message, n.icon, n.color, n.link, n.created_at,
                (r.user_id IS NOT NULL) AS is_read
           FROM notifications n
           LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = ?
          WHERE ' . crm_notif_visible() . '
          ORDER BY n.id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset,
        'i',
        [(int)$u['id']]
    );
    foreach ($rows as &$r) {
        $r['id']      = (int)$r['id'];
        $r['is_read'] = (int)$r['is_read'];
        $r['ago']     = crm_ago((string)$r['created_at']);
    }
    return $rows;
}

function crm_notif_max_id(): int
{
    $r = crm_one('SELECT MAX(n.id) AS m FROM notifications n WHERE ' . crm_notif_visible());
    return (int)($r['m'] ?? 0);
}

function crm_notif_mark_read(?int $id): void
{
    $u = crm_user();
    if (!$u) {
        return;
    }
    if ($id) {
        crm_run('INSERT IGNORE INTO notification_reads (user_id, notification_id) SELECT ?, id FROM notifications WHERE id = ?', 'ii', [(int)$u['id'], $id]);
    } else {
        crm_run(
            'INSERT IGNORE INTO notification_reads (user_id, notification_id)
             SELECT ?, n.id FROM notifications n WHERE ' . crm_notif_visible(),
            'i',
            [(int)$u['id']]
        );
    }
}

function crm_ago(string $dt): string
{
    $t = strtotime($dt);
    if (!$t) {
        return '';
    }
    $s = time() - $t;
    if ($s < 60) {
        return 'Just now';
    }
    if ($s < 3600) {
        return (int)($s / 60) . ' min ago';
    }
    if ($s < 86400) {
        return (int)($s / 3600) . ' hr ago';
    }
    if ($s < 172800) {
        return 'Yesterday';
    }
    return date('j M Y', $t);
}

/** Month-day match that also works for 29 Feb in a non-leap year */
function crm_md_today(?string $date): bool
{
    if (!$date || strlen($date) < 10 || substr($date, 0, 4) === '0000') {
        return false;
    }
    $md = substr($date, 5, 5);
    if ($md === '02-29' && date('L') === '0') {
        $md = '02-28';
    }
    return $md === date('m-d');
}

/** Creates today's automatic notifications. Safe to call often (every item has a unique ref). */
function crm_notif_generate(): void
{
    crm_session_start();
    if (($_SESSION['crm_gen_at'] ?? 0) > time() - 300) {          // at most every 5 minutes per session
        return;
    }
    $_SESSION['crm_gen_at'] = time();
    $today = date('Y-m-d');
    $year  = (int)date('Y');

    try {
        /* ---- employees: birthday + work anniversary (everybody sees these) ---- */
        foreach (crm_all("SELECT id, name, dob, joining_date FROM users WHERE status = 'active'") as $e) {
            if (crm_md_today($e['dob'])) {
                crm_notify([
                    'type' => 'emp_birthday', 'icon' => 'gift', 'color' => 'gold',
                    'title' => 'Birthday today: ' . $e['name'],
                    'message' => 'Wish your teammate a happy birthday.',
                    'link' => 'notifications.php', 'ref' => 'emp_bday:' . $e['id'] . ':' . $year,
                ]);
            }
            if (crm_md_today($e['joining_date'])) {
                $yrs = $year - (int)substr((string)$e['joining_date'], 0, 4);
                if ($yrs >= 1) {
                    crm_notify([
                        'type' => 'emp_anniversary', 'icon' => 'award', 'color' => 'green',
                        'title' => 'Work anniversary: ' . $e['name'],
                        'message' => 'Completes ' . $yrs . ' year' . ($yrs === 1 ? '' : 's') . ' with the team today.',
                        'link' => 'notifications.php', 'ref' => 'emp_anniv:' . $e['id'] . ':' . $year,
                    ]);
                }
            }
        }

        /* ---- customers: today's birthdays / anniversaries ---- */
        foreach (['dob' => ['Birthday', 'gift', 'reminders.php?view=birthday'], 'anniversary' => ['Anniversary', 'heart', 'reminders.php?view=anniversary']] as $col => $m) {
            $names = [];
            foreach (crm_all("SELECT name, $col AS d FROM customers WHERE $col IS NOT NULL AND $col > '1000-01-01' AND DATE_FORMAT($col, '%m-%d') IN ('" . date('m-d') . "'" . (date('m-d') === '02-28' && date('L') === '0' ? ",'02-29'" : '') . ")") as $c) {
                $names[] = $c['name'];
            }
            if ($names) {
                crm_notify([
                    'type' => 'cust_' . strtolower($m[0]), 'icon' => $m[1], 'color' => 'gold', 'perm' => 'reminders.view',
                    'title' => "Today's customer " . strtolower($m[0]) . (count($names) > 1 ? 's' : '') . ' (' . count($names) . ')',
                    'message' => implode(', ', array_slice($names, 0, 4)) . (count($names) > 4 ? ' +' . (count($names) - 4) . ' more' : ''),
                    'link' => $m[2], 'ref' => 'cust_' . $col . ':' . $today, 'update' => true,
                ]);
            }
        }

        /* ---- pending payments ---- */
        $p = crm_one("SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS amt FROM bills WHERE payment_status <> 'Paid'");
        if ($p && (int)$p['n'] > 0) {
            crm_notify([
                'type' => 'pending_pay', 'icon' => 'hourglass-split', 'color' => 'red', 'perm' => 'billing.view',
                'title' => (int)$p['n'] . ((int)$p['n'] === 1 ? ' payment is pending' : ' payments are pending'),
                'message' => 'Rs. ' . number_format((float)$p['amt'], 0) . ' to collect',
                'link' => 'billing.php?view=pending', 'ref' => 'pending_pay:' . $today, 'update' => true,
            ]);
        }

        /* ---- low / out of stock ---- */
        $s = crm_one("SELECT COUNT(*) AS n FROM (SELECT p.id, p.min_stock FROM products p LEFT JOIN product_variants v ON v.product_id = p.id
                       WHERE p.status = 'Active' GROUP BY p.id, p.min_stock HAVING COALESCE(SUM(v.stock),0) <= p.min_stock) t");
        if ($s && (int)$s['n'] > 0) {
            crm_notify([
                'type' => 'low_stock', 'icon' => 'box-seam', 'color' => 'red', 'perm' => 'products.view',
                'title' => (int)$s['n'] . ' product' . ((int)$s['n'] === 1 ? '' : 's') . ' low or out of stock',
                'message' => 'Review and refill stock.',
                'link' => 'products.php?view=low', 'ref' => 'low_stock:' . $today, 'update' => true,
            ]);
        }

        /* ---- inquiry follow-ups and tasks due today ---- */
        $f = crm_one("SELECT COUNT(*) AS n FROM inquiries WHERE followup_date <= ? AND status IN ('New','In Progress','Follow-up') AND followup_date IS NOT NULL", 's', [$today]);
        if ($f && (int)$f['n'] > 0) {
            crm_notify([
                'type' => 'inq_followup', 'icon' => 'calendar-check', 'color' => 'blue', 'perm' => 'inquiries.view',
                'title' => (int)$f['n'] . ' inquiry follow-up' . ((int)$f['n'] === 1 ? '' : 's') . ' due',
                'message' => 'Call or message these leads today.',
                'link' => 'inquiries.php?view=followups', 'ref' => 'inq_follow:' . $today, 'update' => true,
            ]);
        }
        $t = crm_one("SELECT COUNT(*) AS n FROM tasks WHERE due_date <= ? AND status = 'Open'", 's', [$today]);
        if ($t && (int)$t['n'] > 0) {
            crm_notify([
                'type' => 'tasks_due', 'icon' => 'check2-square', 'color' => 'blue', 'perm' => 'tasks.view',
                'title' => (int)$t['n'] . ' task' . ((int)$t['n'] === 1 ? '' : 's') . ' due today or overdue',
                'message' => 'Open the task list to finish them.',
                'link' => 'tasks.php', 'ref' => 'tasks_due:' . $today, 'update' => true,
            ]);
        }
    } catch (Throwable $ex) {
        error_log('crm_notif_generate: ' . $ex->getMessage());
    }
}
