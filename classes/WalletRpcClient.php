<?php
/**
 * WalletRpcClient — JSON-RPC 2.0 client for monero-wallet-rpc.
 *
 * Handles digest-authenticated communication with a monero-wallet-rpc
 * instance. Exposes only the three operations the module requires:
 * subaddress generation, transfer queries, and subaddress resolution.
 *
 * @author karlokr
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class WalletRpcClient
{
    /** @var string Full URL to the JSON-RPC endpoint (e.g. http://host:18082/json_rpc) */
    private $endpoint;

    /** @var string Digest auth username */
    private $user;

    /** @var string Digest auth password */
    private $pass;

    /** @var int Connection timeout in seconds */
    private $connectTimeout;

    /** @var int Request timeout in seconds */
    private $requestTimeout;

    /**
     * Create a new wallet RPC client.
     *
     * @param string $endpoint       Full URL to the JSON-RPC endpoint
     * @param string $user           Digest auth username
     * @param string $pass           Digest auth password
     * @param int    $connectTimeout Connection timeout in seconds (default 12)
     * @param int    $requestTimeout Total request timeout in seconds (default 12)
     * @throws RuntimeException If cURL or JSON extensions are missing
     */
    public function __construct(
        string $endpoint,
        string $user,
        string $pass,
        int $connectTimeout = 12,
        int $requestTimeout = 12
    ) {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('WalletRpcClient requires the cURL extension.');
        }
        if (!extension_loaded('json')) {
            throw new RuntimeException('WalletRpcClient requires the JSON extension.');
        }

        $this->endpoint = $endpoint;
        $this->user = $user;
        $this->pass = $pass;
        $this->connectTimeout = $connectTimeout;
        $this->requestTimeout = $requestTimeout;
    }

    // ──────────────────────────────────────────────────────────────
    //  Public API — only the operations the module actually uses
    // ──────────────────────────────────────────────────────────────

    /**
     * Generate a fresh subaddress under the given account.
     *
     * Calls the create_address RPC method. The label should always be
     * an empty string for privacy (no order/customer identifiers stored
     * in the wallet).
     *
     * @param int    $accountIndex Wallet account index (default 0)
     * @param string $label        Subaddress label (should be '' for privacy)
     * @return array { "address": string, "address_index": int }
     * @throws RuntimeException On RPC or transport error
     */
    public function generateSubaddress(int $accountIndex = 0, string $label = ''): array
    {
        return $this->call('create_address', [
            'account_index' => $accountIndex,
            'label'         => $label,
        ]);
    }

    /**
     * Fetch confirmed and mempool transfers for a specific subaddress.
     *
     * Calls get_transfers filtered to a single subaddress index, requesting
     * both confirmed (in) and unconfirmed (pool) transactions.
     *
     * @param int $accountIndex  Wallet account index (default 0)
     * @param int $subaddrIndex  Subaddress index to filter on
     * @return array { "in": [...], "pool": [...] } (either key may be absent)
     * @throws RuntimeException On RPC or transport error
     */
    public function fetchTransfers(int $accountIndex = 0, int $subaddrIndex = 0): array
    {
        return $this->call('get_transfers', [
            'in'              => true,
            'pool'            => true,
            'account_index'   => $accountIndex,
            'subaddr_indices' => [$subaddrIndex],
        ]);
    }

    /**
     * Resolve a subaddress index to its address string.
     *
     * Calls get_address filtered to a single index and extracts the
     * matching entry from the response. Falls back to the full result
     * if the expected structure is absent.
     *
     * @param int $accountIndex Wallet account index (default 0)
     * @param int $addressIndex Subaddress index to look up
     * @return array { "address": string, "address_index": int, ... }
     * @throws RuntimeException On RPC or transport error
     */
    public function resolveSubaddress(int $accountIndex = 0, int $addressIndex = 0): array
    {
        $result = $this->call('get_address', [
            'account_index' => $accountIndex,
            'address_index' => [$addressIndex],
        ]);

        if (isset($result['addresses']) && is_array($result['addresses'])) {
            foreach ($result['addresses'] as $entry) {
                if ((int) $entry['address_index'] === $addressIndex) {
                    return $entry;
                }
            }
        }

        return $result;
    }

    // ──────────────────────────────────────────────────────────────
    //  Transport layer
    // ──────────────────────────────────────────────────────────────

    /**
     * Execute a JSON-RPC 2.0 method call against the wallet endpoint.
     *
     * Encodes the request, sends it via cURL with digest auth, decodes
     * the response, and returns the "result" field. Throws on any
     * transport, HTTP, or RPC-level error.
     *
     * @param string $method RPC method name
     * @param array  $params Method parameters
     * @return array Decoded "result" from the JSON-RPC response
     * @throws RuntimeException On any failure
     */
    private function call(string $method, array $params = []): array
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id'      => '0',
            'method'  => $method,
            'params'  => $params,
        ]);

        $raw = $this->post($body);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                'RPC response is not valid JSON: ' . substr($raw, 0, 200)
            );
        }

        if (isset($decoded['error'])) {
            $msg = $decoded['error']['message'] ?? 'Unknown RPC error';
            $data = isset($decoded['error']['data']) ? (' | ' . $decoded['error']['data']) : '';
            throw new RuntimeException('RPC error on ' . $method . ': ' . $msg . $data);
        }

        if (!isset($decoded['result'])) {
            throw new RuntimeException('RPC response missing "result" field for ' . $method);
        }

        return $decoded['result'];
    }

    /**
     * Send an HTTP POST with digest authentication and return the raw body.
     *
     * @param string $body JSON-encoded request body
     * @return string Raw response body
     * @throws RuntimeException On cURL init failure, HTTP error, or connection error
     */
    private function post(string $body): string
    {
        $ch = curl_init();
        if (!$ch) {
            throw new RuntimeException('Failed to initialize cURL handle.');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->endpoint,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_ENCODING       => 'gzip,deflate',
            CURLOPT_HTTPAUTH       => CURLAUTH_DIGEST,
            CURLOPT_USERPWD        => $this->user . ':' . $this->pass,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->requestTimeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        $curlNo   = curl_errno($ch);
        curl_close($ch);

        if ($curlNo !== 0) {
            throw new RuntimeException(
                'cURL error connecting to ' . $this->endpoint . ': ' . $curlErr
            );
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('HTTP ' . $httpCode . ' from ' . $this->endpoint);
        }

        return $response;
    }
}
