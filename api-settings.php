
<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection error. Check config.php: $conn must be a mysqli connection.');
}

$conn->set_charset('utf8mb4');

function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function flashMessage($message, $type = 'success')
{
    $_SESSION['api_flash'] = [
        'message' => $message,
        'type' => $type
    ];
}

function redirectPage()
{
    header('Location: api-settings.php');
    exit;
}

function validEndpoint($endpoint)
{
    if ($endpoint === '') {
        return true;
    }

    if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string)parse_url($endpoint, PHP_URL_SCHEME));

    return in_array($scheme, ['http', 'https'], true);
}

function validateApiFields($name, $endpoint, $key, $secret)
{
    if ($name === '' || strlen($name) > 60) {
        throw new Exception('API name is required and must not exceed 60 characters.');
    }

    if (strlen($endpoint) > 500 ||
        strlen($key) > 255 ||
        strlen($secret) > 255) {
        throw new Exception('One or more fields exceed their database column length.');
    }

    if (!validEndpoint($endpoint)) {
        throw new Exception('Enter a valid HTTP or HTTPS endpoint URL.');
    }
}

if (empty($_SESSION['api_csrf'])) {
    $_SESSION['api_csrf'] = bin2hex(random_bytes(32));
}

/*
 * IMPORTANT:
 * The api_integrations table must already exist.
 * This page does not execute CREATE TABLE, so the application
 * does not need CREATE permission on the database.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf_token'])
        ? (string)$_POST['csrf_token']
        : '';

    if (!hash_equals((string)$_SESSION['api_csrf'], $token)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }

    $action = isset($_POST['action'])
        ? (string)$_POST['action']
        : '';

    try {
        /* ADD */
        if ($action === 'add') {
            $name = trim((string)($_POST['name'] ?? ''));
            $endpoint = trim((string)($_POST['endpoint_url'] ?? ''));
            $key = trim((string)($_POST['api_key'] ?? ''));
            $secret = (string)($_POST['api_secret'] ?? '');
            $connected = isset($_POST['is_connected']) ? 1 : 0;

            validateApiFields($name, $endpoint, $key, $secret);

            $stmt = $conn->prepare(
                "INSERT INTO api_integrations
                (name, endpoint_url, api_key, api_secret, is_connected)
                VALUES (?, ?, ?, ?, ?)"
            );

            $stmt->bind_param(
                'ssssi',
                $name,
                $endpoint,
                $key,
                $secret,
                $connected
            );

            $stmt->execute();
            $stmt->close();

            flashMessage('API "' . $name . '" added successfully.');
            redirectPage();
        }

        /* UPDATE */
        if ($action === 'update') {
            $id = filter_var(
                $_POST['id'] ?? '',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if (!$id) {
                throw new Exception('Invalid API record ID.');
            }

            $name = trim((string)($_POST['name'] ?? ''));
            $endpoint = trim((string)($_POST['endpoint_url'] ?? ''));
            $key = trim((string)($_POST['api_key'] ?? ''));
            $secret = (string)($_POST['api_secret'] ?? '');

            validateApiFields($name, $endpoint, $key, $secret);

            $stmt = $conn->prepare(
                "UPDATE api_integrations
                 SET name = ?, endpoint_url = ?, api_key = ?, api_secret = ?
                 WHERE id = ?"
            );

            $stmt->bind_param(
                'ssssi',
                $name,
                $endpoint,
                $key,
                $secret,
                $id
            );

            $stmt->execute();

            // Verify that the requested record exists, even if values
            // were unchanged and MySQL reports zero affected rows.
            $stmt->close();

            $check = $conn->prepare(
                "SELECT id FROM api_integrations WHERE id = ?"
            );
            $check->bind_param('i', $id);
            $check->execute();
            $check->store_result();
            $exists = $check->num_rows > 0;
            $check->close();

            if (!$exists) {
                throw new Exception('API record not found.');
            }

            flashMessage('API "' . $name . '" updated successfully.');
            redirectPage();
        }

        /* CONNECT / DISCONNECT */
        if ($action === 'toggle') {
            $id = filter_var(
                $_POST['id'] ?? '',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            $connected = (string)($_POST['connected'] ?? '');

            if (!$id || !in_array($connected, ['0', '1'], true)) {
                throw new Exception('Invalid connection status.');
            }

            $status = (int)$connected;

            $stmt = $conn->prepare(
                "UPDATE api_integrations
                 SET is_connected = ?
                 WHERE id = ?"
            );

            $stmt->bind_param('ii', $status, $id);
            $stmt->execute();
            $stmt->close();

            $check = $conn->prepare(
                "SELECT id FROM api_integrations WHERE id = ?"
            );
            $check->bind_param('i', $id);
            $check->execute();
            $check->store_result();
            $exists = $check->num_rows > 0;
            $check->close();

            if (!$exists) {
                throw new Exception('API record not found.');
            }

            flashMessage(
                $status ? 'API marked as connected.' : 'API disconnected.'
            );
            redirectPage();
        }

        /* DELETE */
        if ($action === 'delete') {
            $id = filter_var(
                $_POST['id'] ?? '',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if (!$id) {
                throw new Exception('Invalid API record ID.');
            }

            $stmt = $conn->prepare(
                "DELETE FROM api_integrations WHERE id = ?"
            );

            $stmt->bind_param('i', $id);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();

            if ($deleted < 1) {
                throw new Exception('API record not found.');
            }

            flashMessage('API deleted successfully.');
            redirectPage();
        }

        throw new Exception('Unknown action.');
    } catch (Throwable $ex) {
        error_log('API integrations error: ' . $ex->getMessage());

        // Show validation errors, but avoid exposing SQL/database details.
        $message = $ex instanceof mysqli_sql_exception
            ? 'Database operation failed. Please check the table structure and database permissions.'
            : $ex->getMessage();

        flashMessage($message, 'error');
        redirectPage();
    }
}

/* READ */
$apis = [];

$result = $conn->query(
    "SELECT id, name, endpoint_url, api_key, api_secret,
            is_connected, created_at, updated_at
     FROM api_integrations
     ORDER BY id DESC"
);

if (!$result) {
    error_log('API integrations read error: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load API records. Check the api_integrations table.');
}

while ($row = $result->fetch_assoc()) {
    $apis[] = $row;
}

$result->free();

$flash = $_SESSION['api_flash'] ?? null;
unset($_SESSION['api_flash']);

$editId = filter_var(
    $_GET['edit'] ?? '',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$editId = $editId ?: 0;
$csrf = $_SESSION['api_csrf'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>API Integrations | <?= crm_e(crm_brand()['name']) ?> CRM</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=1" rel="stylesheet">

    <?php crm_head(); ?>

    <style>
        .api-add-card {
            width: 100%;
            min-height: 180px;
            padding: 24px;
            border: 2px dashed #c5cedb;
            border-radius: 4px;
            background: transparent;
            color: #6b7280;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: .2s ease;
        }

        .api-add-card:hover {
            border-color: #c9a24b;
            color: #a8812f;
            background: rgba(201,162,75,.06);
        }

        .api-add-card i { font-size: 30px; }

        .api-card {
            height: 100%;
            padding: 20px;
        }

        .api-card .panel-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }

        .api-card .panel-title h5 {
            margin: 0;
            overflow-wrap: anywhere;
        }

        .api-card .form-label {
            font-size: 13px;
            font-weight: 600;
        }

        .api-card input[readonly] {
            background: #f6f8fb;
        }

        .api-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .api-status { white-space: nowrap; }

        .api-meta {
            margin-top: 12px;
            font-size: 12px;
            color: #7b8494;
        }

        @media (max-width: 575.98px) {
            .api-card { padding: 15px; }
            .api-card .panel-title { align-items: flex-start; }
        }
    </style>
</head>

<body>
<div class="overlay"></div>
<?php crm_sidebar(); ?>

<div class="main">
    <?php crm_topbar(); ?>

    <main class="content">
        <div class="page-head">
            <div>
                <h1 id="pageTitle">API integrations</h1>
                <p class="text-muted mb-0">
                    Manage your property portals and website API credentials.
                    All changes are saved in MySQL.
                </p>
            </div>

            <button type="button" class="btn btn-gold mt-3 mt-lg-0"
                    data-bs-toggle="modal" data-bs-target="#apiModal">
                <i class="bi bi-plus-lg me-1"></i>Add custom API
            </button>
        </div>

        <?php if ($flash): ?>
            <div class="alert <?= ($flash['type'] ?? '') === 'error'
                ? 'alert-danger' : 'alert-success' ?> alert-dismissible fade show"
                 role="alert">
                <?= e($flash['message'] ?? '') ?>
                <button type="button" class="btn-close"
                        data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3" id="apiRow">

            <?php foreach ($apis as $api): ?>
                <?php
                    $id = (int)$api['id'];
                    $editing = ($editId === $id);
                    $connected = ((int)$api['is_connected'] === 1);
                ?>

                <div class="col-12 col-lg-6" id="api-<?= $id ?>">
                    <div class="panel api-card">

                        <div class="panel-title">
                            <h5><?= e($api['name']) ?></h5>

                            <div class="d-flex align-items-center gap-2">
                                <?php if ($connected): ?>
                                    <span class="badge-s b-green api-status">Connected</span>
                                <?php else: ?>
                                    <span class="badge-s b-gray api-status">Not connected</span>
                                <?php endif; ?>

                                <form method="post" class="m-0"
                                      onsubmit="return confirm('Delete this API permanently?');">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $id ?>">

                                    <button type="submit" class="btn btn-sm btn-icon text-danger"
                                            title="Delete API" aria-label="Delete API">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Details form: only this form updates API details -->
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= $id ?>">

                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">API name</label>
                                    <input type="text" name="name" class="form-control"
                                           maxlength="60" required
                                           value="<?= e($api['name']) ?>"
                                           <?= $editing ? '' : 'readonly' ?>>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Endpoint URL</label>
                                    <input type="url" name="endpoint_url" class="form-control"
                                           maxlength="500"
                                           placeholder="https://api.example.com/v1/leads"
                                           value="<?= e($api['endpoint_url']) ?>"
                                           <?= $editing ? '' : 'readonly' ?>>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">API key</label>
                                    <input type="text" name="api_key" class="form-control"
                                           maxlength="255" autocomplete="off"
                                           value="<?= e($api['api_key']) ?>"
                                           <?= $editing ? '' : 'readonly' ?>>
                                </div>

                               
<div class="col-md-6">
    <label class="form-label">API secret</label>

    <div class="input-group">
        <input
            type="password"
            name="api_secret"
            class="form-control api-secret-input"
            maxlength="255"
            autocomplete="new-password"
            value="<?= e($api['api_secret']) ?>"
            <?= $editing ? '' : 'readonly' ?>
        >

        <button
            type="button"
            class="btn btn-outline-secondary toggle-api-secret"
            aria-label="Show API secret"
            title="Show API secret"
        >
            <i class="bi bi-eye"></i>
        </button>
    </div>
</div>

                            </div>

                            <div class="api-meta">
                                <i class="bi bi-clock-history me-1"></i>
                                Updated: <?= e($api['updated_at']) ?>
                            </div>

                            <div class="api-actions mt-3">
                                <?php if ($editing): ?>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-lg me-1"></i>Save changes
                                    </button>
                                    <a href="api-settings.php" class="btn btn-light">Cancel</a>
                                <?php else: ?>
                                    <a href="api-settings.php?edit=<?= $id ?>"
                                       class="btn btn-outline-primary">
                                        <i class="bi bi-pencil me-1"></i>Edit
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <!-- Separate form: Connect / Disconnect -->
                        <form method="post" class="mt-2">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="connected" value="<?= $connected ? 0 : 1 ?>">

                            <button type="submit"
                                    class="btn btn-light <?= $connected ? 'text-danger' : 'text-success' ?>">
                                <i class="bi <?= $connected ? 'bi-plug-fill' : 'bi-plug' ?> me-1"></i>
                                <?= $connected ? 'Disconnect' : 'Connect' ?>
                            </button>
                        </form>

                    </div>
                </div>
            <?php endforeach; ?>

            <div class="col-12 col-lg-6">
                <button type="button" class="api-add-card"
                        data-bs-toggle="modal" data-bs-target="#apiModal">
                    <i class="bi bi-plus-circle"></i>
                    <b>Add custom API</b>
                    <small>Housing, 99acres, REI, Website or another service</small>
                </button>
            </div>

        </div>
    </main>
</div>

<!-- Add API modal -->
<div class="modal fade" id="apiModal" tabindex="-1"
     aria-labelledby="apiModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="apiModalTitle">
                    <i class="bi bi-plug me-2"></i>Add custom API
                </h5>
                <button type="button" class="btn-close"
                        data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="add">

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">API name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control"
                               maxlength="60" required
                               placeholder="Housing, 99acres, REI">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Endpoint URL</label>
                        <input type="url" name="endpoint_url" class="form-control"
                               maxlength="500" placeholder="https://api.example.com/v1/leads">
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">API key</label>
                            <input type="text" name="api_key" class="form-control"
                                   maxlength="255" autocomplete="off">
                        </div>

                        
<div class="col-md-6">
    <label class="form-label">API secret</label>

    <div class="input-group">
        <input
            type="password"
            name="api_secret"
            class="form-control api-secret-input"
            maxlength="255"
            autocomplete="new-password"
            placeholder="Enter API secret"
        >

        <button
            type="button"
            class="btn btn-outline-secondary toggle-api-secret"
            aria-label="Show API secret"
            title="Show API secret"
        >
            <i class="bi bi-eye"></i>
        </button>
    </div>
</div>

                    </div>

                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox"
                               name="is_connected" id="isConnected" value="1" checked>
                        <label class="form-check-label" for="isConnected">
                            Mark as connected
                        </label>
                    </div>

                    <small class="text-muted d-block mt-2">
                        This saves the details only; it does not test the external API.
                    </small>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-gold">
                        <i class="bi bi-check-lg me-1"></i>Add API
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

<script>
document.addEventListener('click', function (event) {
    const button = event.target.closest('.toggle-api-secret');

    if (!button) return;

    const group = button.closest('.input-group');
    const input = group ? group.querySelector('.api-secret-input') : null;
    const icon = button.querySelector('i');

    if (!input || !icon) return;

    const showSecret = input.type === 'password';

    input.type = showSecret ? 'text' : 'password';
    icon.classList.toggle('bi-eye', !showSecret);
    icon.classList.toggle('bi-eye-slash', showSecret);

    button.setAttribute(
        'aria-label',
        showSecret ? 'Hide API secret' : 'Show API secret'
    );

    button.setAttribute(
        'title',
        showSecret ? 'Hide API secret' : 'Show API secret'
    );
});
</script>
 

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
<?php crm_foot(); ?>
</body>
</html>
