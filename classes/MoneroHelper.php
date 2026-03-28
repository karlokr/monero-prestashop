<?php
/**
 * MoneroHelper — shared utilities for the Monero payment module.
 *
 * Centralizes: RPC connection factory, exchange rate caching,
 * fiat-to-XMR conversion, and payment verification.
 *
 * No method in this class writes anything to the database
 * except the exchange rate cache in ps_configuration.
 *
 * @author karlokr
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/WalletRpcClient.php';

class MoneroHelper
{
    /**
     * Create a WalletRpcClient instance from module config.
     *
     * @return WalletRpcClient
     * @throws RuntimeException If RPC config is missing
     */
    public static function rpc(): WalletRpcClient
    {
        $host = Configuration::get('MONERO_WALLET');
        $user = Configuration::get('MONERO_RPC_USER');
        $pass = Configuration::get('MONERO_RPC_PASS');

        if (empty($host) || empty($user) || empty($pass)) {
            throw new RuntimeException('Monero RPC configuration is incomplete.');
        }

        return new WalletRpcClient($host . '/json_rpc', $user, $pass);
    }

    /**
     * Get the current XMR price in a fiat currency, with TTL caching.
     *
     * Cache is stored in Configuration::get('MONERO_RATE_CACHE') as JSON:
     *   {"rates": {"USD": 150.23, "EUR": 138.50, ...}, "ts": 1711500000}
     *
     * @param string $currencyCode ISO 4217 currency code (USD, EUR, etc.)
     * @return float|false XMR price in the given currency, or false on failure
     */
    public static function getXmrPrice($currencyCode)
    {
        $supported = ['USD', 'EUR', 'CAD', 'GBP', 'INR', 'BTC'];
        if (!in_array($currencyCode, $supported)) {
            $currencyCode = 'USD';
        }

        // Check cache
        $cacheJson = Configuration::get('MONERO_RATE_CACHE');
        if ($cacheJson) {
            $cache = json_decode($cacheJson, true);
            if (is_array($cache) && isset($cache['ts'], $cache['rates'])) {
                $ttl = (int) Configuration::get('MONERO_RATE_CACHE_TTL');
                if ($ttl <= 0) {
                    $ttl = 300;
                }
                if (time() - (int) $cache['ts'] < $ttl && isset($cache['rates'][$currencyCode])) {
                    return (float) $cache['rates'][$currencyCode];
                }
            }
        }

        // Fetch from CryptoCompare
        $url = 'https://min-api.cryptocompare.com/data/price?fsym=XMR&tsyms='
            . implode(',', $supported) . '&extraParams=monero_prestashop';

        $response = Tools::file_get_contents($url);
        if (!$response) {
            return false;
        }

        $prices = json_decode($response, true);
        if (!is_array($prices) || !isset($prices[$currencyCode])) {
            return false;
        }

        // Update cache
        Configuration::updateValue('MONERO_RATE_CACHE', json_encode([
            'rates' => $prices,
            'ts' => time(),
        ]));

        return (float) $prices[$currencyCode];
    }

    /**
     * Convert a fiat amount to XMR atomic units (piconero).
     *
     * Uses bcmath for precision. 1 XMR = 1,000,000,000,000 piconero.
     *
     * @param float  $fiatAmount   Amount in fiat currency
     * @param string $currencyCode ISO 4217 currency code
     * @return string Atomic units as a string (for bcmath precision)
     * @throws RuntimeException If price cannot be fetched
     */
    public static function convertToXmr($fiatAmount, $currencyCode)
    {
        $price = self::getXmrPrice($currencyCode);
        if (!$price || $price <= 0) {
            throw new RuntimeException('Failed to fetch XMR price for ' . $currencyCode);
        }

        // fiatAmount / price = XMR amount (up to 12 decimal places)
        $xmrAmount = bcdiv((string) $fiatAmount, (string) $price, 12);

        // Convert to atomic units: XMR * 1e12
        $atomicUnits = bcmul($xmrAmount, '1000000000000', 0);

        return $atomicUnits;
    }

    /**
     * Format atomic units as a human-readable XMR string.
     *
     * @param string $atomicUnits Atomic units (piconero)
     * @return string XMR amount with 12 decimal places
     */
    public static function formatXmr($atomicUnits)
    {
        return bcdiv((string) $atomicUnits, '1000000000000', 12);
    }

    /**
     * Check if a payment has been received at a specific subaddress.
     *
     * Queries wallet-rpc get_transfers for the subaddress index and compares
     * the total received against the expected amount.
     *
     * Returns a status array — all data stays in PHP memory, nothing persisted.
     *
     * @param WalletRpcClient $client           Wallet RPC client
     * @param int              $subaddrIndex     Subaddress index to check
     * @param string           $xmrAmountAtomic  Expected amount in atomic units
     * @param int              $requiredConfs    Required confirmations (0 = accept mempool)
     * @return array {status: pending|confirming|paid, received: string, confirmations: int, overpaid: bool}
     */
    public static function verifyPayment(WalletRpcClient $client, $subaddrIndex, $xmrAmountAtomic, $requiredConfs = 0)
    {
        try {
            $transfers = $client->fetchTransfers(0, (int) $subaddrIndex);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Payment: verification error: ' . $e->getMessage(), 3);
            return [
                'status' => 'pending',
                'confirmed_received' => '0',
                'pool_received' => '0',
                'confirmations' => 0,
                'overpaid' => false,
            ];
        }

        // Sum confirmed transfers meeting the confirmation threshold
        $confirmedTotal = '0';
        $minConfirmations = PHP_INT_MAX;

        if (isset($transfers['in']) && is_array($transfers['in'])) {
            foreach ($transfers['in'] as $tx) {
                $txConfs = isset($tx['confirmations']) ? (int) $tx['confirmations'] : 0;
                if ($txConfs >= $requiredConfs) {
                    $confirmedTotal = bcadd($confirmedTotal, (string) $tx['amount'], 0);
                }
                if ($txConfs < $minConfirmations) {
                    $minConfirmations = $txConfs;
                }
            }
        }

        // Sum unconfirmed pool transfers
        $poolTotal = '0';
        if (isset($transfers['pool']) && is_array($transfers['pool'])) {
            foreach ($transfers['pool'] as $tx) {
                $poolTotal = bcadd($poolTotal, (string) $tx['amount'], 0);
                $minConfirmations = 0;
            }
        }

        if ($minConfirmations === PHP_INT_MAX) {
            $minConfirmations = 0;
        }

        $totalReceived = bcadd($confirmedTotal, $poolTotal, 0);
        $overpaid = bccomp($confirmedTotal, $xmrAmountAtomic, 0) > 0;

        // Determine status
        if (bccomp($confirmedTotal, $xmrAmountAtomic, 0) >= 0) {
            // Enough confirmed funds
            return [
                'status' => 'paid',
                'confirmed_received' => $confirmedTotal,
                'pool_received' => $poolTotal,
                'total_received' => bcadd($confirmedTotal, $poolTotal, 0),
                'confirmations' => $minConfirmations,
                'overpaid' => $overpaid,
            ];
        }

        if (bccomp($totalReceived, $xmrAmountAtomic, 0) >= 0) {
            // Enough total but not enough confirmed
            return [
                'status' => 'confirming',
                'confirmed_received' => $confirmedTotal,
                'pool_received' => $poolTotal,
                'total_received' => $totalReceived,
                'confirmations' => $minConfirmations,
                'required' => $requiredConfs,
                'overpaid' => false,
            ];
        }

        // Not enough funds yet
        return [
            'status' => 'pending',
            'confirmed_received' => $confirmedTotal,
            'pool_received' => $poolTotal,
            'total_received' => $totalReceived,
            'confirmations' => $minConfirmations,
            'overpaid' => false,
        ];
    }
}
