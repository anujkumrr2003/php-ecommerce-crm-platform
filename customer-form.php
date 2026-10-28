<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
crm_guard();
/*
|--------------------------------------------------------------------------
| customer-form.php  -  Customer registration backend (Admin / Self Added)
|--------------------------------------------------------------------------
| Admin jo customer add karta hai uska source hamesha "Self Added" save hota hai.
| Save hone ke baad WhatsApp welcome message jata hai aur result
| customers.wa_status + message_history me save hota hai.
|
| Called by:
|   POST add-customer.php form  ->  customer-form.php
|   GET  customer-form.php?custom_fields=1   (JSON list of active custom fields)
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}

require_once __DIR__ . '/customer-core.php';   // config.php, $conn, WhatsApp helpers

const SELF_ADDED = 'Self Added';

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/*
|--------------------------------------------------------------------------
| GET: active custom fields as JSON  (customer-form.php?custom_fields=1)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['custom_fields'])) {
    try {
        $res = mysqli_query(
            $conn,
            "SELECT id, label, field_key, field_type, placeholder, options_json, is_required
               FROM customer_custom_fields
              WHERE is_active = 1
              ORDER BY sort_order ASC, id ASC"
        );
        $fields = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $opts = json_decode($row['options_json'] ?? '[]', true);
            $fields[] = [
                'id'          => (int)$row['id'],
                'label'       => $row['label'],
                'key'         => $row['field_key'],
                'type'        => $row['field_type'],
                'placeholder' => $row['placeholder'] ?? '',
                'options'     => is_array($opts) ? $opts : [],
                'required'    => (bool)$row['is_required'],
            ];
        }
        jsonResponse(['success' => true, 'fields' => $fields]);
    } catch (Throwable $e) {
        error_log('custom_fields load: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Could not load custom fields.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

/* Go back to the form and show a message (keeps what the user typed) */
function backWithError(string $message): void
{
    $old = $_POST;
    unset($old['csrf_token']);
    $_SESSION['customer_form_error'] = $message;
    $_SESSION['customer_form_old']   = $old;
    header('Location: add-customer.php');
    exit;
}

