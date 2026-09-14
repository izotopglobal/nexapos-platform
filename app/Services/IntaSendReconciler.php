<?php

namespace Platform\Services;

use Closure;
use PDO;
use RuntimeException;

/**
 * IntaSend's sibling of PaymentReconciler - same idempotency-ledger and
 * "webhook is only a trigger to re-fetch the authoritative status"
 * shape, adapted for IntaSend's own state machine (PENDING/PROCESSING/
 * COMPLETED/FAILED, see webhooks/payment-collection-events.md) and its
 * challenge-string webhook auth instead of Paystack's HMAC signature.
 */
final class IntaSendReconciler
{
    private PDO $pdo;
    private Closure $checkStatus;

    public function __construct(PDO $pdo, callable $checkStatus)
    {
        $this->pdo = $pdo;
        $this->checkStatus = Closure::fromCallable($checkStatus);
    }

    /**
     * IntaSend doesn't sign webhook bodies - it echoes back a merchant-
     * configured `challenge` string instead (see docs/webhooks/how-to-
     * setup.md). hash_equals for the same timing-attack reason Paystack's
     * hasValidSignature uses it, even though this is a plain string
     * compare rather than an HMAC.
     */
    public static function hasValidChallenge(string $challenge, string $expected): bool
    {
        $expected = trim($expected);
        return $expected !== '' && hash_equals($expected, trim($challenge));
    }

    public function verifyForClient(string $reference, int $clientId): array
    {
        $transaction = $this->findTransaction($reference, $clientId);
        if ($transaction === null) {
            return ['outcome' => 'not_found', 'response' => null];
        }

        $result = ($this->checkStatus)($transaction['invoice_id']);
        return [
            'outcome' => $this->reconcile($transaction, $result),
            // Re-reads from DB rather than reusing $transaction in memory,
            // so this reflects the status write reconcile() just made.
            'response' => $this->toResponse($reference, $clientId),
        ];
    }

    public function handleWebhook(array $event, string $rawPayload): array
    {
        $invoiceId = trim((string) ($event['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            throw new RuntimeException('IntaSend webhook has no invoice_id.');
        }

        $eventKey = hash('sha256', 'intasend|' . $invoiceId . '|' . (string) ($event['state'] ?? ''));
        $insert = $this->pdo->prepare('INSERT IGNORE INTO intasend_webhook_events
            (event_key, state, reference, payload_sha256)
            VALUES (?, ?, ?, ?)');
        $insert->execute([$eventKey, (string) ($event['state'] ?? ''), (string) ($event['api_ref'] ?? ''), hash('sha256', $rawPayload)]);

        $claim = $this->pdo->prepare("UPDATE intasend_webhook_events
            SET attempts = attempts + IF(status = 'received', 0, 1),
                status = 'processing',
                last_error = NULL
            WHERE event_key = ?
              AND (status IN ('received', 'failed')
                   OR (status = 'processing' AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)))");
        $claim->execute([$eventKey]);
        if ($claim->rowCount() !== 1) {
            return ['outcome' => 'duplicate'];
        }

        try {
            $transaction = $this->findTransactionByInvoice($invoiceId);
            if ($transaction === null) {
                throw new RuntimeException('Webhook transaction is not recorded locally yet.');
            }

            // Re-fetch from IntaSend rather than trusting the webhook
            // body's own state/value fields - same reasoning as
            // PaymentReconciler::handleWebhook, and doubly relevant here
            // since the challenge string alone (no HMAC over the body)
            // is a weaker guarantee that the payload wasn't tampered
            // with in transit.
            $result = ($this->checkStatus)($invoiceId);
            $outcome = $this->reconcile($transaction, $result);
            if ($outcome === 'pending') {
                throw new RuntimeException('IntaSend state is not final yet.');
            }

            $this->markEvent($eventKey, 'processed', $outcome === 'mismatch' ? 'Payment details did not match.' : null);
            return ['outcome' => $outcome];
        } catch (\Throwable $e) {
            $this->markEvent($eventKey, 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Normalizes to the exact same envelope shape PaystackGateway.verify
     * already parses in the Flutter app ({status, data: {status, amount,
     * currency, reference}}) - IntaSendGateway.verify's parsing logic is
     * then nearly identical to Paystack's, rather than inventing a
     * second response shape for callers to learn.
     */
    public function toResponse(string $reference, int $clientId): array
    {
        $transaction = $this->findTransaction($reference, $clientId);
        if ($transaction === null) {
            return ['status' => false, 'message' => 'Transaction not found.', 'http_code' => 404];
        }
        $success = $transaction['status'] === 'verified_success';
        $failed = $transaction['status'] === 'verified_failed';
        return [
            'http_code' => 200,
            'body' => [
                'status' => true,
                'data' => [
                    'status' => $success ? 'success' : ($failed ? 'failed' : 'pending'),
                    'amount' => (int) $transaction['amount_minor'],
                    'currency' => $transaction['currency'],
                    'reference' => $transaction['reference'],
                ],
            ],
        ];
    }

    private function findTransaction(string $reference, ?int $clientId): ?array
    {
        if ($clientId === null) {
            $stmt = $this->pdo->prepare('SELECT * FROM intasend_transactions WHERE reference = ?');
            $stmt->execute([$reference]);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM intasend_transactions WHERE reference = ? AND client_id = ?');
            $stmt->execute([$reference, $clientId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function findTransactionByInvoice(string $invoiceId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM intasend_transactions WHERE invoice_id = ?');
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function reconcile(array $transaction, array $result): string
    {
        $invoice = (array) ($result['body']['invoice'] ?? []);
        $state = strtoupper(trim((string) ($invoice['state'] ?? '')));
        if ($state === 'COMPLETE' || $state === 'COMPLETED') {
            $value = (float) ($invoice['value'] ?? $invoice['net_amount'] ?? 0);
            $verifiedAmount = (int) round($value * 100);
            if ($verifiedAmount !== (int) $transaction['amount_minor']) {
                $this->updateStatus((int) $transaction['id'], 'verified_failed');
                error_log('[nexapos_platform] IntaSend verification mismatch for reference=' . $transaction['reference']);
                return 'mismatch';
            }
            $this->updateStatus((int) $transaction['id'], 'verified_success');
            return 'success';
        }

        if ($state === 'FAILED') {
            $this->updateStatus((int) $transaction['id'], 'verified_failed');
            return 'failed';
        }
        return 'pending';
    }

    private function updateStatus(int $transactionId, string $status): void
    {
        $update = $this->pdo->prepare('UPDATE intasend_transactions SET status = ?, verified_at = UTC_TIMESTAMP() WHERE id = ?');
        $update->execute([$status, $transactionId]);
    }

    private function markEvent(string $eventKey, string $status, ?string $error): void
    {
        $message = $error === null ? null : substr($error, 0, 500);
        $processedAt = $status === 'processed' ? 'UTC_TIMESTAMP()' : 'NULL';
        $stmt = $this->pdo->prepare("UPDATE intasend_webhook_events
            SET status = ?, last_error = ?, processed_at = $processedAt
            WHERE event_key = ?");
        $stmt->execute([$status, $message, $eventKey]);
    }
}
