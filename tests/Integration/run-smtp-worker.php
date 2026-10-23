<?php

declare(strict_types=1);

use App\Jobs\JobExecutionContext;
use App\Jobs\JobResult;
use App\Jobs\WorkerRunner;
use App\Mail\PhpMailerSmtpTransport;
use App\Repositories\AccountRepository;
use App\Repositories\JobRepository;
use App\Repositories\SubmissionRepository;
use App\Security\CredentialCipher;
use App\Security\EndpointPolicy;
use App\Security\QueuedContentCipher;
use App\Services\AccountService;
use App\Services\SubmissionService;
use App\Submissions\SubmissionDeliveryStore;
use App\Submissions\SubmissionRequest;
use App\Submissions\SubmissionValidator;
use App\Workers\SendWorker;

/**
 * Controlled-TLS SMTP worker acceptance.
 *
 * Requires tests/Fixtures/smtp/worker-server.js listening on
 * MAILPANEL_SMTP_WORKER_BASE_PORT, seven ports from that base: accepted,
 * rcpt_reject, rcpt_transient, partial, data_reject, data_transient,
 * data_disconnect.
 */

$root = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "SKIP: Composer dependencies are not installed.\n");
    exit(77);
}
require $autoload;

$dsn = getenv('MAILPANEL_TEST_DB_DSN');
$user = getenv('MAILPANEL_TEST_DB_USER');
$password = getenv('MAILPANEL_TEST_DB_PASSWORD');
$allowReset = getenv('MAILPANEL_TEST_ALLOW_RESET');
$basePort = (int) (getenv('MAILPANEL_SMTP_WORKER_BASE_PORT') ?: 4465);
$caFile = getenv('MAILPANEL_SMTP_TEST_CA_FILE');
$smtpUser = getenv('MAILPANEL_SMTP_TEST_USER');
$smtpPassword = getenv('MAILPANEL_SMTP_TEST_PASSWORD');

if (
    !is_string($dsn) || $dsn === '' || !is_string($user) || $allowReset !== '1'
    || !is_string($caFile) || !is_file($caFile)
    || !is_string($smtpUser) || $smtpUser === ''
    || !is_string($smtpPassword) || $smtpPassword === ''
) {
    fwrite(STDERR, "SKIP: disposable DB, SMTP worker CA, and test credentials are required.\n");
    exit(77);
}

$probe = @stream_socket_client('tcp://127.0.0.1:' . $basePort, $errno, $errstr, 2);
if (!is_resource($probe)) {
    fwrite(STDERR, "SKIP: controlled SMTP worker server is not listening on port {$basePort}.\n");
    exit(77);
}
fclose($probe);

$pdo = new PDO($dsn, $user, is_string($password) ? $password : '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ([
    'worker_runs', 'job_account_leases', 'jobs', 'submission_recipient_outcomes',
    'submission_recipients', 'outgoing_submissions', 'mail_accounts',
] as $table) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
foreach (['002_create_accounts.php', '003_create_submissions.php', '007_create_jobs.php'] as $migration) {
    (require $root . '/database/migrations/' . $migration)($pdo);
}

$accounts = new AccountRepository($pdo);
$submissions = new SubmissionRepository($pdo);
$jobs = new JobRepository($pdo);
$credentialKey = base64_encode(random_bytes(CredentialCipher::KEY_BYTES));
$credentialCipher = new CredentialCipher(['test-key' => $credentialKey], 'test-key');
$contentKey = base64_encode(random_bytes(CredentialCipher::KEY_BYTES));
$contentCipher = new QueuedContentCipher(['test-key' => $contentKey], 'test-key');
$accountService = new AccountService($accounts, $credentialCipher, new EndpointPolicy([]), $jobs);
$submissionService = new SubmissionService(
    $pdo,
    $accounts,
    $submissions,
    $jobs,
    $contentCipher,
    new SubmissionValidator(300, 86400, 'mail.example.test'),
);
$transport = new PhpMailerSmtpTransport($caFile);
$sendWorker = new SendWorker($submissionService, $accountService, $transport);

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$createAccount = static function (int $port, string $secret, string $label) use (
    $accounts,
    $credentialCipher,
    $smtpUser,
    $smtpPassword
): string {
    if ($secret === '') {
        $secret = $smtpPassword;
    }
    $publicId = 'acct_' . bin2hex(random_bytes(16));
    $context = CredentialCipher::contextFor($publicId, 'password');
    $accounts->insert([
        'public_id' => $publicId,
        'label' => $label,
        'smtp_host' => '127.0.0.1',
        'smtp_port' => $port,
        'smtp_tls' => 'implicit',
        'smtp_username' => $smtpUser,
        'password_enc' => $credentialCipher->encrypt($secret, $context),
        'password_aad' => $context,
        'from_email' => 'sender@example.test',
        'from_name' => 'Synthetic Sender',
        'enabled' => true,
    ]);
    return $publicId;
};

