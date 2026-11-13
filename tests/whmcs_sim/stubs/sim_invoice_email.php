<?php
/**
 * WHMCS SIMULATOR — contexts other than the invoice page where WHMCS also calls <gateway>_link():
 * the invoice e-mail merge field {$invoice_payment_link} and the "order complete" page (test double).
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
$gatewayName = (string) $inv['paymentmethod'];
require_once __DIR__ . '/modules/gateways/' . $gatewayName . '.php';
$params = array_merge(getGatewayVariables($gatewayName), [
    'invoiceid' => $invoiceId,
    'description' => $GLOBALS['SIM']['system']['companyname'] . ' - Fatura #' . $invoiceId,
    'amount' => number_format(sim_balance($invoiceId), 2, '.', ''),
    'currency' => 'BRL',
    'clientdetails' => sim_client_details($inv['userid']),
]);
echo call_user_func($gatewayName . '_link', $params);
