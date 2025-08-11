<?php
namespace Invoicemate\Utils;

use PDO;

/**
 * Helper for writing audit log entries.
 */
class AuditLogger
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Record an audit log entry. Any exceptions are swallowed to avoid
     * interrupting the primary operation.
     *
     * @param int    $orgId Organization identifier
     * @param int    $userId User identifier performing the action
     * @param string $table Table name affected
     * @param int    $recordId Record identifier within table
     * @param string $action Action name (INSERT/UPDATE/DELETE)
     * @param array  $changes Associative array describing changes
     */
    public function log(int $orgId, int $userId, string $table, int $recordId, string $action, array $changes = []): void
    {
        try {
            $stmt = $this->pdo->prepare('INSERT INTO audit_log (organization_id, user_id, table_name, record_id, action, changes_json) VALUES (:org, :user, :table, :recordId, :action, :changes)');
            $stmt->execute([
                ':org' => $orgId,
                ':user' => $userId,
                ':table' => $table,
                ':recordId' => $recordId,
                ':action' => $action,
                ':changes' => json_encode($changes, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            // Swallow exceptions to ensure audit does not break primary operation
        }
    }
}