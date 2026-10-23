<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Repositories\JobRepository;

final class JobExecutionContext
{
    /** @param callable():bool $stopRequested */
    public function __construct(
        private JobRepository $jobs,
        private string $jobId,
        private string $workerId,
        private int $leaseSeconds,
        private float $deadline,
        private $stopRequested
    ) {
    }

    public function remainingSeconds(): float
    {
        return max(0.0, $this->deadline - microtime(true));
    }

    public function shouldStop(): bool
    {
        return ($this->stopRequested)() || $this->remainingSeconds() <= 0.0;
    }

    /** @param array<string, mixed> $checkpoint */
    public function checkpoint(array $checkpoint): void
    {
        if (!$this->jobs->heartbeat(
            $this->jobId,
            $this->workerId,
            min($this->leaseSeconds, max(5, (int) ceil($this->remainingSeconds()) + 5)),
            $checkpoint
        )) {
            throw new \RuntimeException('Job lease was lost while checkpointing.');
        }
        $this->jobs->heartbeatWorker($this->workerId);
    }

    public function networkTimeoutSeconds(int $configuredMaximum): int
    {
        $usable = $this->remainingSeconds() - 1.0;
        if ($usable < 0.5) {
            throw new \RuntimeException('Worker budget is exhausted before network operation.');
        }
        return max(1, min($configuredMaximum, (int) floor($usable)));
    }
}
