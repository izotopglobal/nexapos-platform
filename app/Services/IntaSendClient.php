<?php

namespace Platform\Services;

use Platform\Core\Redactor;
use RuntimeException;

/**
 * All outbound calls to IntaSend, using the platform's own secret +
 * publishable keys (sibling of PaystackClient - see that class's doc for
 * why the phone never holds these directly). Payload shapes and field
 * names here are taken from IntaSend's own official PHP SDK
 * (github.com/IntaSend/intasend-php, src/Collection.php and
 * src/Wallet.php) rather than their prose docs, which were found to
 * disagree with the SDK on response field names (e.g. a wallet's id
 * comes back as `id`, not `wallet_id`) - the SDK is what real merchants'
 * code actually runs against.
 *
 * Unlike Paystack, IntaSend has no subaccount/bank-detail step for
 * collection: a WORKING wallet just needs a currency + label, created
 * lazily on first use (see ensureIntaSendWallet in public/index.php).
 * Money collected into that wallet stays IntaSend-held until a separate
 * disbursement call moves it to the shop's real bank/M-Pesa account -
 * that disbursement step does not exist yet (paused pending IntaSend's
 * answer on automatic payouts), so this client intentionally has no
 * transfer/disbursement methods.
 */
class IntaSendClient
{
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../../config/platform.php';
        if (trim((string) $this->config['intasend_secret_key']) === '') {
            throw new RuntimeException('Platform IntaSend secret key is not configured.');
        }
        if (trim((string) $this->config['intasend_publishable_key']) === '') {
            throw new RuntimeException('Platform IntaSend publishable key is not configured.');
        }
    }

    /**
     * WORKING wallet = a sub-account IntaSend uses to isolate one shop's
     * collected funds from every other shop's, all under this one
     * platform account. can_disburse is deliberately false: nothing in
     * this codebase can move money out of a wallet yet, so there is
     * nothing to gain from asking IntaSend to allow it.
     */
    public function createWallet(string $label): array
    {
        return $this->request('POST', '/wallets/', [
            'wallet_type' => 'WORKING',
            'currency' => 'KES',
            'label' => $label,
            'can_disburse' => false,
        ], auth: true);
    }

    /**
     * $amountMinor is cents, same convention as PaystackClient - IntaSend
     * itself wants a major-unit decimal (e.g. 10.00), so the conversion
     * happens here, once, rather than trusting every caller to remember.
     */
    public function mpesaStkPush(
        int $amountMinor,
        string $phoneNumber,
        string $apiRef,
        ?string $name,
        ?string $email,
        string $walletId
    ): array {
        return $this->request('POST', '/payment/mpesa-stk-push/', [
            'public_key' => $this->config['intasend_publishable_key'],
            'currency' => 'KES',
            'method' => 'MPESA_STK_PUSH',
            'amount' => round($amountMinor / 100, 2),
            'phone_number' => $phoneNumber,
            'api_ref' => $apiRef,
            'name' => $name,
            'email' => $email,
            'wallet_id' => $walletId,
        ], auth: true);
    }

    /**
     * Deliberately unauthenticated (no Bearer header) - mirrors the
     * official SDK exactly, which nulls out the token before this call.
     * Only the publishable key + invoice_id are needed, since checking
     * "is this invoice paid" was designed by IntaSend to be safe to
     * expose without the secret key (e.g. for a browser-side poll).
     */
    public function status(string $invoiceId): array
    {
        return $this->request('POST', '/payment/status/', [
            'public_key' => $this->config['intasend_publishable_key'],
            'invoice_id' => $invoiceId,
        ], auth: false);
    }

    private function request(string $method, string $path, array $payload, bool $auth): array
    {
        $url = rtrim((string) $this->config['intasend_api_base'], '/') . '/' . ltrim($path, '/');
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($auth) {
            $headers[] = 'Authorization: Bearer ' . trim((string) $this->config['intasend_secret_key']);
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(10, (int) $this->config['intasend_connect_timeout']),
            CURLOPT_TIMEOUT => max(30, (int) $this->config['intasend_timeout']),
            CURLOPT_DNS_CACHE_TIMEOUT => 300,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($payload),
        ];
        if (defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('IntaSend request failed: ' . $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('IntaSend response was invalid.');
        }
        $this->log($method . ' ' . $path, $httpCode, $payload, $data);

        return ['http_code' => $httpCode, 'body' => $data];
    }

    private function log(string $action, int $httpCode, array $request, array $response): void
    {
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $entry = [
            'time' => date('c'),
            'action' => $action,
            'http_code' => $httpCode,
            'request' => Redactor::redact($request),
            'response' => Redactor::redact($response),
        ];
        @file_put_contents($logDir . '/intasend-platform.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }
}
