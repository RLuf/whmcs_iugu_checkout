<?php
/**
 * WHMCS SIMULATOR — Admin "Refund through Gateway" (Invoice > Refund tab), test double.
 * Calls the REAL iugucc_refund($params) with the documented refund parameters and, on success,
 * records the refund transaction; a full refund moves the invoice to Refunded.
 */

declare(strict_types=1);

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/gatewayfunctions.php';
require_once __DIR__ . '/includes/invoicefunctions.php';

header('Content-Type: application/json; charset=utf-8');
$invoiceId = (int) ($_POST['invoiceid'] ?? 0);
$transId = (string) ($_POST['transid'] ?? '');
$inv = $GLOBALS['SIM']['tblinvoices'][(string) $invoiceId] ?? null;
$payment = null;
foreach ($GLOBALS['SIM']['tblaccounts'] as $row) {
    if ((string) $row['transid'] === $transId && (int) $row['invoiceid'] === $invoiceId && (float) $row['amountin'] > 0) {
        $payment = $row;
    }
}
if ($inv === null || $payment === null) {
    http_response_code(404);
    exit(json_encode(['status' => 'error', 'rawdata' => 'transaction not found']));
}
// WHMCS: "leave this blank to refund the entire transaction value"
$amount = ($_POST['amount'] ?? '') !== '' ? (float) $_POST['amount'] : (float) $payment['amountin'];

$gatewayName = (string) $payment['gateway'];
require_once __DIR__ . '/modules/gateways/' . $gatewayName . '.php';
$params = array_merge(getGatewayVariables($gatewayName), [
    'invoiceid' => $invoiceId,
    'transid' => $transId,
    'amount' => number_format($amount, 2, '.', ''),
    'currency' => 'BRL',
    'clientdetails' => sim_client_details($inv['userid']),
]);
$result = call_user_func($gatewayName . '_refund', $params);
logTransaction($gatewayName, $result['rawdata'] ?? '', 'Refund ' . ucfirst((string) ($result['status'] ?? 'error')));

if (($result['status'] ?? '') === 'success') {
    $GLOBALS['SIM']['tblaccounts'][] = [
        'id' => count($GLOBALS['SIM']['tblaccounts']) + 1,
        'invoiceid' => $invoiceId,
        'userid' => (int) $inv['userid'],
        'transid' => (string) ($result['transid'] ?? $transId),
        'amountin' => 0.0,
        'amountout' => round($amount, 2),
        'fees' => 0.0,
        'gateway' => $gatewayName,
        'refundid' => $payment['id'],
    ];
    $refunded = 0.0;
    foreach ($GLOBALS['SIM']['tblaccounts'] as $row) {
        if ((int) $row['invoiceid'] === $invoiceId) {
            $refunded += (float) $row['amountout'];
        }
    }
    if ($refunded >= (float) $payment['amountin'] - 0.001) {
        $GLOBALS['SIM']['tblinvoices'][(string) $invoiceId]['status'] = 'Refunded';
    }
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
