<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;

class JobRepository
{
    public const QUEUES = ['outbound', 'sync', 'maintenance'];
    public const FAILURE_CATEGORIES = [
        'account_disabled',
        'authentication',
        'configuration',
        'deadline',
        'internal',
        'network',
        'permanent',
        'protocol',
        'storage',
        'tls',
        'quota',
        'sender_auth',
        'timeout',
        'uncertain_outcome',
    ];

    public function __construct(protected PDO $pdo)
    {
    }

    /**
     * Enqueue using the current connection. If the caller has an open
     * transaction, the job participates in it and is not visible until commit.
     *
     * @param array<string, mixed> $job
     */
    public function enqueue(array $job): string
    {
        $queue = (string) ($job['queue_name'] ?? 'maintenance');
        if (!in_array($queue, self::QUEUES, true)) {
            throw new \InvalidArgumentException('Unknown job queue.');
        }

        $type = $this->boundedIdentifier((string) ($job['type'] ?? ''), 80, 'job type');
        $targetType = $this->boundedIdentifier((string) ($job['target_type'] ?? ''), 80, 'target type');
        $targetReference = trim((string) ($job['target_reference'] ?? ''));
        if ($targetReference === '' || strlen($targetReference) > 191) {
            throw new \InvalidArgumentException('Invalid job target reference.');
        }

        $publicId = 'job_' . bin2hex(random_bytes(16));
        $now = self::now();
        $scheduledAt = $job['scheduled_at'] ?? $now;
        $payload = isset($job['payload'])
            ? json_encode($job['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            : null;
        $dedupeKey = isset($job['dedupe_key']) ? trim((string) $job['dedupe_key']) : null;
        if ($dedupeKey === '') {
            $dedupeKey = null;
        }
        if ($dedupeKey !== null && strlen($dedupeKey) > 191) {
            throw new \InvalidArgumentException('Job dedupe key is too long.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO jobs
                (public_id, type, queue_name, target_type, target_reference,
                 account_reference, dedupe_key, payload_json, status, priority,
                 scheduled_at, attempts, max_attempts, created_at, updated_at)
             VALUES
                (:public_id, :type, :queue_name, :target_type, :target_reference,
                 :account_reference, :dedupe_key, :payload_json, \'queued\', :priority,
                 :scheduled_at, 0, :max_attempts, :created_at, :updated_at)'
        );

        try {
            $statement->execute([
                ':public_id' => $publicId,
                ':type' => $type,
                ':queue_name' => $queue,
                ':target_type' => $targetType,
                ':target_reference' => $targetReference,
                ':account_reference' => $job['account_reference'] ?? null,
                ':dedupe_key' => $dedupeKey,
                ':payload_json' => $payload,
                ':priority' => max(0, min(65535, (int) ($job['priority'] ?? 100))),
                ':scheduled_at' => (string) $scheduledAt,
                ':max_attempts' => max(1, min(65535, (int) ($job['max_attempts'] ?? 1))),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if ($dedupeKey === null || (string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            $existing = $this->pdo->prepare(
                'SELECT public_id FROM jobs WHERE type = :type AND dedupe_key = :dedupe_key LIMIT 1'
            );
            $existing->execute([':type' => $type, ':dedupe_key' => $dedupeKey]);
            $found = $existing->fetchColumn();
            if (!is_string($found)) {
                throw $exception;
            }

            return $found;
        }

        return $publicId;
    }

    /** @return array<string, mixed>|null */
    public function claim(array $queues, string $workerId, int $leaseSeconds): ?array
    {
        $queues = array_values(array_intersect(self::QUEUES, $queues));
        if ($queues === []) {
            throw new \InvalidArgumentException('At least one valid queue is required.');
        }

        $workerId = $this->workerId($workerId);
        $leaseSeconds = max(5, min(300, $leaseSeconds));
        $now = self::now();
        $expires = self::afterSeconds($leaseSeconds);
        $placeholders = implode(', ', array_fill(0, count($queues), '?'));

        $this->pdo->beginTransaction();
        try {
            $sql = 'SELECT j.* FROM jobs j
                     WHERE j.queue_name IN (' . $placeholders . ')
                       AND j.status = \'queued\'
                       AND j.scheduled_at <= ?
                       AND j.attempts < j.max_attempts
                       AND (j.account_reference IS NULL OR NOT EXISTS (
                           SELECT 1 FROM job_account_leases al
                            WHERE al.account_reference = j.account_reference
                              AND al.lease_expires_at > ?
                       ))
                     ORDER BY j.priority ASC, j.scheduled_at ASC, j.id ASC
                     LIMIT 1 FOR UPDATE SKIP LOCKED';
            $statement = $this->pdo->prepare($sql);
            $statement->execute([...$queues, $now, $now]);
            $job = $statement->fetch(PDO::FETCH_ASSOC);
            if ($job === false) {
                $this->pdo->commit();
                return null;
            }

            if ($job['account_reference'] !== null) {
                $lease = $this->pdo->prepare(
                    'INSERT INTO job_account_leases
                        (account_reference, job_id, worker_id, lease_expires_at, updated_at)
                     VALUES (:account_reference, :job_id, :worker_id, :lease_expires_at, :updated_at)
                     ON DUPLICATE KEY UPDATE
                        job_id = IF(lease_expires_at <= VALUES(updated_at), VALUES(job_id), job_id),
                        worker_id = IF(lease_expires_at <= VALUES(updated_at), VALUES(worker_id), worker_id),
                        updated_at = IF(lease_expires_at <= VALUES(updated_at), VALUES(updated_at), updated_at),
                        lease_expires_at = IF(lease_expires_at <= VALUES(updated_at), VALUES(lease_expires_at), lease_expires_at)'
                );
                $lease->execute([
                    ':account_reference' => $job['account_reference'],
                    ':job_id' => $job['id'],
                    ':worker_id' => $workerId,
                    ':lease_expires_at' => $expires,
                    ':updated_at' => $now,
                ]);

                $owner = $this->pdo->prepare(
                    'SELECT job_id, worker_id FROM job_account_leases
                     WHERE account_reference = :account_reference FOR UPDATE'
                );
                $owner->execute([':account_reference' => $job['account_reference']]);
                $accountLease = $owner->fetch(PDO::FETCH_ASSOC);
                if ($accountLease === false
                    || (int) $accountLease['job_id'] !== (int) $job['id']
                    || $accountLease['worker_id'] !== $workerId) {
                    $this->pdo->rollBack();
                    return null;
                }
            }

            $update = $this->pdo->prepare(
                'UPDATE jobs
                    SET status = \'leased\', attempts = attempts + 1,
                        lease_owner = :worker_id, lease_expires_at = :lease_expires_at,
                        heartbeat_at = :heartbeat_at, updated_at = :updated_at
                  WHERE id = :id AND status = \'queued\''
            );
            $update->execute([
                ':worker_id' => $workerId,
                ':lease_expires_at' => $expires,
                ':heartbeat_at' => $now,
                ':updated_at' => $now,
                ':id' => $job['id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Job claim lost its state transition.');
            }

            $this->pdo->commit();
            $job['status'] = 'leased';
            $job['attempts'] = (int) $job['attempts'] + 1;
            $job['lease_owner'] = $workerId;
            $job['lease_expires_at'] = $expires;
            return $this->decodeJsonFields($job);
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    /** @return array<string, mixed>|null */
    public function claimExpiredForRecovery(array $queues, string $workerId, int $leaseSeconds): ?array
    {
        $queues = array_values(array_intersect(self::QUEUES, $queues));
        if ($queues === []) {
            throw new \InvalidArgumentException('At least one valid queue is required.');
        }
        $workerId = $this->workerId($workerId);
        $now = self::now();
        $expires = self::afterSeconds(max(5, min(300, $leaseSeconds)));
        $placeholders = implode(', ', array_fill(0, count($queues), '?'));
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT * FROM jobs
                  WHERE queue_name IN (' . $placeholders . ')
                    AND status = \'leased\' AND lease_expires_at <= ?
                  ORDER BY lease_expires_at ASC, id ASC
                  LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $statement->execute([...$queues, $now]);
            $job = $statement->fetch(PDO::FETCH_ASSOC);
            if ($job === false) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                'UPDATE jobs SET lease_owner = :worker_id, lease_expires_at = :expires,
                    heartbeat_at = :heartbeat_at, updated_at = :updated_at WHERE id = :id'
            );
            $update->execute([
                ':worker_id' => $workerId,
                ':expires' => $expires,
                ':heartbeat_at' => $now,
                ':updated_at' => $now,
                ':id' => $job['id'],
            ]);

            if ($job['account_reference'] !== null) {
                $lease = $this->pdo->prepare(
                    'UPDATE job_account_leases SET worker_id = :worker_id,
                        lease_expires_at = :expires, updated_at = :now
                      WHERE account_reference = :account_reference AND job_id = :job_id'
                );
                $lease->execute([
                    ':worker_id' => $workerId,
                    ':expires' => $expires,
                    ':now' => $now,
                    ':account_reference' => $job['account_reference'],
                    ':job_id' => $job['id'],
                ]);
            }

            $this->pdo->commit();
            $job['lease_owner'] = $workerId;
            $job['lease_expires_at'] = $expires;
            return $this->decodeJsonFields($job);
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    /** @param array<string, mixed>|null $checkpoint */
    public function heartbeat(string $publicId, string $workerId, int $leaseSeconds, ?array $checkpoint = null): bool
    {
        $now = self::now();
        $expires = self::afterSeconds(max(5, min(300, $leaseSeconds)));
        $checkpointJson = $checkpoint === null
            ? null
            : json_encode($checkpoint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $statement = $this->pdo->prepare(
            'UPDATE jobs SET heartbeat_at = :heartbeat_at, lease_expires_at = :expires,
                    checkpoint_json = COALESCE(:checkpoint_json, checkpoint_json), updated_at = :updated_at
              WHERE public_id = :public_id AND status = \'leased\' AND lease_owner = :worker_id'
        );
        $statement->execute([
            ':heartbeat_at' => $now,
            ':expires' => $expires,
            ':checkpoint_json' => $checkpointJson,
            ':updated_at' => $now,
            ':public_id' => $publicId,
            ':worker_id' => $this->workerId($workerId),
        ]);
        if ($statement->rowCount() !== 1) {
            return false;
        }

        $lease = $this->pdo->prepare(
            'UPDATE job_account_leases al JOIN jobs j ON j.id = al.job_id
                SET al.lease_expires_at = :expires, al.updated_at = :now
              WHERE j.public_id = :public_id AND al.worker_id = :worker_id'
        );
        $lease->execute([
            ':expires' => $expires,
            ':now' => $now,
            ':public_id' => $publicId,
            ':worker_id' => $workerId,
        ]);
        return true;
    }

    public function complete(string $publicId, string $workerId): bool
    {
        return $this->finish($publicId, $workerId, 'completed', null, null);
    }

    public function fail(string $publicId, string $workerId, string $category): bool
    {
        return $this->finish($publicId, $workerId, 'failed', $this->failureCategory($category), null);
    }

    public function retry(string $publicId, string $workerId, string $category, string $scheduledAt): bool
    {
        return $this->finish(
            $publicId,
            $workerId,
            'queued',
            $this->failureCategory($category),
            $scheduledAt
        );
    }

    public function cancelQueuedForTarget(string $targetType, string $targetReference): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE jobs SET status = \'canceled\', completed_at = :completed_at, updated_at = :updated_at
              WHERE target_type = :target_type AND target_reference = :target_reference
                AND status = \'queued\''
        );
        $statement->execute([
            ':completed_at' => self::now(),
            ':updated_at' => self::now(),
            ':target_type' => $targetType,
            ':target_reference' => $targetReference,
        ]);
        return $statement->rowCount();
    }

    public function startWorker(string $workerId, string $queueName): void
    {
        $now = self::now();
        $statement = $this->pdo->prepare(
            'INSERT INTO worker_runs
                (worker_id, queue_name, started_at, heartbeat_at, jobs_completed, jobs_failed)
             VALUES (:worker_id, :queue_name, :started_at, :heartbeat_at, 0, 0)'
        );
        $statement->execute([
            ':worker_id' => $this->workerId($workerId),
            ':queue_name' => $queueName,
            ':started_at' => $now,
            ':heartbeat_at' => $now,
        ]);
    }

    public function heartbeatWorker(string $workerId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE worker_runs SET heartbeat_at = :now WHERE worker_id = :worker_id'
        );
        $statement->execute([':now' => self::now(), ':worker_id' => $this->workerId($workerId)]);
    }

    public function finishWorker(
        string $workerId,
        string $reason,
        int $completed,
        int $failed
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE worker_runs SET heartbeat_at = :heartbeat_at, finished_at = :finished_at,
                    exit_reason = :reason, jobs_completed = :completed, jobs_failed = :failed
              WHERE worker_id = :worker_id'
        );
        $statement->execute([
            ':heartbeat_at' => self::now(),
            ':finished_at' => self::now(),
            ':reason' => substr($reason, 0, 64),
            ':completed' => max(0, $completed),
            ':failed' => max(0, $failed),
            ':worker_id' => $this->workerId($workerId),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findByPublicId(string $publicId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM jobs WHERE public_id = :public_id LIMIT 1');
        $statement->execute([':public_id' => $publicId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->decodeJsonFields($row);
    }

    public function countRecentAccountFailures(string $accountReference, string $category, string $since): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM jobs
              WHERE account_reference = :account_reference AND status = \'failed\'
                AND failure_category = :category AND updated_at >= :since'
        );
        $statement->execute([
            ':account_reference' => $accountReference,
            ':category' => $this->failureCategory($category),
            ':since' => $since,
        ]);
        return (int) $statement->fetchColumn();
    }

    /**
     * @return array{
     *     worker: array{last_heartbeat_at:?string,last_finished_at:?string,last_exit_reason:?string,stale:bool},
     *     queues: array<string, array{queued:int,leased:int,oldest_queued_at:?string}>,
     *     expired_leases: int,
     *     failed_jobs_24h: int
     * }
     */
    public function diagnostics(): array
    {
        $worker = [
            'last_heartbeat_at' => null,
            'last_finished_at' => null,
            'last_exit_reason' => null,
            'stale' => true,
        ];
        $workerStatement = $this->pdo->prepare(
            'SELECT heartbeat_at, finished_at, exit_reason,
                    (heartbeat_at < (UTC_TIMESTAMP(6) - INTERVAL 5 MINUTE)) AS stale
               FROM worker_runs
              ORDER BY heartbeat_at DESC
              LIMIT 1'
        );
        $workerStatement->execute();
        $workerRow = $workerStatement->fetch(PDO::FETCH_ASSOC);
        if ($workerRow !== false) {
            $worker['last_heartbeat_at'] = $workerRow['heartbeat_at'] === null
                ? null
                : (string) $workerRow['heartbeat_at'];
            $worker['last_finished_at'] = $workerRow['finished_at'] === null
                ? null
                : (string) $workerRow['finished_at'];
            $worker['last_exit_reason'] = $workerRow['exit_reason'] === null
                ? null
                : (string) $workerRow['exit_reason'];
            $worker['stale'] = $worker['last_heartbeat_at'] === null || (int) $workerRow['stale'] === 1;
        }

        $queues = [];
        foreach (self::QUEUES as $queueName) {
            $queues[$queueName] = ['queued' => 0, 'leased' => 0, 'oldest_queued_at' => null];
        }
        $queueStatement = $this->pdo->prepare(
            "SELECT queue_name,
                    SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued,
                    SUM(CASE WHEN status = 'leased' THEN 1 ELSE 0 END) AS leased,
                    MIN(CASE WHEN status = 'queued' THEN scheduled_at END) AS oldest_queued_at
               FROM jobs
              WHERE queue_name IN ('outbound', 'sync', 'maintenance')
                AND status IN ('queued', 'leased')
              GROUP BY queue_name"
        );
        $queueStatement->execute();
        foreach ($queueStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $queueRow) {
            $queueName = (string) $queueRow['queue_name'];
            if (!array_key_exists($queueName, $queues)) {
                continue;
            }
            $queues[$queueName] = [
                'queued' => (int) $queueRow['queued'],
                'leased' => (int) $queueRow['leased'],
                'oldest_queued_at' => $queueRow['oldest_queued_at'] === null
                    ? null
                    : (string) $queueRow['oldest_queued_at'],
            ];
        }

        $expiredStatement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM jobs
              WHERE status = 'leased' AND lease_expires_at IS NOT NULL
                AND lease_expires_at <= UTC_TIMESTAMP(6)"
        );
        $expiredStatement->execute();

        $failedStatement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM jobs
              WHERE status = 'failed'
                AND updated_at >= (UTC_TIMESTAMP(6) - INTERVAL 24 HOUR)"
        );
        $failedStatement->execute();

        return [
            'worker' => $worker,
            'queues' => $queues,
            'expired_leases' => (int) $expiredStatement->fetchColumn(),
            'failed_jobs_24h' => (int) $failedStatement->fetchColumn(),
        ];
    }

    private function finish(
        string $publicId,
        string $workerId,
        string $status,
        ?string $category,
        ?string $scheduledAt
    ): bool {
        $now = self::now();
        $completedAt = $status === 'queued' ? null : $now;
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'UPDATE jobs
                    SET status = :status, failure_category = :category,
                        scheduled_at = COALESCE(:scheduled_at, scheduled_at),
                        lease_owner = NULL, lease_expires_at = NULL,
                        completed_at = :completed_at, updated_at = :now
                  WHERE public_id = :public_id AND status = \'leased\' AND lease_owner = :worker_id'
            );
            $statement->execute([
                ':status' => $status,
                ':category' => $category,
                ':scheduled_at' => $scheduledAt,
                ':completed_at' => $completedAt,
                ':now' => $now,
                ':public_id' => $publicId,
                ':worker_id' => $this->workerId($workerId),
            ]);
            if ($statement->rowCount() === 1) {
                $release = $this->pdo->prepare(
                    'DELETE al FROM job_account_leases al
                     JOIN jobs j ON j.id = al.job_id
                     WHERE j.public_id = :public_id AND al.worker_id = :worker_id'
                );
                $release->execute([':public_id' => $publicId, ':worker_id' => $workerId]);
            }
            $this->pdo->commit();
            return $statement->rowCount() === 1;
        } catch (\Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    private function failureCategory(string $category): string
    {
        return in_array($category, self::FAILURE_CATEGORIES, true) ? $category : 'internal';
    }

    private function workerId(string $workerId): string
    {
        if ($workerId === '' || strlen($workerId) > 128 || preg_match('/^[A-Za-z0-9_.:-]+$/', $workerId) !== 1) {
            throw new \InvalidArgumentException('Invalid worker ID.');
        }
        return $workerId;
    }

    private function boundedIdentifier(string $value, int $max, string $label): string
    {
        if ($value === '' || strlen($value) > $max || preg_match('/^[a-z][a-z0-9_.-]*$/', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid ' . $label . '.');
        }
        return $value;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function decodeJsonFields(array $row): array
    {
        foreach (['payload_json' => 'payload', 'checkpoint_json' => 'checkpoint'] as $column => $key) {
            $row[$key] = $row[$column] === null
                ? null
                : json_decode((string) $row[$column], true, 32, JSON_THROW_ON_ERROR);
            unset($row[$column]);
        }
        return $row;
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private static function afterSeconds(int $seconds): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $seconds . ' seconds')
            ->format('Y-m-d H:i:s.u');
    }
}
