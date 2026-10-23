<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AdminSession;
use App\Middleware\AuthMiddleware;
use App\Security\CsrfTokenManager;
use App\Services\AccountService;
use App\Services\SubmissionService;
use App\Submissions\SubmissionRequest;
use DomainException;
use InvalidArgumentException;
use LengthException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final readonly class MailController
{
    private const OUTBOX_STATES = [
        'queued',
        'sending',
        'accepted',
        'partially_accepted',
        'failed',
        'uncertain',
        'expired',
        'canceled',
    ];

    private const OPERATION_ID_PATTERN = '/^[A-Za-z0-9_-]{16,128}$/';

    private const MAX_RECIPIENTS = 10;

    private const MAX_RAW_FIELD_BYTES = 10000;

    private const MAX_SUBJECT_LENGTH = 200;

    private const MAX_BODY_BYTES = 1048576;

    public function __construct(
        private SubmissionService $submissions,
        private AccountService $accounts,
        private CsrfTokenManager $csrf,
        private Environment $view,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function compose(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->requiredSession($request);

        return $this->composeForm($response, $session, null, [], 200);
    }

    public function send(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->requiredSession($request);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        /** @var array<string, string> $old */
        $old = [];
        $malformed = false;
        foreach (['account_id', 'to', 'cc', 'bcc', 'subject', 'text', 'html', 'operation_id'] as $field) {
            $value = $body[$field] ?? '';
            if (!is_string($value)) {
                $malformed = true;
                $value = '';
            }
            $old[$field] = $value;
        }

        if ($malformed) {
            return $this->composeForm(
                $response,
                $session,
                'Some fields contain invalid input.',
                $old,
                422,
            );
        }

        $error = $this->inputError($old);
        if ($error !== null) {
            return $this->composeForm($response, $session, $error, $old, 422);
        }

        $operationId = trim($old['operation_id']);
        if (preg_match(self::OPERATION_ID_PATTERN, $operationId) !== 1) {
            return $this->composeForm(
                $response,
                $session,
                'The form could not be verified. Please submit it again.',
                $old,
                422,
            );
        }

        $to = $this->splitRecipients($old['to']);
        $cc = $this->splitRecipients($old['cc']);
        $bcc = $this->splitRecipients($old['bcc']);
        if (count($to) + count($cc) + count($bcc) > self::MAX_RECIPIENTS) {
            return $this->composeForm(
                $response,
                $session,
                'A maximum of ' . self::MAX_RECIPIENTS . ' recipients is allowed.',
                $old,
                422,
            );
        }

        $html = $old['html'] !== '' ? $old['html'] : null;
        $submissionRequest = new SubmissionRequest(
            trim($old['account_id']),
            'panel',
            $to,
            $cc,
            $bcc,
            $old['subject'],
            $old['text'],
            $html,
            null,
            null,
            $operationId,
        );

        try {
            $result = $this->submissions->create($submissionRequest);
        } catch (InvalidArgumentException|DomainException $exception) {
            return $this->composeForm($response, $session, $exception->getMessage(), $old, 422);
        } catch (LengthException) {
            return $this->composeForm($response, $session, 'The message content is too large.', $old, 422);
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/outbox/' . rawurlencode($result->submissionId))
            ->withHeader('Cache-Control', 'no-store');
    }

    public function outbox(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->requiredSession($request);
        $query = $request->getQueryParams();

        $page = 1;
        $rawPage = $query['page'] ?? null;
        if (is_string($rawPage) && ctype_digit($rawPage)) {
            $page = max(1, (int) $rawPage);
        }

        $state = null;
        $rawState = $query['state'] ?? null;
        if (is_string($rawState) && in_array($rawState, self::OUTBOX_STATES, true)) {
            $state = $rawState;
        }

        $result = $this->submissions->outboxPage($page, 25, $state);
        $perPage = max(1, (int) ($result['per_page'] ?? 25));
        $total = max(0, (int) ($result['total'] ?? 0));
        $currentPage = max(1, (int) ($result['page'] ?? $page));
        $pageCount = max(1, (int) ceil($total / $perPage));

        return $this->html($response, 'mail/outbox.twig', [
            'title' => 'Outbox',
            'administrator' => $session,
            'items' => $this->outboxItems(is_array($result['items'] ?? null) ? $result['items'] : []),
            'page' => $currentPage,
            'per_page' => $perPage,
            'total' => $total,
            'page_count' => $pageCount,
            'state' => $state,
            'states' => self::OUTBOX_STATES,
            'csrf_token' => $this->csrf->issue('mail-cancel', $session->csrfSecret),
        ]);
    }

    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $session = $this->requiredSession($request);
        $submissionId = $args['id'] ?? '';
        if (!is_string($submissionId) || $submissionId === '') {
            return $this->notFound();
        }

        try {
            $detail = $this->submissions->detailForAuthenticatedUi($submissionId);
        } catch (DomainException) {
            return $this->notFound();
        }

        $snapshot = is_array($detail['snapshot'] ?? null) ? $detail['snapshot'] : [];
        $recipientsByOrdinal = $this->recipientsByOrdinal($snapshot);
        $outcomes = [];
        $rawOutcomes = $detail['recipient_outcomes'] ?? [];
        if (is_array($rawOutcomes)) {
            foreach ($rawOutcomes as $outcome) {
                if (!is_array($outcome)) {
                    continue;
                }
                $ordinal = (int) ($outcome['ordinal'] ?? -1);
                $outcomes[] = [
                    'recipient_type' => is_string($outcome['recipient_type'] ?? null)
                        ? $outcome['recipient_type']
                        : '',
                    'ordinal' => $ordinal,
                    'address' => $recipientsByOrdinal[$ordinal]['address'] ?? null,
                    'attempt_number' => (int) ($outcome['attempt_number'] ?? 0),
                    'outcome' => is_string($outcome['outcome'] ?? null) ? $outcome['outcome'] : '',
                    'failure_category' => is_string($outcome['failure_category'] ?? null)
                        ? $outcome['failure_category']
                        : null,
                    'recorded_at' => is_string($outcome['recorded_at'] ?? null)
                        ? $outcome['recorded_at']
                        : null,
                ];
            }
        }

        $query = $request->getQueryParams();
        $canceled = $query['canceled'] ?? null;
        $notice = match ($canceled) {
            '1' => 'The queued submission was canceled.',
            '0' => 'The submission could not be canceled because sending may already have started.',
            default => null,
        };

        $state = is_string($detail['state'] ?? null) ? $detail['state'] : '';

        return $this->html($response, 'mail/outbox-detail.twig', [
            'title' => 'Outbox submission',
            'administrator' => $session,
            'notice' => $notice,
            'submission' => [
                'id' => is_string($detail['public_id'] ?? null) ? $detail['public_id'] : $submissionId,
                'account_reference' => $detail['account_reference'] ?? null,
                'source' => $detail['source'] ?? null,
                'message_id' => $detail['message_id'] ?? null,
                'state' => $state,
                'attempts' => (int) ($detail['attempts'] ?? 0),
                'deadline_at' => $detail['deadline_at'] ?? null,
                'last_error_category' => is_string($detail['last_error_category'] ?? null)
                    ? $detail['last_error_category']
                    : null,
                'sent_copy_state' => $detail['sent_copy_state'] ?? null,
                'sending_started_at' => $detail['sending_started_at'] ?? null,
                'terminal_at' => $detail['terminal_at'] ?? null,
                'created_at' => $detail['created_at'] ?? null,
                'updated_at' => $detail['updated_at'] ?? null,
                'from' => is_array($snapshot['from'] ?? null) ? $snapshot['from'] : null,
                'reply_to' => $snapshot['reply_to'] ?? null,
                'subject' => is_string($snapshot['subject'] ?? null) ? $snapshot['subject'] : null,
                'to' => is_array($snapshot['to'] ?? null) ? $snapshot['to'] : [],
                'cc' => is_array($snapshot['cc'] ?? null) ? $snapshot['cc'] : [],
                'bcc' => is_array($snapshot['bcc'] ?? null) ? $snapshot['bcc'] : [],
            ],
            'recipient_outcomes' => $outcomes,
            'csrf_token' => $this->csrf->issue('mail-cancel', $session->csrfSecret),
        ]);
    }

    public function cancel(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requiredSession($request);
        $submissionId = $args['id'] ?? '';
        if (!is_string($submissionId) || $submissionId === '') {
            return $this->notFound();
        }

        $canceled = $this->submissions->cancelQueued($submissionId);

        return $response
            ->withStatus(303)
            ->withHeader(
                'Location',
                '/outbox/' . rawurlencode($submissionId) . '?canceled=' . ($canceled ? '1' : '0'),
            )
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, string> $old */
    private function inputError(array $old): ?string
    {
        foreach (['account_id', 'to', 'cc', 'bcc', 'subject', 'operation_id'] as $field) {
            if (strlen($old[$field]) > self::MAX_RAW_FIELD_BYTES) {
                return 'One or more fields are too long.';
            }
        }

        if (strlen($old['text']) + strlen($old['html']) > self::MAX_BODY_BYTES) {
            return 'The message body is too large.';
        }

        if ($this->textLength(trim($old['subject'])) > self::MAX_SUBJECT_LENGTH) {
            return 'Subject must be at most ' . self::MAX_SUBJECT_LENGTH . ' characters.';
        }

        if (trim($old['account_id']) === '') {
            return 'Select a sending account.';
        }

        return null;
    }

    /** @return list<string> */
    private function splitRecipients(string $value): array
    {
        $parts = preg_split('/[,;\r\n]+/', $value);
        if ($parts === false) {
            return [];
        }

        $recipients = [];
        foreach ($parts as $part) {
            $address = trim($part);
            if ($address !== '') {
                $recipients[] = $address;
            }
        }

        return $recipients;
    }

    /**
     * @param array<string, string> $old
     */
    private function composeForm(
        ResponseInterface $response,
        AdminSession $session,
        ?string $error,
        array $old,
        int $status,
    ): ResponseInterface {
        $accounts = array_values(array_filter(
            $this->accounts->list(),
            static fn (array $account): bool => !empty($account['enabled']),
        ));

        $operationId = $old['operation_id'] ?? '';
        if (preg_match(self::OPERATION_ID_PATTERN, $operationId) !== 1) {
            $operationId = bin2hex(random_bytes(16));
        }

        return $this->html($response->withStatus($status), 'mail/compose.twig', [
            'title' => 'Compose message',
            'administrator' => $session,
            'accounts' => $accounts,
            'csrf_token' => $this->csrf->issue('mail-send', $session->csrfSecret),
            'operation_id' => $operationId,
            'old' => $old,
            'error' => $error,
        ]);
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function outboxItems(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $row['public_id'] ?? $row['id'] ?? null;
            if (!is_string($id) || $id === '') {
                continue;
            }

            $summary = $this->recipientSummary($row);
            if ($summary === null) {
                $count = $row['recipient_count'] ?? null;
                if (is_int($count) || (is_string($count) && ctype_digit($count))) {
                    $count = (int) $count;
                    $summary = $count . ' recipient' . ($count === 1 ? '' : 's');
                }
            }
            if ($summary === null) {
                $summary = $this->summaryFromDetail($id);
            }

            $account = $row['account_label'] ?? $row['account_reference'] ?? null;
            $failure = $row['last_error_category'] ?? $row['failure_category'] ?? null;

            $items[] = [
                'id' => $id,
                'created_at' => $this->stringOrNull($row['created_at'] ?? null),
                'updated_at' => $this->stringOrNull($row['updated_at'] ?? null),
                'deadline_at' => $this->stringOrNull($row['deadline_at'] ?? null),
                'account' => $this->stringOrNull($account),
                'source' => $this->stringOrNull($row['source'] ?? null),
                'state' => $this->stringOrNull($row['state'] ?? null),
                'attempts' => (int) ($row['attempts'] ?? 0),
                'failure_category' => $this->stringOrNull($failure),
                'sent_copy_state' => $this->stringOrNull($row['sent_copy_state'] ?? null),
                'recipient_summary' => $summary,
            ];
        }

        return $items;
    }

    /** @param array<string, mixed> $row */
    private function recipientSummary(array $row): ?string
    {
        foreach (['recipient_summary', 'recipients_summary'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $value = $row[$key];
            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '') {
                    return $value;
                }
            } elseif (is_array($value)) {
                $description = $this->describeRecipients($value);
                if ($description !== null) {
                    return $description;
                }
            }
        }

        if (is_array($row['recipients'] ?? null)) {
            return $this->describeRecipients($row['recipients']);
        }

        return null;
    }

    private function summaryFromDetail(string $submissionId): ?string
    {
        try {
            $detail = $this->submissions->detailForAuthenticatedUi($submissionId);
        } catch (\Throwable) {
            return null;
        }

        $snapshot = $detail['snapshot'] ?? null;
        if (!is_array($snapshot)) {
            return null;
        }

        return $this->describeRecipients($snapshot);
    }

    private function describeRecipients(mixed $value): ?string
    {
        if (!is_array($value) || $value === []) {
            return null;
        }

        $groups = ['to' => [], 'cc' => [], 'bcc' => []];
        if (array_key_exists('to', $value) || array_key_exists('cc', $value) || array_key_exists('bcc', $value)) {
            foreach (array_keys($groups) as $type) {
                $groups[$type] = $this->recipientGroup($value[$type] ?? []);
            }
        } else {
            foreach ($value as $entry) {
                $type = 'to';
                $address = null;
                if (is_string($entry)) {
                    $address = $entry;
                } elseif (is_array($entry)) {
                    $candidateType = $entry['type'] ?? $entry['recipient_type'] ?? null;
                    if (is_string($candidateType) && array_key_exists($candidateType, $groups)) {
                        $type = $candidateType;
                    }
                    $candidate = $entry['address'] ?? $entry['email'] ?? null;
                    $address = is_string($candidate) ? $candidate : null;
                }
                $address = is_string($address) ? trim($address) : '';
                if ($address !== '') {
                    $groups[$type][] = $address;
                }
            }
        }

        $parts = [];
        foreach ($groups as $type => $addresses) {
            if ($addresses === []) {
                continue;
            }
            $masked = [];
            foreach (array_slice($addresses, 0, 3) as $address) {
                $masked[] = $this->maskAddress($address);
            }
            $part = strtoupper($type) . ': ' . implode(', ', $masked);
            if (count($addresses) > 3) {
                $part .= ' +' . (count($addresses) - 3) . ' more';
            }
            $parts[] = $part;
        }

        return $parts === [] ? null : implode('; ', $parts);
    }

    /** @return list<string> */
    private function recipientGroup(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);
            return $value === '' ? [] : [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $addresses = [];
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $entry = trim($entry);
                if ($entry !== '') {
                    $addresses[] = $entry;
                }
                continue;
            }
            if (is_array($entry)) {
                $candidate = $entry['email'] ?? $entry['address'] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    $addresses[] = trim($candidate);
                }
            }
        }

        return $addresses;
    }

    private function maskAddress(string $address): string
    {
        $address = trim($address);
        $at = strrpos($address, '@');
        if ($at === false) {
            return $this->firstCharacter($address) . '***';
        }

        return $this->firstCharacter(substr($address, 0, $at)) . '***' . substr($address, $at);
    }

    private function firstCharacter(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, 1) : substr($value, 0, 1);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<int, array{type: string, address: string}>
     */
    private function recipientsByOrdinal(array $snapshot): array
    {
        $byOrdinal = [];
        $ordinal = 0;
        foreach (['to', 'cc', 'bcc'] as $type) {
            $list = $snapshot[$type] ?? [];
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $recipient) {
                $address = is_array($recipient) ? ($recipient['email'] ?? null) : $recipient;
                if (is_string($address) && $address !== '') {
                    $byOrdinal[$ordinal] = ['type' => $type, 'address' => $address];
                }
                $ordinal++;
            }
        }

        return $byOrdinal;
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function notFound(): ResponseInterface
    {
        $response = $this->responses->createResponse(404)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write('Submission not found.');

        return $response;
    }

    private function requiredSession(ServerRequestInterface $request): AdminSession
    {
        $session = $request->getAttribute(AuthMiddleware::ATTRIBUTE);
        if (!$session instanceof AdminSession) {
            throw new \LogicException('Protected route has no administrator session.');
        }

        return $session;
    }

    /** @param array<string, mixed> $data */
    private function html(ResponseInterface $response, string $template, array $data): ResponseInterface
    {
        $response->getBody()->write($this->view->render($template, $data));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
