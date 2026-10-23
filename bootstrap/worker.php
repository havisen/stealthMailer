<?php

declare(strict_types=1);

use App\Jobs\JobHandler;
use App\Workers\SendWorker;

if (!isset($dependencies) || !is_array($dependencies)) {
    $dependencies = require __DIR__ . '/dependencies.php';
}

/** @var array<string, JobHandler> $handlers */
$handlers = [
    'smtp.send' => $dependencies[SendWorker::class],
];

return $handlers;
