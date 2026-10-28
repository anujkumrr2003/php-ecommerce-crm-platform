<?php
/* web-coupons.php - discount codes for the online store */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/crud.php';
crm_guard();
require_once __DIR__ . '/includes/webcore/load.php';

crud_page([
    'title' => 'Coupons & offers', 'item' => 'coupon', 'table' => 'web_coupons', 'order' => 'id DESC', 'toggle' => 'is_active',
    'sub' => 'Percentage or flat discount codes. Customers enter the code in the cart.',
    'defaults' => ['type' => 'percent', 'is_active' => 1, 'usage_limit' => 0, 'per_user_limit' => 1, 'min_order' => 0, 'max_discount' => 0],
    'columns' => [
        ['label' => 'Code', 'render' => fn($r) => '<b>' . crm_e($r['code']) . '</b><br><small class="text-muted">' . crm_e($r['description']) . '</small>'],
        ['label' => 'Discount', 'render' => fn($r) => $r['type'] === 'percent' ? rtrim(rtrim($r['value'], '0'), '.') . '%' . ((float)$r['max_discount'] > 0 ? ' (max ' . wc_money($r['max_discount']) . ')' : '') : wc_money($r['value']) . ' flat'],
        ['label' => 'Min order', 'render' => fn($r) => (float)$r['min_order'] > 0 ? wc_money($r['min_order']) : '-'],
        ['label' => 'Used', 'render' => fn($r) => (int)$r['used_count'] . ((int)$r['usage_limit'] > 0 ? ' / ' . (int)$r['usage_limit'] : '')],
        ['label' => 'Valid', 'render' => fn($r) => ($r['starts_at'] ?: 'any time') . ' &rarr; ' . ($r['ends_at'] ?: 'no end')],
        ['label' => 'Status', 'filter' => true, 'render' => fn($r) => '<span class="badge-s b-' . ($r['is_active'] ? 'green' : 'gray') . '">' . ($r['is_active'] ? 'Active' : 'Off') . '</span>'],
    ],
    'fields' => [
        ['name' => 'code', 'label' => 'Coupon code', 'required' => true, 'max' => 40, 'upper' => true, 'readonly_on_edit' => true, 'col' => 4, 'placeholder' => 'WELCOME10'],
        ['name' => 'description', 'label' => 'Description (shown to customer)', 'max' => 200, 'col' => 8],
        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => ['percent' => 'Percentage (%)', 'flat' => 'Flat amount (Rs.)'], 'col' => 4],
        ['name' => 'value', 'label' => 'Value', 'type' => 'number', 'required' => true, 'min' => 0.01, 'col' => 4],
        ['name' => 'max_discount', 'label' => 'Maximum discount (0 = no limit)', 'type' => 'number', 'min' => 0, 'col' => 4],
        ['name' => 'min_order', 'label' => 'Minimum order amount', 'type' => 'number', 'min' => 0, 'col' => 4],
        ['name' => 'usage_limit', 'label' => 'Total uses (0 = unlimited)', 'type' => 'number', 'step' => '1', 'min' => 0, 'col' => 4],
        ['name' => 'per_user_limit', 'label' => 'Uses per customer (0 = unlimited)', 'type' => 'number', 'step' => '1', 'min' => 0, 'col' => 4],
        ['name' => 'starts_at', 'label' => 'Starts on', 'type' => 'date', 'col' => 4],
        ['name' => 'ends_at', 'label' => 'Ends on', 'type' => 'date', 'col' => 4],
        ['name' => 'is_active', 'label' => 'Active', 'type' => 'checkbox', 'col' => 4],
    ],
    'before_save' => function (array &$d, ?array $old) {
        if (isset($d['code']) && !preg_match('/^[A-Z0-9_-]{3,40}$/', $d['code'])) {
            return 'Coupon code can have only letters, numbers, - and _ (3 to 40 characters).';
        }
        if (($d['type'] ?? ($old['type'] ?? '')) === 'percent' && (float)$d['value'] > 100) {
            return 'Percentage cannot be more than 100.';
        }
        return null;
    },
]);
