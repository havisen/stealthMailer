<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class SubmissionRepository
{
    public const TERMINAL_STATES = [
        'accepted',
        'partially_accepted',
        'failed',
        'uncertain',
        'expired',
        'canceled',
    ];

    public const STATES = [
        'queued',
        'sending',
        'accepted',
        'partially_accepted',
        'failed',
        'uncertain',
        'expired',
        'canceled',
    ];

    public function __construct(protected PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $submission
     * @param list<array{type:string,ordinal:int,ciphertext:string,address_hash:string}> $recipients
     */
    public function insert(array $submission, array $recipients): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO outgoing_submissions
                (public_id, account_id, account_reference, source, browser_operation_id,
                 message_id, snapshot_ciphertext, snapshot_hash, state, attempts,
                 deadline_at, sent_copy_state, created_at, updated_at)
             VALUES
                (:public_id, :account_id, :account_reference, :source, :browser_operation_id,
                 :message_id, :snapshot_ciphertext, :snapshot_hash, \'queued\', 0,
                 :deadline_at, \'not_requested\', :created_at, :updated_at)'
        );
        $statement->execute([
            ':public_id' => $submission['public_id'],
            ':account_id' => (int) $submission['account_id'],
            ':account_reference' => $submission['account_reference'],
            ':source' => $submission['source'],
            ':browser_operation_id' => $submission['browser_operation_id'],
            ':message_id' => $submission['message_id'],
            ':snapshot_ciphertext' => $submission['snapshot_ciphertext'],
            ':snapshot_hash' => $submission['snapshot_hash'],
            ':deadline_at' => $submission['deadline_at'],
            ':created_at' => $submission['created_at'],
            ':updated_at' => $submission['updated_at'],
        ]);
        $submissionId = (int) $this->pdo->lastInsertId();

        $recipientStatement = $this->pdo->prepare(
            'INSERT INTO submission_recipients
                (submission_id, recipient_type, ordinal, address_ciphertext, address_hash, created_at)
             VALUES
                (:submission_id, :recipient_type, :ordinal, :address_ciphertext, :address_hash, :created_at)'
        );
        foreach ($recipients as $recipient) {
            $recipientStatement->execute([
                ':submission_id' => $submissionId,
                ':recipient_type' => $recipient['type'],
                ':ordinal' => $recipient['ordinal'],
                ':address_ciphertext' => $recipient['ciphertext'],
                ':address_hash' => $recipient['address_hash'],
                ':created_at' => $submission['created_at'],
            ]);
        }

        return $submissionId;
    }

    /** @return array<string, mixed>|null */
    public function findByBrowserOperation(int $accountId, string $source, string $operationId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT public_id, state, deadline_at, message_id, snapshot_ciphertext, snapshot_hash
               FROM outgoing_submissions
              WHERE account_id = :account_id AND source = :source
                AND browser_operation_id = :operation_id
              LIMIT 1'
        );
        $statement->execute([
            ':account_id' => $accountId,
            ':source' => $source,
            ':operation_id' => $operationId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findMetadata(string $publicId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT public_id, account_reference, source, message_id, snapshot_hash,
                    state, attempts, deadline_at, last_error_category, sent_copy_state,
                    sending_started_at, terminal_at, created_at, updated_at
               FROM outgoing_submissions WHERE public_id = :public_id LIMIT 1'
        );
        $statement->execute([':public_id' => $publicId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findEncryptedDetail(string $publicId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT public_id, account_reference, source, message_id, snapshot_ciphertext,
                    state, attempts, deadline_at, last_error_category, sent_copy_state,
                    sending_started_at, terminal_at, created_at, updated_at
               FROM outgoing_submissions WHERE public_id = :public_id LIMIT 1'
        );
        $statement->execute([':public_id' => $publicId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function listRecipientOutcomes(string $publicId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.recipient_type, r.ordinal, o.attempt_number, o.outcome,
                    o.failure_category, o.recorded_at
               FROM outgoing_submissions s
               JOIN submission_recipients r ON r.submission_id = s.id
               JOIN submission_recipient_outcomes o ON o.submission_recipient_id = r.id
              WHERE s.public_id = :public_id
              ORDER BY o.attempt_number ASC, r.ordinal ASC'
        );
        $statement->execute([':public_id' => $publicId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMetadata(int $limit, int $offset, ?string $state = null): array
    {
        $this->assertState($state);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT s.public_id, s.account_reference, s.source, s.state, s.attempts,
                       s.deadline_at, s.last_error_category, s.sent_copy_state,
                       s.sending_started_at, s.terminal_at, s.created_at, s.updated_at,
                       COUNT(r.id) AS recipient_count
                  FROM outgoing_submissions s
                  LEFT JOIN submission_recipients r ON r.submission_id = s.id';
        if ($state !== null) {
            $sql .= ' WHERE s.state = :state';
        }
        $sql .= '
                 GROUP BY s.public_id, s.account_reference, s.source, s.state, s.attempts,
                          s.deadline_at, s.last_error_category, s.sent_copy_state,
                          s.sending_started_at, s.terminal_at, s.created_at, s.updated_at
                 ORDER BY s.created_at DESC, s.public_id ASC
                 LIMIT :limit OFFSET :offset';

        $statement = $this->pdo->prepare($sql);
        if ($state !== null) {
            $statement->bindValue(':state', $state, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['attempts'] = (int) $row['attempts'];
            $row['recipient_count'] = (int) $row['recipient_count'];
        }
        unset($row);
        return $rows;
    }

    public function countMetadata(?string $state = null): int
    {
        $this->assertState($state);
        $sql = 'SELECT COUNT(*) FROM outgoing_submissions';
        if ($state !== null) {
            $sql .= ' WHERE state = :state';
        }
        $statement = $this->pdo->prepare($sql);
        if ($state !== null) {
            $statement->bindValue(':state', $state, PDO::PARAM_STR);
        }
        $statement->execute();
        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function lockByPublicId(string $publicId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM outgoing_submissions WHERE public_id = :public_id LIMIT 1 FOR UPDATE'
        );
        $statement->execute([':public_id' => $publicId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function markSending(string $publicId, string $now): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE outgoing_submissions
                SET state = \'sending\', attempts = attempts + 1,
                    sending_started_at = :sending_started_at, last_error_category = NULL, updated_at = :updated_at
              WHERE public_id = :public_id AND state = \'queued\''
        );
        $statement->execute([
            ':sending_started_at' => $now,
            ':updated_at' => $now,
            ':public_id' => $publicId,
        ]);
        return $statement->rowCount() === 1;
    }

    public function markExpired(string $publicId, string $now): bool
    {
        return $this->terminalTransition($publicId, 'queued', 'expired', 'deadline', $now);
    }

    public function cancelQueued(string $publicId, string $now): bool
    {
        return $this->terminalTransition($publicId, 'queued', 'canceled', null, $now);
    }

    public function failQueued(string $publicId, string $category, string $now): bool
    {
        return $this->terminalTransition($publicId, 'queued', 'failed', $category, $now);
    }

    public function finishSending(string $publicId, string $state, ?string $category, string $now): bool
    {
        if (!in_array($state, ['accepted', 'partially_accepted', 'failed', 'uncertain'], true)) {
            throw new \InvalidArgumentException('Invalid terminal send state.');
        }
        return $this->terminalTransition($publicId, 'sending', $state, $category, $now);
    }

    public function requeueSending(string $publicId, string $category, string $now): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE outgoing_submissions
                SET state = \'queued\', last_error_category = :category, updated_at = :updated_at
              WHERE public_id = :public_id AND state = \'sending\' AND deadline_at > :now'
        );
        $statement->execute([
            ':category' => $category,
            ':updated_at' => $now,
            ':public_id' => $publicId,
            ':now' => $now,
        ]);
        return $statement->rowCount() === 1;
    }

    /**
     * @param list<array{ordinal:int,outcome:string,failure_category:?string}> $outcomes
     */
    public function recordRecipientOutcomes(int $submissionId, int $attempt, array $outcomes, string $now): void
    {
        $find = $this->pdo->prepare(
            'SELECT id FROM submission_recipients
              WHERE submission_id = :submission_id AND ordinal = :ordinal LIMIT 1'
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO submission_recipient_outcomes
                (submission_recipient_id, attempt_number, outcome, failure_category, recorded_at)
             VALUES (:recipient_id, :attempt, :outcome, :failure_category, :recorded_at)'
        );
        foreach ($outcomes as $outcome) {
            if (!in_array($outcome['outcome'], ['accepted', 'rejected', 'transient_failure', 'unknown'], true)) {
                throw new \InvalidArgumentException('Invalid recipient outcome.');
            }
            $find->execute([
                ':submission_id' => $submissionId,
                ':ordinal' => $outcome['ordinal'],
            ]);
            $recipientId = $find->fetchColumn();
            if ($recipientId === false) {
                throw new \InvalidArgumentException('Recipient outcome does not match the immutable snapshot.');
            }
            $insert->execute([
                ':recipient_id' => (int) $recipientId,
                ':attempt' => $attempt,
                ':outcome' => $outcome['outcome'],
                ':failure_category' => $outcome['failure_category'],
                ':recorded_at' => $now,
            ]);
        }
    }

    public function recipientCount(int $submissionId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM submission_recipients WHERE submission_id = :submission_id'
        );
        $statement->execute([':submission_id' => $submissionId]);
        return (int) $statement->fetchColumn();
    }

    private function assertState(?string $state): void
    {
        if ($state !== null && !in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException('Invalid submission state filter.');
        }
    }

    private function terminalTransition(
        string $publicId,
        string $expected,
        string $state,
        ?string $category,
        string $now
    ): bool {
        $statement = $this->pdo->prepare(
            'UPDATE outgoing_submissions
                SET state = :state, last_error_category = :category,
                    terminal_at = :terminal_at, updated_at = :updated_at
              WHERE public_id = :public_id AND state = :expected'
        );
        $statement->execute([
            ':state' => $state,
            ':category' => $category,
            ':terminal_at' => $now,
            ':updated_at' => $now,
            ':public_id' => $publicId,
            ':expected' => $expected,
        ]);
        return $statement->rowCount() === 1;
    }
}
