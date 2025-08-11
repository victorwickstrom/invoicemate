<?php
namespace Invoicemate\Accounting;

use PDO;

/**
 * Service for voucher related operations such as numbering,
 * balancing and period locking.
 */
class VoucherService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Generate the next sequential voucher number for a table and organization.
     * Uses a SELECT MAX(...)+1 query inside a transaction.
     */
    public function nextVoucherNumber(int $orgId, string $table, string $col = 'voucher_number'): int
    {
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare("SELECT COALESCE(MAX($col), 0) + 1 AS next_num FROM {$table} WHERE organization_id = :org");
        $stmt->execute([':org' => $orgId]);
        $next = (int)$stmt->fetchColumn();
        return $next;
    }

    /**
     * Ensure that the provided voucher lines balance.
     *
     * @param array $lines Array of ['debit' => float, 'credit' => float]
     * @throws \RuntimeException if unbalanced.
     */
    public function ensureBalanced(array $lines): void
    {
        $sumDebit = 0.0;
        $sumCredit = 0.0;
        foreach ($lines as $line) {
            $sumDebit += isset($line['debit']) ? (float)$line['debit'] : 0.0;
            $sumCredit += isset($line['credit']) ? (float)$line['credit'] : 0.0;
        }
        $diff = round($sumDebit - $sumCredit, 2);
        if (abs($diff) > 0.0001) {
            throw new \RuntimeException('Unbalanced voucher: diff ' . $diff);
        }
    }

    /**
     * Ensure that the given date is not within a locked period.
     *
     * @throws \RuntimeException if locked.
     */
    public function ensureUnlocked(int $orgId, string $date): void
    {
        // Extract year from date (YYYY-MM-DD)
        $year = (int)substr($date, 0, 4);
        $stmt = $this->pdo->prepare('SELECT locked_until FROM accounting_year WHERE organization_id = :org AND year = :year');
        $stmt->execute([':org' => $orgId, ':year' => $year]);
        $lockedUntil = $stmt->fetchColumn();
        if ($lockedUntil && $date <= $lockedUntil) {
            throw new \RuntimeException('Accounting period locked until ' . $lockedUntil);
        }
    }
}