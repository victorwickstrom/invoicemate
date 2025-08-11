<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Psr\Container\ContainerInterface;

/**
 * Webhook subscription and event management routes.
 *
 * These endpoints allow clients to subscribe to and unsubscribe from
 * domain events emitted by the system.  Subscriptions are stored in
 * `webhook_subscriptions` and mapped to events defined in
 * `webhook_events`.  Events can be listed for introspection.  When
 * business logic elsewhere triggers an event, a WebhookService (see
 * Services/WebhookService.php) is responsible for delivering the
 * payloads to each subscriber.
 */
return function (App $app) {
    /** @var ContainerInterface $container */
    $container = $app->getContainer();

    // List available webhook events
    $app->get('/v1/{organizationId}/webhooks/events', function (Request $request, Response $response, array $args) use ($container) {
        /** @var \PDO $pdo */
        $pdo = $container->get('db');
        $stmt = $pdo->query('SELECT id, name, description FROM webhook_events ORDER BY id');
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($events));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // List current subscriptions for an organisation
    $app->get('/v1/{organizationId}/webhooks/subscriptions', function (Request $request, Response $response, array $args) use ($container) {
        /** @var \PDO $pdo */
        $pdo = $container->get('db');
        $orgId = (int)$args['organizationId'];
        $stmt = $pdo->prepare('SELECT s.id, s.event_id, e.name as event_name, s.uri, s.secret FROM webhook_subscriptions s JOIN webhook_events e ON s.event_id = e.id WHERE s.organization_id = :org');
        $stmt->execute([':org' => $orgId]);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($subs));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Subscribe to a webhook event
    $app->post('/v1/{organizationId}/webhooks/subscribe', function (Request $request, Response $response, array $args) use ($container) {
        /** @var \PDO $pdo */
        $pdo = $container->get('db');
        $orgId = (int)$args['organizationId'];
        $data = json_decode($request->getBody()->getContents(), true);
        $eventId = $data['eventId'] ?? null;
        $uri     = $data['uri'] ?? null;
        $secret  = $data['secret'] ?? null;
        if (!$eventId || !$uri) {
            $response->getBody()->write(json_encode(['error' => 'eventId and uri are required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        // Validate that the event exists
        $stmtEvent = $pdo->prepare('SELECT COUNT(*) FROM webhook_events WHERE id = :id');
        $stmtEvent->execute([':id' => $eventId]);
        if ((int)$stmtEvent->fetchColumn() === 0) {
            $response->getBody()->write(json_encode(['error' => 'Invalid event id']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        // Insert subscription
        $stmt = $pdo->prepare('INSERT INTO webhook_subscriptions (organization_id, event_id, uri, secret, created_at) VALUES (:org, :event, :uri, :secret, CURRENT_TIMESTAMP)');
        $stmt->execute([
            ':org'   => $orgId,
            ':event' => $eventId,
            ':uri'   => $uri,
            ':secret'=> $secret,
        ]);
        $response->getBody()->write(json_encode(['message' => 'Subscription created']));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    });

    // Unsubscribe from a webhook event
    $app->delete('/v1/{organizationId}/webhooks/unsubscribe', function (Request $request, Response $response, array $args) use ($container) {
        /** @var \PDO $pdo */
        $pdo = $container->get('db');
        $orgId = (int)$args['organizationId'];
        $data = json_decode($request->getBody()->getContents(), true);
        $eventId = $data['eventId'] ?? null;
        $uri     = $data['uri'] ?? null;
        if (!$eventId || !$uri) {
            $response->getBody()->write(json_encode(['error' => 'eventId and uri are required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        $stmt = $pdo->prepare('DELETE FROM webhook_subscriptions WHERE organization_id = :org AND event_id = :event AND uri = :uri');
        $stmt->execute([
            ':org'   => $orgId,
            ':event' => $eventId,
            ':uri'   => $uri,
        ]);
        if ($stmt->rowCount() === 0) {
            $response->getBody()->write(json_encode(['error' => 'Subscription not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        $response->getBody()->write(json_encode(['message' => 'Subscription removed']));
        return $response->withHeader('Content-Type', 'application/json');
    });
};