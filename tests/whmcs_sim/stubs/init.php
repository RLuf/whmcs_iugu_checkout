<?php
/**
 * WHMCS 8.x SIMULATOR bootstrap (test double — NOT WHMCS).
 *
 * Provides just enough of the documented WHMCS runtime for the real module files
 * (modules/gateways/iugucc.php and modules/gateways/callback/iugucc.php) to run unmodified:
 *   - WHMCS constant, WHMCS\Database\Capsule::table() query subset used by the module
 *   - State persisted in a JSON file (env WHMCS_SIM_STATE) shared with the Node orchestrator
 *
 * Behaviour of the gateway/invoice helper functions follows developers.whmcs.com/payment-gateways/callbacks
 * and docs.whmcs.com (Invoice Management, Credit Balances, Payment Reversals).
 */

declare(strict_types=1);

namespace WHMCS\Database {
    final class SimQuery
    {
        /** @param array<int, array{0:string,1:mixed}> $wheres */
        public function __construct(private string $table, private array $wheres = [])
        {
        }

        public function where(string $column, mixed $value): self
        {
            $next = clone $this;
            $next->wheres[] = [$column, $value];
            return $next;
        }

        /** @return array<int, array<string, mixed>> */
        private function rows(): array
        {
            $rows = array_values((array) ($GLOBALS['SIM'][$this->table] ?? []));
            $rows = array_values(array_filter($rows, 'is_array'));
            return array_values(array_filter($rows, function (array $row): bool {
                foreach ($this->wheres as [$col, $val]) {
                    if ((string) ($row[$col] ?? '') !== (string) $val) {
                        return false;
                    }
                }
                return true;
            }));
        }

        public function first(): ?object
        {
            $rows = $this->rows();
            return isset($rows[0]) ? (object) $rows[0] : null;
        }

        public function value(string $column): mixed
        {
            $rows = $this->rows();
            return $rows[0][$column] ?? null;
        }

        public function sum(string $column): float
        {
            $total = 0.0;
            foreach ($this->rows() as $row) {
                $total += (float) ($row[$column] ?? 0);
            }
            return $total;
        }

        public function exists(): bool
        {
            return count($this->rows()) > 0;
        }
    }

    final class Capsule
    {
        public static function table(string $name): SimQuery
        {
            return new SimQuery($name);
        }
    }
}

namespace {
    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }

    $simStateFile = (string) getenv('WHMCS_SIM_STATE');
    if ($simStateFile === '' || !is_file($simStateFile)) {
        http_response_code(500);
        exit('WHMCS simulator: state file not configured.');
    }
    $GLOBALS['SIM_FILE'] = $simStateFile;
    $GLOBALS['SIM'] = json_decode((string) file_get_contents($simStateFile), true) ?: [];

    register_shutdown_function(static function (): void {
        // Keyed tables must stay JSON objects even when empty (PHP encodes [] as a JSON list).
        foreach (['tblinvoices', 'tblhosting', 'tblclients', 'gateways'] as $map) {
            if (($GLOBALS['SIM'][$map] ?? []) === []) {
                $GLOBALS['SIM'][$map] = new \stdClass();
            }
        }
        file_put_contents(
            $GLOBALS['SIM_FILE'],
            json_encode($GLOBALS['SIM'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    });

    /** Outstanding balance of an invoice: total − Σ(amountin − amountout). */
    function sim_balance(int|string $invoiceId): float
    {
        $inv = $GLOBALS['SIM']['tblinvoices'][(string) $invoiceId] ?? null;
        if ($inv === null) {
            return 0.0;
        }
        $paid = 0.0;
        foreach ($GLOBALS['SIM']['tblaccounts'] as $row) {
            if ((string) $row['invoiceid'] === (string) $invoiceId) {
                $paid += (float) $row['amountin'] - (float) $row['amountout'];
            }
        }
        return round((float) $inv['total'] - $paid, 2);
    }

    /** Client details array as WHMCS passes it in $params['clientdetails']. */
    function sim_client_details(int|string $clientId): array
    {
        $c = $GLOBALS['SIM']['tblclients'][(string) $clientId] ?? [];
        $c['userid'] = (int) $clientId;
        $c['id'] = (int) $clientId;
        return $c;
    }
}
