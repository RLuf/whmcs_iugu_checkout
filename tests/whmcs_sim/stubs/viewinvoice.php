<?php
/**
 * WHMCS SIMULATOR — viewinvoice.php (test double).
 * Calls the REAL iugucc_link($params) with the parameters WHMCS documents for third-party gateways.
 * WHMCS only renders the payment button for invoices in Unpaid status.
 */

declare(strict_types=1);

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/gatewayfunctions.php';
require_once __DIR__ . '/includes/invoicefunctions.php';

$invoiceId = (int) ($_GET['id'] ?? 0);
$inv = $GLOBALS['SIM']['tblinvoices'][(string) $invoiceId] ?? null;
header('Content-Type: text/html; charset=utf-8');
if ($inv === null) {
    http_response_code(404);
    exit('Invoice not found');
}
echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Fatura #' . $invoiceId . '</title></head><body>';
echo '<div id="invoice-status" data-status="' . htmlspecialchars($inv['status']) . '">' . htmlspecialchars($inv['status']) . '</div>';

$gatewayName = (string) $inv['paymentmethod'];
$gatewayParams = getGatewayVariables($gatewayName);
if ($inv['status'] === 'Unpaid' && !empty($gatewayParams['type'])) {
    require_once __DIR__ . '/modules/gateways/' . $gatewayName . '.php';
    $params = array_merge($gatewayParams, [
        'invoiceid' => $invoiceId,
        'invoicenum' => (string) $invoiceId,
        'description' => $GLOBALS['SIM']['system']['companyname'] . ' - Fatura #' . $invoiceId,
        'amount' => number_format(sim_balance($invoiceId), 2, '.', ''),
        'currency' => 'BRL',
        'clientdetails' => sim_client_details($inv['userid']),
        'returnurl' => $GLOBALS['SIM']['system']['systemurl'] . 'viewinvoice.php?id=' . $invoiceId,
    ]);
    echo '<div id="payment-button">' . call_user_func($gatewayName . '_link', $params) . '</div>';
}
echo '</body></html>';
