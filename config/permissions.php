<?php

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
|
| Permission keys are defined in code so every server-side check refers to a
| known key. Which roles hold which permissions lives in the database and is
| edited from Admin → Roles. The role presets below only seed new installs
| (and the migration that introduced roles).
|
*/

return [
    'groups' => [
        'Products' => [
            'products.view' => 'View products and categories',
            'products.create' => 'Create products and categories',
            'products.edit' => 'Edit products, categories and prices',
            'products.delete' => 'Archive products and delete categories',
        ],
        'Orders' => [
            'orders.view' => 'View orders',
            'orders.update' => 'Update order status and details',
            'orders.cancel' => 'Cancel orders',
            'orders.refund' => 'Issue refunds',
        ],
        'Inventory' => [
            'inventory.view' => 'View stock and stock history',
            'inventory.adjust' => 'Adjust stock',
        ],
        'Purchases' => [
            'purchases.view' => 'View purchases and suppliers',
            'purchases.create' => 'Create purchases and suppliers',
            'purchases.edit' => 'Edit purchases, suppliers and supplier payments',
        ],
        'Customers' => [
            'customers.view' => 'View customers',
            'customers.create' => 'Create customers',
            'customers.edit' => 'Edit customers and record customer payments',
        ],
        'POS' => [
            'pos.sell' => 'Sell from the POS',
        ],
        'Accounting' => [
            'accounting.view' => 'View accounts, expenses and transactions',
            'accounting.create' => 'Record expenses, deposits and withdrawals',
            'accounting.edit' => 'Edit expenses and accounts',
        ],
        'Reports' => [
            'reports.view' => 'View reports and financial figures',
            'reports.export' => 'Export reports',
        ],
        'Marketing' => [
            'marketing.manage' => 'Manage coupons',
        ],
        'Staff' => [
            'staff.view' => 'View staff and roles',
            'staff.create' => 'Create staff and roles',
            'staff.edit' => 'Edit staff, roles and permissions',
            'staff.delete' => 'Deactivate staff and delete roles',
        ],
        'System' => [
            'settings.manage' => 'Manage store settings',
            'audit.view' => 'View the activity log',
        ],
    ],

    'roles' => [
        'super_admin' => [
            'name' => 'Super Admin',
            'description' => 'Full access to everything, including staff, permissions and settings.',
            'permissions' => ['*'],
        ],
        'manager' => [
            'name' => 'Manager',
            'description' => 'Runs day-to-day operations. Cannot manage staff accounts or store settings.',
            'permissions' => [
                'products.view', 'products.create', 'products.edit', 'products.delete',
                'orders.view', 'orders.update', 'orders.cancel', 'orders.refund',
                'inventory.view', 'inventory.adjust',
                'purchases.view', 'purchases.create', 'purchases.edit',
                'customers.view', 'customers.create', 'customers.edit',
                'pos.sell',
                'accounting.view', 'accounting.create', 'accounting.edit',
                'reports.view', 'reports.export',
                'marketing.manage',
                'staff.view',
                'audit.view',
            ],
        ],
        'sales_staff' => [
            'name' => 'Sales Staff',
            'description' => 'Sells in the shop and handles online orders.',
            'permissions' => [
                'products.view',
                'orders.view', 'orders.update', 'orders.cancel',
                'inventory.view',
                'customers.view', 'customers.create', 'customers.edit',
                'pos.sell',
            ],
        ],
        'accountant' => [
            'name' => 'Accountant',
            'description' => 'Handles money: expenses, payments, refunds and reports.',
            'permissions' => [
                'orders.view', 'orders.refund',
                'purchases.view',
                'customers.view',
                'accounting.view', 'accounting.create', 'accounting.edit',
                'reports.view', 'reports.export',
            ],
        ],
        'warehouse_staff' => [
            'name' => 'Warehouse Staff',
            'description' => 'Receives purchases, packs orders and keeps stock accurate.',
            'permissions' => [
                'products.view',
                'orders.view', 'orders.update',
                'inventory.view', 'inventory.adjust',
                'purchases.view', 'purchases.create', 'purchases.edit',
            ],
        ],
    ],
];
