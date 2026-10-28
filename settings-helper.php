<?php
/* =====================================================================
 *  CRM - settings-helper.php
 *  Shared by settings.php, create-bill.php and billing.php.
 *  Reads the `settings` table (see settings.sql) and gives every page the
 *  same shop details / invoice rules. Add a new setting in 3 steps:
 *    1) add it to settings_defaults() below   2) add a field in settings.php
 *    3) use $SET['your_key'] anywhere (or shop_for_js() for the invoice)
 * ===================================================================== */

const SETTINGS_GST_RATES = [0, 5, 12, 18];

function settings_defaults(): array
{
  return [
    'store_name'          => 'My Store',
    'business_email'      => '',
    'phone'               => '+91 98100 00000',
    'gstin'               => '07ABCDE1234F1Z5',
    'address'             => '12 Khan Market Road, New Delhi 110003',
    'place_of_supply'     => 'Delhi (07)',
    'currency'            => 'INR (₹)',
    'timezone'            => 'Asia/Kolkata',
    'date_format'         => 'DD MMM YYYY',
    'logo'                => '',
    'invoice_prefix'      => 'INV-',
    'next_invoice_number' => '1001',
    'default_gst'         => '12',
    'invoice_terms'       => "1. Goods once sold can be exchanged within 7 days with this bill.\n2. No cash refund. Bridal and altered items are not returnable.\n3. Subject to Delhi jurisdiction.",
    'footer_note'         => 'Thank you for shopping with {store_name}. We look forward to seeing you again!',
    'round_off'           => '1',
    'show_gstin'          => '1',
    'variant_label_1'     => 'Size',
    'variant_label_2'     => 'Color',
    'product_group_label' => 'Suitable for',
    'product_material_label' => 'Material / specification',
    'customer_preferences' => '',
  ];
}

/** label => [symbol, major unit word, minor unit word] */
function settings_currencies(): array
{
  return [
    'INR (₹)' => ['₹', 'Rupees', 'Paise'],
    // 'USD ($)' => ['$', 'Dollars', 'Cents'],
    // 'EUR (€)' => ['€', 'Euros', 'Cents'],
    // 'GBP (£)' => ['£', 'Pounds', 'Pence'],
    // 'AED'     => ['AED ', 'Dirhams', 'Fils'],
  ];
}

/** IANA id => label */
function settings_timezones(): array
{
  return [
    'Asia/Kolkata'     => 'Asia/Kolkata (IST)',
    // 'Asia/Dubai'       => 'Asia/Dubai (GST)',
    // 'Asia/Singapore'   => 'Asia/Singapore (SGT)',
    // 'Europe/London'    => 'Europe/London (GMT/BST)',
    // 'America/New_York' => 'America/New_York (EST/EDT)',
    // 'UTC'              => 'UTC',
  ];
}

/** setting label => PHP date() format */
function settings_date_formats(): array
{
  return [
    'DD MMM YYYY' => 'd M Y',
    'DD/MM/YYYY'  => 'd/m/Y',
    'MM/DD/YYYY'  => 'm/d/Y',
    'YYYY-MM-DD'  => 'Y-m-d',
  ];
}

/** Defaults + whatever is saved in the DB. Never throws (falls back to defaults). */
function get_settings(PDO $pdo): array
{
  $s = settings_defaults();
  try {
    foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $r) {
      if (array_key_exists($r['setting_key'], $s)) $s[$r['setting_key']] = (string)$r['setting_value'];
    }
  } catch (Throwable $e) {
    error_log('[settings-helper] ' . $e->getMessage());
  }
  return $s;
}

function app_timezone(array $s): string
{
  return isset(settings_timezones()[$s['timezone']]) ? $s['timezone'] : 'Asia/Kolkata';
}

function app_date(string $ymd, array $s): string
{
  $fmt = settings_date_formats()[$s['date_format']] ?? 'd M Y';
  $ts  = strtotime($ymd);
  return $ts ? date($fmt, $ts) : $ymd;
}

function currency_info(array $s): array
{
  $c = settings_currencies();
  return $c[$s['currency']] ?? $c['INR (₹)'];
}

/** Public URL of the logo ('' when none). Only our own upload pattern is accepted. */
function logo_url(array $s): string
{
  $l = (string)$s['logo'];
  return preg_match('#^assets/uploads/logo_[a-f0-9]+\.(png|jpe?g|webp)$#', $l) ? $l : '';
}

/** The shop block used by the invoice (passed to JavaScript as JSON) */
function shop_for_js(array $s): array
{
  [$sym, $word, $minor] = currency_info($s);
  return [
    'name'       => $s['store_name'],
    'addr'       => $s['address'],
    'gstin'      => $s['show_gstin'] === '1' ? $s['gstin'] : '',
    'phone'      => $s['phone'],
    'email'      => $s['business_email'],
    'logo'       => logo_url($s),
    'terms'      => $s['invoice_terms'],
    'footer'     => str_replace('{store_name}', $s['store_name'], $s['footer_note']),
    'pos'        => $s['place_of_supply'],
    'sym'        => $sym,
    'word'       => $word,
    'minor'      => $minor,
    'round'      => $s['round_off'] === '1',
    'default_gst' => in_array((int)$s['default_gst'], SETTINGS_GST_RATES, true) ? (int)$s['default_gst'] : 12,
  ];
}

/** Next invoice number to SHOW on the form (does not reserve it). */
function preview_invoice_no(PDO $pdo, array $s): string
{
  $prefix = (string)$s['invoice_prefix'];
  $n      = max(1, (int)$s['next_invoice_number']);
  $chk    = $pdo->prepare('SELECT 1 FROM bills WHERE invoice_no = ? LIMIT 1');
  for ($i = 0; $i < 1000; $i++) {
    $chk->execute([$prefix . $n]);
    if (!$chk->fetchColumn()) break;
    $n++;
  }
  return $prefix . $n;
}

/**
 * Reserve the next invoice number. MUST be called inside a transaction:
 * the settings row is locked so two bills saved at the same moment never get the same number.
 */
function take_invoice_no(PDO $pdo): string
{
  $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('invoice_prefix','INV-'), ('next_invoice_number','1001')");
  $st = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('invoice_prefix','next_invoice_number') FOR UPDATE");
  $v = [];
  foreach ($st->fetchAll() as $r) $v[$r['setting_key']] = (string)$r['setting_value'];
  $prefix = $v['invoice_prefix'] ?? 'INV-';
  $n      = max(1, (int)($v['next_invoice_number'] ?? 1001));

  $chk = $pdo->prepare('SELECT 1 FROM bills WHERE invoice_no = ? LIMIT 1');
  for ($i = 0; $i < 1000; $i++) {                 // skip a number that already exists
    $chk->execute([$prefix . $n]);
    if (!$chk->fetchColumn()) break;
    $n++;
  }
  $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'next_invoice_number'")->execute([(string)($n + 1)]);
  return $prefix . $n;
}
