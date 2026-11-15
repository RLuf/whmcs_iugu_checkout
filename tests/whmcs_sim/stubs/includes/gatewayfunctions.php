<?php
/**
 * WHMCS SIMULATOR — includes/gatewayfunctions.php (test double).
 */

declare(strict_types=1);

/**
 * Returns the gateway configuration plus system variables, or an array without 'type'
 * when the module is not activated (developers.whmcs.com: "Die if module is not active").
 *
 * @return array<string, mixed>
 */
function getGatewayVariables(string $gatewayName, int|string $invoiceId = ''): array
{
    $gw = $GLOBALS['SIM']['gateways'][$gatewayName] ?? null;
    if ($gw === null || empty($gw['active'])) {
        return [];
    }
    return array_merge($gw['settings'], [
        'type' => 'Invoices',
        'name' => $gatewayName,
        'paymentmethod' => $gatewayName,
        'companyname' => $GLOBALS['SIM']['system']['companyname'],
        'systemurl' => $GLOBALS['SIM']['system']['systemurl'],
        'langpaynow' => 'Pagar Agora',
        'whmcsVersion' => $GLOBALS['SIM']['system']['whmcsVersion'],
    ]);
}

/** Adds an entry to Billing > Gateway Log (tblgatewaylog). */
function logTransaction(string $gateway, mixed $data, string $result): void
{
    $GLOBALS['SIM']['tblgatewaylog'][] = ['gateway' => $gateway, 'data' => $data, 'result' => $result];
}
