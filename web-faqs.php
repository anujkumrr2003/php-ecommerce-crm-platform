<?php
/* web-faqs.php - questions & answers shown on the FAQ page */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/crud.php';
crm_guard();
require_once __DIR__ . '/includes/webcore/load.php';

crud_page([
    'title' => 'FAQ', 'item' => 'question', 'table' => 'web_faqs', 'order' => 'sort_order, id', 'toggle' => 'is_active',
    'sub' => 'Frequently asked questions shown on the website FAQ page.',
    'defaults' => ['sort_order' => 100, 'is_active' => 1],
    'columns' => [
        ['label' => 'Question', 'render' => fn($r) => '<b>' . crm_e($r['question']) . '</b>'],
        ['label' => 'Answer', 'render' => fn($r) => '<span class="d-inline-block text-truncate" style="max-width:420px">' . crm_e(strip_tags(html_entity_decode($r['answer']))) . '</span>'],
        ['label' => 'Order', 'key' => 'sort_order'],
        ['label' => 'Status', 'filter' => true, 'render' => fn($r) => '<span class="badge-s b-' . ($r['is_active'] ? 'green' : 'gray') . '">' . ($r['is_active'] ? 'Shown' : 'Hidden') . '</span>'],
    ],
    'fields' => [
        ['name' => 'question', 'label' => 'Question', 'required' => true, 'max' => 250, 'col' => 12],
        ['name' => 'answer', 'label' => 'Answer', 'type' => 'textarea', 'required' => true, 'rows' => 5, 'col' => 12],
        ['name' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'step' => '1', 'col' => 6],
        ['name' => 'is_active', 'label' => 'Show on website', 'type' => 'checkbox', 'col' => 6],
    ],
]);