$createSubmission = static function (string $accountId, array $to, string $operation) use ($submissionService): string {
    return $submissionService->create(new SubmissionRequest(
        $accountId,
        'panel',
        $to,
        [],
        [],
        'Controlled SMTP worker test',
        'Synthetic fixture body only.',
        browserOperationId: $operation,
    ))->submissionId;
};

$runOutbound = static function (SendWorker $worker) use ($jobs): array {
    $runner = new WorkerRunner($jobs, 30, 1, 25);
    $runner->register('smtp.send', $worker);
    return $runner->run(['outbound'], 10);
};

$state = static function (string $submissionId) use ($pdo): string {
    return (string) $pdo->query(
        'SELECT state FROM outgoing_submissions WHERE public_id = ' . $pdo->quote($submissionId)
    )->fetchColumn();
};
$job = static function (string $submissionId) use ($pdo): array {
    $row = $pdo->query(
        'SELECT status, failure_category, scheduled_at, attempts FROM jobs WHERE target_reference = ' . $pdo->quote($submissionId)
    )->fetch();
    return is_array($row) ? $row : [];
};
$outcomes = static function (string $submissionId) use ($pdo): array {
    return $pdo->query(
        'SELECT r.ordinal, o.outcome, o.failure_category
           FROM submission_recipient_outcomes o
           JOIN submission_recipients r ON r.id = o.submission_recipient_id
          WHERE r.submission_id = (SELECT id FROM outgoing_submissions WHERE public_id = ' . $pdo->quote($submissionId) . ')
          ORDER BY r.ordinal, o.attempt_number'
    )->fetchAll();
};
$now = static fn (): string => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

$test = static function (string $name, callable $callback) use (&$assertions): void {
    try {
        $callback();
        fwrite(STDOUT, "ok - {$name}\n");
    } catch (Throwable $throwable) {
        fwrite(STDERR, "FAIL - {$name}: {$throwable->getMessage()}\n");
        throw $throwable;
    }
};

