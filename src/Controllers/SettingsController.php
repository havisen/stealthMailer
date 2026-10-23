<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Api\IssuedApiKey;
use App\Auth\AdminSession;
use App\Middleware\AuthMiddleware;
use App\Repositories\JobRepository;
use App\Security\CsrfTokenManager;
use App\Services\AccountService;
use App\Services\ApiKeyService;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final readonly class SettingsController
{
    private const ACCOUNT_ID_PATTERN = '/\Aacct_[a-f0-9]{32}\z/D';
    private const KEY_ID_PATTERN = '/\Akey_[a-f0-9]{32}\z/D';
    private const STALE_AFTER_SECONDS = 300;

    public function __construct(
        private ApiKeyService $keys,
        private AccountService $accounts,
        private JobRepository $jobs,
        private CsrfTokenManager $csrf,
        private Environment $view,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function apiKeys(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->session($request);
        $query = $request->getQueryParams();
        $notice = match (is_string($query['revoked'] ?? null) ? $query['revoked'] : null) {
            '1' => 'API key revoked. New API calls using that key are no longer authorized.',
            '0' => 'No API key was revoked. It may already be revoked, or the request was invalid.',
            default => null,
        };

        return $this->renderKeys(
            $response,
            $session,
            $this->accounts->list(),
            '',
            [],
            null,
            null,
            $notice,
        );
    }

    public function createKey(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->session($request);
        $body = $this->body($request);
        if (!$this->csrfValid($body, 'api-key-create', $session)) {
            return $this->forbidden('Request could not be verified.');
        }

        $accounts = $this->accounts->list();
        $label = is_string($body['label'] ?? null) ? trim($body['label']) : '';
        $rawSelection = is_array($body['account_ids'] ?? null) ? $body['account_ids'] : [];
        $selected = [];
        $selectionValid = $rawSelection !== [];
        foreach ($rawSelection as $candidate) {
            if (!is_string($candidate) || preg_match(self::ACCOUNT_ID_PATTERN, $candidate) !== 1) {
                $selectionValid = false;
                continue;
            }
            $selected[$candidate] = $candidate;
        }
        $selected = array_values($selected);

        $knownAccounts = [];
        foreach ($accounts as $account) {
            if (is_array($account) && is_string($account['public_id'] ?? null)) {
                $knownAccounts[$account['public_id']] = true;
            }
        }

        $errors = [];
        if ($label === '' || $this->length($label) > 120) {
            $errors[] = 'Enter a label of at most 120 characters.';
        }
        if (!$selectionValid || $selected === []) {
            $errors[] = 'Select at least one existing sending account.';
        } else {
            foreach ($selected as $accountId) {
                if (!isset($knownAccounts[$accountId])) {
                    $errors[] = 'Select at least one existing sending account.';
                    break;
                }
            }
        }

        if ($errors !== []) {
            return $this->renderKeys(
                $response,
                $session,
                $accounts,
                $label,
                $selected,
                null,
                implode(' ', array_values(array_unique($errors))),
                null,
                422,
            );
        }

        try {
            $issued = $this->keys->create($label, $selected);
        } catch (\InvalidArgumentException $exception) {
            return $this->renderKeys(
                $response,
                $session,
                $accounts,
                $label,
                $selected,
                null,
                $exception->getMessage(),
                null,
                422,
            );
        }

        return $this->renderKeys(
            $response,
            $session,
            $accounts,
            '',
            [],
            $issued,
            null,
            null,
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function revokeKey(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = [],
    ): ResponseInterface {
        $session = $this->session($request);
        $body = $this->body($request);
        if (!$this->csrfValid($body, 'api-key-revoke', $session)) {
            return $this->forbidden('Request could not be verified.');
        }

        $publicId = $args['publicId'] ?? $args['public_id'] ?? ($body['public_id'] ?? null);
        if (!is_string($publicId) || preg_match(self::KEY_ID_PATTERN, $publicId) !== 1) {
            return $this->redirect($response, '/settings/api-keys?revoked=0');
        }

        $revoked = $this->keys->revoke($publicId);

        return $this->redirect($response, '/settings/api-keys?revoked=' . ($revoked ? '1' : '0'));
    }

    public function diagnostics(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->session($request);
        $diagnostics = $this->jobs->diagnostics();
        $sourceWorker = is_array($diagnostics['worker'] ?? null) ? $diagnostics['worker'] : [];
        $sourceQueues = is_array($diagnostics['queues'] ?? null) ? $diagnostics['queues'] : [];

        $heartbeatAt = is_string($sourceWorker['last_heartbeat_at'] ?? null)
            && $sourceWorker['last_heartbeat_at'] !== ''
            ? $sourceWorker['last_heartbeat_at']
            : null;
        $heartbeatAgeSeconds = $heartbeatAt === null ? null : $this->ageSeconds($heartbeatAt);
        $stale = (bool) ($sourceWorker['stale'] ?? false)
            || $heartbeatAt === null
            || $heartbeatAgeSeconds === null
            || $heartbeatAgeSeconds >= self::STALE_AFTER_SECONDS;
        $heartbeatAge = $heartbeatAgeSeconds === null ? null : $this->humanAge($heartbeatAgeSeconds);

        $staleWarning = null;
        if ($stale) {
            if ($heartbeatAt === null) {
                $staleWarning = 'No worker heartbeat has been recorded. Scheduled jobs may not be running; check the cron entry and worker command.';
            } elseif ($heartbeatAgeSeconds !== null && $heartbeatAgeSeconds >= self::STALE_AFTER_SECONDS) {
                $staleWarning = 'Worker heartbeat is stale: last seen ' . $heartbeatAge
                    . ' (threshold: 5 minutes). Scheduled jobs may not be running; check the cron entry and worker command.';
            } else {
                $staleWarning = 'The worker is marked stale' . ($heartbeatAge !== null
                    ? ' (last heartbeat ' . $heartbeatAge . '; threshold: 5 minutes)'
                    : '') . '. Scheduled jobs may not be running; check the cron entry and worker command.';
            }
        }

        $queues = [];
        foreach (JobRepository::QUEUES as $queueName) {
            $sourceQueue = is_array($sourceQueues[$queueName] ?? null) ? $sourceQueues[$queueName] : [];
            $oldest = $sourceQueue['oldest_queued_at'] ?? null;
            $queues[$queueName] = [
                'queued' => max(0, (int) ($sourceQueue['queued'] ?? 0)),
                'leased' => max(0, (int) ($sourceQueue['leased'] ?? 0)),
                'oldest_queued_at' => is_string($oldest) && $oldest !== '' ? $oldest : null,
            ];
        }

        $worker = [
            'last_heartbeat_at' => $heartbeatAt,
            'last_finished_at' => $this->nullableString($sourceWorker['last_finished_at'] ?? null),
            'last_exit_reason' => $this->nullableString($sourceWorker['last_exit_reason'] ?? null),
            'stale' => $stale,
        ];

        $accounts = [];
        foreach ($this->accounts->list() as $account) {
            if (!is_array($account)) {
                continue;
            }
            $accounts[] = [
                'label' => $this->nullableString($account['label'] ?? null) ?? 'Unnamed account',
                'from_email' => $this->nullableString($account['from_email'] ?? null) ?? '',
                'enabled' => (bool) ($account['enabled'] ?? false),
                'pause_reason' => $this->nullableString($account['pause_reason'] ?? null),
            ];
        }

        return $this->html($response, 'settings/diagnostics.twig', [
            'title' => 'Diagnostics',
            'administrator' => $session,
            'notice' => $this->diagnosticsNotice($request->getQueryParams()),
            'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
            'worker' => $worker,
            'heartbeat_age' => $heartbeatAge,
            'stale_warning' => $staleWarning,
            'queues' => $queues,
            'expired_leases' => max(0, (int) ($diagnostics['expired_leases'] ?? 0)),
            'failed_jobs_24h' => max(0, (int) ($diagnostics['failed_jobs_24h'] ?? 0)),
            'accounts' => $accounts,
        ]);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->session($request);

        return $this->html($response, 'settings/index.twig', [
            'title' => 'Settings',
            'administrator' => $session,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $accounts
     * @param list<string> $selectedAccountIds
     */
    private function renderKeys(
        ResponseInterface $response,
        AdminSession $session,
        array $accounts,
        string $label,
        array $selectedAccountIds,
        ?IssuedApiKey $issuedKey,
        ?string $error,
        ?string $notice,
        int $status = 200,
    ): ResponseInterface {
        return $this->html($response->withStatus($status), 'settings/api-keys.twig', [
            'title' => 'API keys',
            'administrator' => $session,
            'notice' => $notice,
            'error' => $error,
            'keys' => $this->keys->list(),
            'accounts' => $accounts,
            'csrf_create' => $this->csrf->issue('api-key-create', $session->csrfSecret),
            'csrf_revoke' => $this->csrf->issue('api-key-revoke', $session->csrfSecret),
            'issued_key' => $issuedKey,
            'label' => $label,
            'selected_account_ids' => $selectedAccountIds,
        ]);
    }

    private function session(ServerRequestInterface $request): AdminSession
    {
        $session = $request->getAttribute(AuthMiddleware::ATTRIBUTE);
        if (!$session instanceof AdminSession) {
            throw new \LogicException('Protected route has no administrator session.');
        }

        return $session;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /** @param array<string, mixed> $body */
    private function csrfValid(array $body, string $action, AdminSession $session): bool
    {
        $token = is_string($body['_csrf'] ?? null) ? $body['_csrf'] : '';

        return $token !== '' && $this->csrf->validate($token, $action, $session->csrfSecret);
    }

    private function forbidden(string $message): ResponseInterface
    {
        $response = $this->responses->createResponse(403)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write($message);

        return $response;
    }

    private function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withStatus(303)
            ->withHeader('Location', $location)
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, mixed> $data */
    private function html(ResponseInterface $response, string $template, array $data): ResponseInterface
    {
        $response->getBody()->write($this->view->render($template, $data));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function ageSeconds(string $timestamp): ?int
    {
        $parsed = $this->parseUtc($timestamp);
        if ($parsed === null) {
            return null;
        }

        return max(0, time() - $parsed->getTimestamp());
    }

    private function parseUtc(string $value): ?DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u', '!Y-m-d H:i:s', '!Y-m-d\TH:i:s.u\Z', '!Y-m-d\TH:i:s\Z'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($parsed !== false) {
                return $parsed;
            }
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private function humanAge(int $seconds): string
    {
        if ($seconds < 60) {
            return 'less than a minute ago';
        }
        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);

            return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
        }
        if ($seconds < 86400) {
            $hours = intdiv($seconds, 3600);

            return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
        }
        $days = intdiv($seconds, 86400);

        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }

    /** @param array<string, mixed> $query */
    private function diagnosticsNotice(array $query): ?string
    {
        if (($query['refreshed'] ?? null) === '1') {
            return 'Diagnostics refreshed. The values below are the current snapshot.';
        }
        if (($query['paused'] ?? null) === '1') {
            return 'One or more sending accounts are paused. Review their state below.';
        }
        if (($query['stale'] ?? null) === '1') {
            return 'The worker heartbeat is stale. Check the scheduled worker.';
        }

        return $query === [] ? null : 'Showing the current diagnostics snapshot.';
    }
}