/* CSRF check: token is created by add-customer.php */
$posted = $_POST['csrf_token'] ?? '';
if (
    empty($_SESSION['customer_form_csrf']) ||
    !is_string($posted) ||
    !hash_equals($_SESSION['customer_form_csrf'], $posted)
) {
    $_SESSION['customer_form_error'] = 'Your session expired. Please submit the form again.';
    header('Location: add-customer.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| 1. Read + validate the standard fields
|--------------------------------------------------------------------------
*/
try {

    $name        = cleanValue($_POST['name'] ?? '');
    $mobile      = preg_replace('/\D+/', '', cleanValue($_POST['mobile'] ?? ''));
    $email       = cleanValue($_POST['email'] ?? '');
    $gender      = cleanValue($_POST['gender'] ?? '');
    $dobRaw      = cleanValue($_POST['dob'] ?? '');
    $annivRaw    = cleanValue($_POST['anniv'] ?? '');
    $address     = cleanValue($_POST['addr'] ?? '');
    $city        = cleanValue($_POST['city'] ?? '');
    $state       = cleanValue($_POST['state'] ?? '');
    $pin         = preg_replace('/\D+/', '', cleanValue($_POST['pin'] ?? ''));
    $notes       = cleanValue($_POST['notes'] ?? '');
    $category    = cleanValue($_POST['cat'] ?? '');
    $regDateRaw  = cleanValue($_POST['regdate'] ?? '');

    /* Admin se add hua customer = hamesha "Self Added" (form ka src field ignore hota hai) */
    $source = SELF_ADDED;

    /* +91 / 0 prefix support: keep the last 10 digits */
    if (strlen($mobile) > 10 && preg_match('/^(91|0)/', $mobile)) {
        $mobile = substr($mobile, -10);
    }

    if (
        $name === '' || $mobile === '' || $gender === '' || $city === '' ||
        $state === '' || $pin === '' || $category === ''
    ) {
        throw new UserError('Please fill in all required fields.');
    }
    if (mb_strlen($name) > 150) {
        throw new UserError('Name is too long (max 150 characters).');
    }
    if (mb_strlen($city) > 100) {
        throw new UserError('City is too long (max 100 characters).');
    }
    if (!preg_match('/^[6-9][0-9]{9}$/', $mobile)) {
        throw new UserError('Enter a 10-digit mobile number starting with 6, 7, 8 or 9.');
    }
    if (!preg_match('/^[0-9]{6}$/', $pin)) {
        throw new UserError('Enter a valid 6-digit PIN code.');
    }
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) {
        throw new UserError('Enter a valid email address.');
    }
    if (!in_array($gender, ['Female', 'Male', 'Other'], true)) {
        throw new UserError('Please choose a valid gender.');
    }
    if (!in_array($category, ['Regular', 'Premium', 'VIP', 'Wholesale'], true)) {
        throw new UserError('Please choose a valid customer category.');
    }

    $dob         = validDateOrNull($dobRaw, 'date of birth');
    $anniversary = validDateOrNull($annivRaw, 'anniversary');
    $regDate     = validDateOrNull($regDateRaw !== '' ? $regDateRaw : date('Y-m-d'), 'registration date');

    /* Clothing preferences -> JSON array (only allowed values are kept) */
    $prefs = $_POST['preferences'] ?? [];
    if (!is_array($prefs)) {
        $prefs = [];
    }
    $allowedPrefs = crm_customer_prefs();
    $prefs = array_values(array_intersect(array_map('strval', $prefs), $allowedPrefs));
    $preferencesJson = json_encode($prefs, JSON_UNESCAPED_UNICODE);

    $whatsappConsent = isset($_POST['whatsapp_consent']) ? 1 : 0;
    $smsConsent      = isset($_POST['sms_consent']) ? 1 : 0;

    /*
    |----------------------------------------------------------------------
    | 2. Save customer + custom values in ONE transaction
    |----------------------------------------------------------------------
    */
    mysqli_begin_transaction($conn);

    try {
        /* Duplicate mobile check (UNIQUE key also protects from race conditions) */
        $chk = mysqli_prepare($conn, 'SELECT id FROM customers WHERE mobile = ? LIMIT 1');
        mysqli_stmt_bind_param($chk, 's', $mobile);
        mysqli_stmt_execute($chk);
        mysqli_stmt_store_result($chk);
        $exists = mysqli_stmt_num_rows($chk) > 0;
        mysqli_stmt_close($chk);
        if ($exists) {
            throw new UserError('This mobile number is already registered.');
        }

        /* wa_status = 'pending' -> neeche WhatsApp send hone ke baad 'sent' / 'failed' ho jayega */
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO customers
                (name, mobile, email, gender, dob, anniversary,
                 address, city, state, pin,
                 clothing_preferences, notes, category, registration_date,
                 source, whatsapp_consent, sms_consent, wa_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        );

        $emailDb   = $email   !== '' ? $email   : null;
        $addressDb = $address !== '' ? $address : null;
        $notesDb   = $notes   !== '' ? $notes   : null;

        mysqli_stmt_bind_param(
            $stmt,
            'sssssssssssssssii',
            $name,
            $mobile,
            $emailDb,
            $gender,
            $dob,
            $anniversary,
            $addressDb,
            $city,
            $state,
            $pin,
            $preferencesJson,
            $notesDb,
            $category,
            $regDate,
            $source,
            $whatsappConsent,
            $smsConsent
        );
        mysqli_stmt_execute($stmt);
        $customerId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        if ($customerId <= 0) {
            throw new RuntimeException('Customer insert did not return an id.');
        }

        /* Custom fields: posted as custom[FIELD_ID] */
        $customPosted = $_POST['custom'] ?? [];
        if (!is_array($customPosted)) {
            $customPosted = [];
        }

        $fieldRes = mysqli_query(
            $conn,
            "SELECT id, label, field_type, options_json, is_required
               FROM customer_custom_fields
              WHERE is_active = 1
              ORDER BY sort_order ASC, id ASC"
        );

        $valStmt = mysqli_prepare(
            $conn,
            'INSERT INTO customer_custom_values (customer_id, field_id, field_value) VALUES (?, ?, ?)'
        );

        while ($field = mysqli_fetch_assoc($fieldRes)) {
            $fieldId = (int)$field['id'];
            $label   = $field['label'];
            $raw     = $customPosted[$fieldId] ?? '';

            if (is_array($raw)) {
                throw new UserError('Invalid value for "' . $label . '".');
            }
            $value = trim((string)$raw);

            if ($value === '') {
                if ((int)$field['is_required'] === 1) {
                    throw new UserError('"' . $label . '" is required.');
                }
                continue;
            }
            if (mb_strlen($value) > 1000) {
                throw new UserError('"' . $label . '" is too long (max 1000 characters).');
            }

            switch ($field['field_type']) {
                case 'dropdown':
                    $options = json_decode($field['options_json'] ?? '[]', true);
                    if (!is_array($options) || !in_array($value, $options, true)) {
                        throw new UserError('Please choose a valid option for "' . $label . '".');
                    }
                    break;
                case 'number':
                    if (!is_numeric($value)) {
                        throw new UserError('"' . $label . '" must be a number.');
                    }
                    break;
                case 'date':
                    $value = validDateOrNull($value, $label);
                    break;
            }

            mysqli_stmt_bind_param($valStmt, 'iis', $customerId, $fieldId, $value);
            mysqli_stmt_execute($valStmt);
        }
        mysqli_stmt_close($valStmt);

        mysqli_commit($conn);
    } catch (Throwable $inner) {
        mysqli_rollback($conn);
        throw $inner;
    }

    /*
    |----------------------------------------------------------------------
    | 3. Customer saved. Ab WhatsApp welcome message.
    |    Ye kabhi save ko nahi rokta. Result customers.wa_status +
    |    message_history dono me save hota hai.
    |    Agar fail hua to cron-sync-apis.php baad me retry karega.
    |----------------------------------------------------------------------
    */
    $_SESSION['customer_form_wa']       = 'failed';
    $_SESSION['customer_form_wa_error'] = '';
    try {
        $wa = sendWelcomeWhatsapp($conn, $customerId, $name, $mobile, 'welcome');
        $_SESSION['customer_form_wa'] = !empty($wa['ok']) ? 'sent' : 'failed';
        if (empty($wa['ok'])) {
            $_SESSION['customer_form_wa_error'] = (string)($wa['error'] ?? '');
        }
    } catch (Throwable $waEx) {
        $_SESSION['customer_form_wa_error'] = $waEx->getMessage();
        error_log('Welcome WhatsApp exception: ' . $waEx->getMessage());
    }

    $_SESSION['customer_form_csrf'] = bin2hex(random_bytes(32));
    unset($_SESSION['customer_form_old'], $_SESSION['customer_form_error']);

    header('Location: customers.php?saved=1&customer_id=' . $customerId);
    exit;
} catch (UserError $e) {
    backWithError($e->getMessage());
} catch (mysqli_sql_exception $e) {
    if ((int)$e->getCode() === 1062) {
        backWithError('This mobile number is already registered.');
    }
    error_log('customer-form.php DB error [' . $e->getCode() . ']: ' . $e->getMessage());
    backWithError('Could not save the customer because of a database error. Please try again.');
} catch (Throwable $e) {
    error_log('customer-form.php error: ' . $e->getMessage());
    backWithError('Something went wrong while saving. Please try again.');
}