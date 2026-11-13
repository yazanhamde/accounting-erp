<?php
// actions.php — entry point, loads all action modules
// Split into modules under includes/actions/ for maintainability

require_once __DIR__ . '/actions/helpers.php';
require_once __DIR__ . '/actions/customers.php';
require_once __DIR__ . '/actions/suppliers.php';
require_once __DIR__ . '/actions/personal.php';
require_once __DIR__ . '/actions/expenses.php';
require_once __DIR__ . '/actions/banks.php';
require_once __DIR__ . '/actions/employees.php';
require_once __DIR__ . '/actions/car_employees.php';
require_once __DIR__ . '/actions/workshop.php';
require_once __DIR__ . '/actions/checks.php';
require_once __DIR__ . '/actions/attachments.php';
require_once __DIR__ . '/actions/overview.php';
