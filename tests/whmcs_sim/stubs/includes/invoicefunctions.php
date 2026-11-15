<?php
/**
 * WHMCS SIMULATOR — includes/invoicefunctions.php (test double).
 */

declare(strict_types=1);

/** Validates the invoice ID; halts execution when it does not exist (any status is valid). */
function checkCbInvoiceID(int|string $invoiceId, string $gatewayName): int
{
    if (!isset($GLOBALS['SIM']['tblinvoices'][(string) $invoiceId])) {
        logTransaction($gatewayName, ['invoiceid' => $invoiceId], 'Invoice ID Not Found');
        exit;
    }
    return (int) $invoiceId;
}

/** Halts execution when a transaction with the same ID already exists (duplicate callback guard). */
function checkCbTransID(string $transactionId): void
{
    foreach ($GLOBALS['SIM']['tblaccounts'] as $row) {
        if ((string) $row['transid'] === $transactionId) {
            exit;
        }
    }
}

/**
 * Applies a payment to an invoice. Amount 0 or '' means the full balance due.
 * When the balance reaches zero on an Unpaid invoice the invoice becomes Paid and the paid-invoice
 * automation runs (service activation, Add Funds credit, Mass Payment sub-invoices, overpayment credit).
 */
function addInvoicePayment(
    int|string $invoiceId,
    string $transactionId,
    float|int|string $amount,
    float|int|string $fees,
    string $gateway,
    bool $noEmail = false
): bool {
    if (!isset($GLOBALS['SIM']['tblinvoices'][(string) $invoiceId])) {
        return false;
    }
    if ($amount === '' || (float) $amount == 0.0) {
        $amount = sim_balance($invoiceId);
    }
    $GLOBALS['SIM']['tblaccounts'][] = [
        'id' => count($GLOBALS['SIM']['tblaccounts']) + 1,
        'invoiceid' => (int) $invoiceId,
        'userid' => (int) $GLOBALS['SIM']['tblinvoices'][(string) $invoiceId]['userid'],
        'transid' => $transactionId,
        'amountin' => round((float) $amount, 2),
        'amountout' => 0.0,
        'fees' => round((float) $fees, 2),
        'gateway' => $gateway,
    ];
    sim_process_paid_invoice($invoiceId, $transactionId, $gateway);
    return true;
}

function sim_process_paid_invoice(int|string $invoiceId, string $transactionId, string $gateway): void
{
    $inv = &$GLOBALS['SIM']['tblinvoices'][(string) $invoiceId];
    $balance = sim_balance($invoiceId);
    if ($balance > 0.0 || $inv['status'] !== 'Unpaid') {
        return;
    }
    $inv['status'] = 'Paid';
    $clientId = (string) $inv['userid'];
    if ($balance < 0.0) {
        // docs.whmcs.com Credit Balances: "Clients automatically receive credit for any overpayments"
        $GLOBALS['SIM']['tblclients'][$clientId]['credit'] = round(
            (float) ($GLOBALS['SIM']['tblclients'][$clientId]['credit'] ?? 0) + abs($balance),
            2
        );
    }
    foreach ($inv['items'] ?? [] as $item) {
        $relid = (string) ($item['relid'] ?? '');
        switch ($item['type'] ?? '') {
            case 'Hosting':
                if (($GLOBALS['SIM']['tblhosting'][$relid]['domainstatus'] ?? '') === 'Pending') {
                    $GLOBALS['SIM']['tblhosting'][$relid]['domainstatus'] = 'Active';
                }
                break;
            case 'AddFunds':
                $GLOBALS['SIM']['tblclients'][$clientId]['credit'] = round(
                    (float) ($GLOBALS['SIM']['tblclients'][$clientId]['credit'] ?? 0) + (float) $item['amount'],
                    2
                );
                break;
            case 'Invoice': // Mass Payment: the paid amount is applied to each grouped invoice
                $childBalance = sim_balance($relid);
                if ($childBalance > 0.0 && isset($GLOBALS['SIM']['tblinvoices'][$relid])) {
                    $GLOBALS['SIM']['tblaccounts'][] = [
                        'id' => count($GLOBALS['SIM']['tblaccounts']) + 1,
                        'invoiceid' => (int) $relid,
                        'userid' => (int) $clientId,
                        'transid' => $transactionId,
                        'amountin' => $childBalance,
                        'amountout' => 0.0,
                        'fees' => 0.0,
                        'gateway' => $gateway,
                    ];
                    sim_process_paid_invoice($relid, $transactionId, $gateway);
                }
                break;
        }
    }
}
