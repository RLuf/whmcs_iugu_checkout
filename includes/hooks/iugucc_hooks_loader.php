<?php
/**
 * WHMCS Global Hook Loader for Iugu Credit Card (`iugucc`).
 *
 * WHMCS reliably loads all files under `includes/hooks/*.php`; placing this lightweight
 * loader guarantees `modules/gateways/iugucc/hooks.php` runs across client area, invoice
 * creation, and daily cron cycles without modifying any WHMCS template files.
 */

declare(strict_types=1);

$iuguccHooksPath = __DIR__ . '/../../modules/gateways/iugucc/hooks.php';
if (is_file($iuguccHooksPath)) {
    require_once $iuguccHooksPath;
}
