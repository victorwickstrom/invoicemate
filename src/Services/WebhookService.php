<?php
declare(strict_types=1);

namespace Invoicemate\Services;

use GuzzleHttp\Client;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * WebhookService is responsible for dispatching event payloads to
 * subscribers.  It can be injected wherever domain events are created,
 * such as after creating an invoice or contact.  This implementation
 * performs immediate HTTP POSTs and does not yet include retry logic.
 */
class WebhookService
{
    private PDO $pdo;
    private Client $httpClient;
    private ?LoggerInterface $logger;

    public function __construct(PDO $pdo, ?LoggerInterface $logger = null)
    {
        $this->pdo = $pdo;
        $this->httpClient = new Client(['timeout' => 5.0]);
        $this->logger = $logger;
    }

    /**
     * Trigger a named event for a given organisation.
     *
     * @param int   $organizationId The organisation that generated the event.
     * @param string $eventName     The name of the event as stored in webhook_events.name.
     * @param array  $payload       The JSON payload to deliver to subscribers.
     */
    public function triggerEvent(int $organizationId, string $eventName, array $payload): void
    {
        // Look up the event id
        $stmtEvent = $this->pdo->prepare('SELECT id FROM webhook_events WHERE name = :name');
        $stmtEvent->execute([':name' => $eventName]);
        $eventId = (int) $stmtEvent->fetchColumn();
        if (!$eventId) {
            return;
        }
        // Fetch subscriptions
        $stmtSubs = $this->pdo->prepare('SELECT uri, secret FROM webhook_subscriptions WHERE organization_id = :org AND event_id = :event');
        $stmtSubs->execute([':org' => $organizationId, ':event' => $eventId]);
        $subs = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);
        if (!$subs) {
            return;
        }
        $body = json_encode($payload);
        foreach ($subs as $sub) {
            $headers = ['Content-Type' => 'application/json'];
            // Include an HMAC signature header if a secret is configured
            if (!empty($sub['secret'])) {
                $signature = hash_hmac('sha256', $body, (string)$sub['secret']);
                $headers['X-Signature'] = $signature;
            }
            try {
                $this->httpClient->post($sub['uri'], [
                    'headers' => $headers,
                    'body'    => $body,
                ]);
            } catch (\Throwable $e) {
                // Best effort: log and continue
                if ($this->logger) {
                    $this->logger->error('Webhook dispatch failed', [
                        'uri'       => $sub['uri'],
                        'event'     => $eventName,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}