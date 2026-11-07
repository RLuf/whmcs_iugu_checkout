<?php
/**
 * WHMCS Iugu Credit Card Gateway — Standalone PSR-4 Autoloader
 *
 * Ensures 100% plug-and-play installation on WHMCS 8.13.7 / cPanel environments
 * without requiring SSH or Composer execution on the target hosting server.
 *
 * @package    WHMCS\Module\Gateway\IuguCC
 * @author     RLuf / ImovelSite <https://imovelsite.com.br>
 * @copyright  2026 ImovelSite
 * @license    MIT
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'WHMCS\\Module\\Gateway\\IuguCC\\';
    $baseDir = __DIR__ . '/lib/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
