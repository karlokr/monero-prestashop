# Monero Payments for PrestaShop

Accept Monero (XMR) payments in your PrestaShop store with a zero-knowledge ephemeral payment architecture.

## What This Does

- Generates a unique subaddress per order via `monero-wallet-rpc`
- Converts fiat totals to XMR using CryptoCompare with configurable cache TTL
- Holds all payment state in browser memory via HMAC-signed tokens (nothing persists on disk)
- Obfuscates completed orders to look like generic "Bank wire" payments in the database
- Quantizes and jitters order timestamps to resist timing correlation attacks
- Renders QR codes client-side on canvas (no external API calls)
- Returns a customer-side receipt as the only record linking an order to a subaddress

## Requirements

- PrestaShop 8.0+
- PHP 8.1+ with `curl` and `bcmath` extensions
- A running `monero-wallet-rpc` instance loaded with a **view-only wallet**
- Digest authentication enabled on wallet-rpc (`--rpc-login user:pass`)

## Installation

1. Copy the `modules/monero/` folder into your PrestaShop `modules/` directory
2. In the PrestaShop admin, go to **Modules > Module Manager** and install "Monero Payments"
3. Configure the wallet RPC host, username, password, and confirmation threshold

## Configuration

| Setting | Description | Default |
|---------|-------------|---------|
| Wallet RPC Host | URL to your monero-wallet-rpc (e.g. `http://monero-wallet-rpc:18082`) | — |
| RPC Username | Digest auth username | — |
| RPC Password | Digest auth password | — |
| Required Confirmations | Blockchain confirmations before accepting payment | 1 |
| Rate Cache TTL | How long to cache exchange rates (seconds) | 300 |
| Token TTL | How long a payment token stays valid (seconds) | 1800 |

## Architecture

The module uses a `WalletRpcClient` class that communicates with `monero-wallet-rpc` via JSON-RPC 2.0 with digest authentication. Only three RPC operations are used: `create_address`, `get_transfers`, and `get_address`.

Payment state is carried between browser and server through HMAC-SHA256 signed tokens that exist only in JavaScript memory. No subaddresses, amounts, or transaction data are written to the database, session, cookies, or filesystem.

After payment confirms, orders are obfuscated: `ps_orders.module` becomes `ps_wirepayment`, all payment method fields become "Bank wire", and timestamps are quantized into 6-hour buckets with ±3 hours of random jitter.

## License

MIT

## Thank you for your support!

Donations are graciously accepted for your continuing support in the development of this software and others.

- **XMR**: 8BwmJHCfeaL9z3f1DwjStW7i1bvwKPL8oXhDnfcXbjRNSQAxVk9PVFv74SoFWVGWEVVQDCfb1bTsa1S53KP18zrwVizUeqe
- **BTC**: bc1q3frlupheaz79v88t4hc8lgzfqwy4nekvc4gtj7
- **ETH**: 0x9038E310D0a6B8E7819A8b7c33E53ebCF6964eF9
- **SOL**: Gzea6q2aBmpUUPMwCcAUUPsxGqSua5k6HT8PaGHD3ewn
