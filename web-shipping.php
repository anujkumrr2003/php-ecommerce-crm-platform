<?php
/* web-shipping.php - state-wise shipping charge (overrides the general charge from Website settings) */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/crud.php';
crm_guard();
require_once __DIR__ . '/includes/webcore/load.php';

crud_page([
    'title' => 'State-wise shipping', 'item' => 'shipping rate', 'table' => 'web_shipping_rates', 'order' => 'state', 'toggle' => 'is_active',
    'sub' => 'Optional. If a state is listed here, its charge is used instead of the general shipping charge. General charge and free-shipping amount: Website settings -> Shipping.',
    'top_html' => '<div class="alert alert-light border small"><i class="bi bi-info-circle me-1"></i>General charge: <b>' . wc_money(wc_setting('web_ship_flat', '0')) . '</b>, free above <b>' . wc_money(wc_setting('web_ship_free_above', '0')) . '</b>. Change in <a href="web-settings.php#shipping">Website settings</a>.</div>',
    'defaults' => ['charge' => 0, 'is_active' => 1],
    'columns' => [
        ['label' => 'State', 'render' => fn($r) => '<b>' . crm_e($r['state']) . '</b>'],
        ['label' => 'Charge', 'render' => fn($r) => (float)$r['charge'] > 0 ? wc_money($r['charge']) : 'Free'],
        ['label' => 'Free above', 'render' => fn($r) => $r['free_above'] === null ? 'general amount' : wc_money($r['free_above'])],
        ['label' => 'Status', 'filter' => true, 'render' => fn($r) => '<span class="badge-s b-' . ($r['is_active'] ? 'green' : 'gray') . '">' . ($r['is_active'] ? 'Active' : 'Off') . '</span>'],
    ],
    'fields' => [
        ['name' => 'state', 'label' => 'State', 'type' => 'select', 'options' => array_combine(wc_states(), wc_states()), 'readonly_on_edit' => true, 'col' => 4],
        ['name' => 'charge', 'label' => 'Shipping charge (Rs.)', 'type' => 'number', 'min' => 0, 'col' => 4],
        ['name' => 'free_above', 'label' => 'Free above (Rs., optional)', 'type' => 'number', 'min' => 0, 'nullable' => true, 'col' => 4],
        ['name' => 'is_active', 'label' => 'Active', 'type' => 'checkbox', 'col' => 12],
    ],
]);