try {
    $test('accepted DATA is terminal', function () use ($createAccount, $createSubmission, $runOutbound, $state, $job, $outcomes, $assert, $basePort): void {
        $accountId = $createAccount($basePort, '', 'Controlled accepted');
        $submissionId = $createSubmission($accountId, ['one@example.com'], 'worker_accept_0001');
        $runOutbound($GLOBALS['sendWorker']);
        $assert($state($submissionId) === 'accepted', 'Accepted submission state was not accepted.');
        $assert(($job($submissionId)['status'] ?? '') === 'completed', 'Accepted job was not completed.');
        $assert(($outcomes($submissionId)[0]['outcome'] ?? '') === 'accepted', 'Accepted recipient outcome missing.');
    });

    $test('permanent RCPT rejection is terminal', function () use ($createAccount, $createSubmission, $runOutbound, $state, $job, $outcomes, $assert, $basePort): void {
        $accountId = $createAccount($basePort + 1, '', 'Controlled reject');
        $submissionId = $createSubmission($accountId, ['one@example.com'], 'worker_reject_0001');
        $runOutbound($GLOBALS['sendWorker']);
        $assert($state($submissionId) === 'failed', 'Rejected submission was not failed.');
        $assert(($job($submissionId)['status'] ?? '') === 'failed', 'Rejected job was not failed.');
        $assert(($job($submissionId)['failure_category'] ?? '') === 'permanent', 'Rejection category was not permanent.');
        $assert(($outcomes($submissionId)[0]['outcome'] ?? '') === 'rejected', 'Rejected recipient outcome missing.');
    });

    $test('temporary RCPT rejection requeues once', function () use ($createAccount, $createSubmission, $runOutbound, $state, $job, $assert, $basePort, $now): void {
        $accountId = $createAccount($basePort + 2, '', 'Controlled transient');
        $submissionId = $createSubmission($accountId, ['one@example.com'], 'worker_transient_0001');
        $runOutbound($GLOBALS['sendWorker']);
        $row = $job($submissionId);
        $assert($state($submissionId) === 'queued', 'Transient submission was not requeued.');
        $assert(($row['status'] ?? '') === 'queued', 'Transient job was not requeued.');
        $assert(($row['scheduled_at'] ?? '') > $now(), 'Transient retry was not scheduled in the future.');
    });

    $test('mixed recipient acceptance is terminal and never retried', function () use ($createAccount, $createSubmission, $runOutbound, $state, $job, $outcomes, $assert, $basePort): void {
        $accountId = $createAccount($basePort + 3, '', 'Controlled partial');
        $submissionId = $createSubmission($accountId, ['one@example.com', 'two@example.com'], 'worker_partial_0001');
        $runOutbound($GLOBALS['sendWorker']);
        $assert($state($submissionId) === 'partially_accepted', 'Mixed acceptance was not partially_accepted.');
        $assert(($job($submissionId)['status'] ?? '') === 'completed', 'Mixed acceptance job was not completed.');
        $accepted = array_values(array_filter($outcomes($submissionId), static fn (array $row): bool => $row['outcome'] === 'accepted'));
        $rejected = array_values(array_filter($outcomes($submissionId), static fn (array $row): bool => $row['outcome'] === 'rejected'));
        $assert(count($accepted) === 1 && count($rejected) === 1, 'Mixed recipient outcomes were not split.');
    });

    $test('permanent DATA rejection is terminal', function () use ($createAccount, $createSubmission, $runOutbound, $state, $job, $assert, $basePort): void {
        $accountId = $createAccount($basePort + 4, '', 'Controlled data reject');
        $submissionId = $createSubmission($accountId, ['one@example.com'], 'worker_data_reject_0001');
        $runOutbound($GLOBALS['sendWorker']);
        $assert($state($submissionId) === 'failed', 'DATA rejection was not failed.');
        $assert(($job($submissionId)['failure_category'] ?? '') === 'permanent', 'DATA rejection category was not permanent.');
    });

    $test('temporary DATA rejection requeues once', function () use ($createAccount, $createSubmission, $runOutbound, $state, $job, $assert, $basePort, $now): void {
        $accountId = $createAccount($basePort + 5, '', 'Controlled data transient');
        $submissionId = $createSubmission($accountId, ['one@example.com'], 'worker_data_transient_0001');
        $runOutbound($GLOBALS['sendWorker']);
        $row = $job($submissionId);
        $assert($state($submissionId) === 'queued', 'Temporary DATA rejection was not requeued.');
        $assert(($row['status'] ?? '') === 'queued', 'Temporary DATA job was not requeued.');
        $assert(($row['scheduled_at'] ?? '') > $now(), 'Temporary DATA retry was not scheduled in the future.');
    });

    $test('final-DATA disconnect becomes uncertain and never retries', function () use ($createAccount, $createSubmission, $runOutbound, $state, $job, $assert, $basePort): void {
        $accountId = $createAccount($basePort + 6, '', 'Controlled data disconnect');
        $submissionId = $createSubmission($accountId, ['one@example.com'], 'worker_disconnect_0001');
        $runOutbound($GLOBALS['sendWorker']);
        $assert($state($submissionId) === 'uncertain', 'Final-DATA disconnect was not uncertain.');
        $assert(($job($submissionId)['failure_category'] ?? '') === 'uncertain_outcome', 'Uncertain category missing.');
        $assert(($job($submissionId)['status'] ?? '') === 'failed', 'Uncertain job was not terminal.');
    });

    $test('interrupted lease recovery marks a sending submission uncertain', function () use ($createAccount, $createSubmission, $runOutbound, $state, $job, $assert, $pdo, $jobs, $submissionService, $basePort): void {
        $accountId = $createAccount($basePort, '', 'Controlled interrupted');
        $submissionId = $createSubmission($accountId, ['one@example.com'], 'worker_interrupted_0001');
        $submissionService->claimForSending($submissionId);
        $claimed = $jobs->claim(['outbound'], 'worker:interrupted', 30);
        $assert(is_array($claimed) && ($claimed['target_reference'] ?? null) === $submissionId, 'Interrupted job could not be claimed.');
        $pdo->exec(
            'UPDATE jobs SET lease_expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE target_reference = ' . $pdo->quote($submissionId)
        );
        $runOutbound($GLOBALS['sendWorker']);
        $assert($state($submissionId) === 'uncertain', 'Interrupted sending submission was not uncertain.');
        $assert(($job($submissionId)['status'] ?? '') === 'failed', 'Interrupted job did not become terminal.');
    });

    $test('persistence failure after SMTP acceptance recovers as uncertain', function () use ($createAccount, $createSubmission, $runOutbound, $state, $job, $assert, $pdo, $jobs, $sendWorker, $submissionService, $accountService, $transport, $basePort): void {
        $accountId = $createAccount($basePort, '', 'Controlled persistence failure');
        $submissionId = $createSubmission($accountId, ['one@example.com'], 'worker_persistence_0001');
        $failingStore = new class($submissionService) implements SubmissionDeliveryStore {
            public function __construct(private SubmissionService $inner) {}
            public function claimForSending(string $submissionId, ?DateTimeImmutable $startedAt = null): ?array { return $this->inner->claimForSending($submissionId, $startedAt); }
            public function finishAttempt(string $submissionId, string $state, ?string $failureCategory, array $outcomes): void { throw new RuntimeException('controlled persistence failure'); }
            public function recordAndRequeueDefinitelyNotAccepted(string $submissionId, string $failureCategory, array $outcomes): bool { return $this->inner->recordAndRequeueDefinitelyNotAccepted($submissionId, $failureCategory, $outcomes); }
            public function failQueuedWithoutAttempt(string $submissionId, string $failureCategory): bool { return $this->inner->failQueuedWithoutAttempt($submissionId, $failureCategory); }
            public function recoverExpiredSend(string $submissionId): JobResult { return $this->inner->recoverExpiredSend($submissionId); }
        };
        $failingWorker = new SendWorker($failingStore, $accountService, $transport);
        $runOutbound($failingWorker);
        $assert($state($submissionId) === 'sending', 'Persistence failure did not leave the submission sending.');
        $pdo->exec(
            'UPDATE jobs SET lease_expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE target_reference = ' . $pdo->quote($submissionId)
        );
        $runOutbound($sendWorker);
        $assert($state($submissionId) === 'uncertain', 'Persistence failure recovery did not become uncertain.');
        $assert(($job($submissionId)['status'] ?? '') === 'failed', 'Recovered persistence job did not become terminal.');
    });

    $test('three authentication failures pause the account', function () use ($createAccount, $createSubmission, $runOutbound, $state, $job, $assert, $pdo, $basePort): void {
        $accountId = $createAccount($basePort, 'wrong-fixture-password', 'Controlled auth pause');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $submissionId = $createSubmission($accountId, ['one@example.com'], sprintf('worker_auth_%04d', $attempt));
            $runOutbound($GLOBALS['sendWorker']);
            $assert($state($submissionId) === 'failed', 'Authentication failure was not terminal.');
            $assert(($job($submissionId)['failure_category'] ?? '') === 'authentication', 'Authentication failure category missing.');
        }
        $enabled = (int) $pdo->query(
            'SELECT enabled FROM mail_accounts WHERE public_id = ' . $pdo->quote($accountId)
        )->fetchColumn();
        $assert($enabled === 0, 'Account was not paused after three authentication failures.');
    });

    fwrite(STDOUT, sprintf("Controlled SMTP worker checks passed (%d assertions).\n", $assertions));
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Controlled SMTP worker checks failed: ' . $throwable->getMessage() . "\n");
    exit(1);
}
