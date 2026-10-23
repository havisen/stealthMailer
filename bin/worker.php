#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config\Config;
use App\Config\ConfigException;
use App\Jobs\JobHandler;
use App\Jobs\WorkerRunner;
use App\Repositories\JobRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "worker.php may only run from the CLI.\n");
    exit(64);
}

$root = dirname(__DIR__);
$options = getopt('', ['queue::', 'budget::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php bin/worker.php [--queue=outbound|sync|maintenance|all] [--budget=1..55]\n");
    exit(0);
}

try {
    $dependencies = require $root . '/bootstrap/dependencies.php';

    /** @var Config $config */
    $config = $dependencies[Config::class];
    $queueConfiguration = $config->get('queue');
    if (!is_array($queueConfiguration) || !is_array($queueConfiguration['queues'] ?? null)) {
        throw new ConfigException('Worker queue configuration is unavailable.');
    }

    $queueOption = (string) ($options['queue'] ?? 'all');
    $queues = $queueOption === 'all' ? array_keys($queueConfiguration['queues']) : [$queueOption];
    if ($queues === [] || array_diff($queues, JobRepository::QUEUES) !== []) {
        fwrite(STDERR, "Unknown queue. Use outbound, sync, maintenance, or all.\n");
        exit(64);
    }
    $budget = (int) ($options['budget'] ?? $queueConfiguration['run_budget_seconds'] ?? 40);
    if ($budget < 1 || $budget > 55) {
        fwrite(STDERR, "Worker budget must be between 1 and 55 seconds.\n");
        exit(64);
    }

    /** @var JobRepository $repository */
    $repository = $dependencies[JobRepository::class];
    $runner = new WorkerRunner(
        $repository,
        $config->int('queue.lease_seconds'),
        $config->int('queue.minimum_start_seconds'),
        $config->int('queue.recovery_limit')
    );

    $handlers = require $root . '/bootstrap/worker.php';
    if (!is_array($handlers)) {
        throw new RuntimeException('bootstrap/worker.php must return a job-type-to-handler map.');
    }
    foreach ($handlers as $type => $handler) {
        if (!is_string($type) || !$handler instanceof JobHandler) {
            throw new RuntimeException('Invalid worker handler registration.');
        }
        $runner->register($type, $handler);
    }

    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static fn () => $runner->requestStop());
        pcntl_signal(SIGINT, static fn () => $runner->requestStop());
    }

    $result = $runner->run($queues, $budget);
    fwrite(STDOUT, sprintf(
        "worker finished: completed=%d failed=%d reason=%s\n",
        $result['completed'],
        $result['failed'],
        $result['reason']
    ));
    exit($result['failed'] > 0 ? 1 : 0);
} catch (ConfigException $exception) {
    fwrite(STDERR, 'Worker configuration is invalid; worker did not run. ' . $exception->getMessage() . "\n");
    exit(78);
} catch (PDOException $exception) {
    fwrite(STDERR, "Database unavailable; worker did not run.\n");
    exit(2);
} catch (Throwable $throwable) {
    fwrite(STDERR, "Worker failed safely; no secret values were printed.\n");
    exit(1);
}
