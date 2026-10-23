<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AdminSession;
use App\Mail\SmtpConnectionTester;
use App\Middleware\AuthMiddleware;
use App\Security\CsrfTokenManager;
use App\Services\AccountService;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Twig\Environment;

final readonly class AccountController
{
    /** @var array<string, string> */
    private const TEST_MESSAGES = [
        'dns' => 'Connection test failed: the SMTP hostname did not resolve.',
        'connectivity' => 'Connection test failed: the SMTP endpoint could not be reached.',
        'tls' => 'Connection test failed: TLS or certificate verification failed.',
        'auth' => 'Connection test failed: the SMTP server rejected the username or password.',
        'sender_auth' => 'Connection test failed: the SMTP server rejected the sender identity.',
        'quota' => 'Connection test failed: the SMTP server reported a sending limit or quota.',
        'protocol' => 'Connection test failed: the SMTP server returned an unexpected response.',
    ];

    public function __construct(
        private AccountService $accounts,
        private SmtpConnectionTester $tester,
        private CsrfTokenManager $csrf,
        private Environment $view,
        private ResponseFactoryInterface $responses,
        private int $connectTimeoutSeconds = 10,
        private int $operationTimeoutSeconds = 20,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->requiredSession($request);
        [$notice, $error] = $this->messagesFromQuery($request->getQueryParams(), false);

        return $this->renderIndex($session, $response, $notice, $error);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->requiredSession($request);

        return $this->renderForm($session, $response, $this->emptyAccount(), false, null, null);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->requiredSession($request);
        $input = $this->inputFromBody($this->body($request));
        $account = $this->applyInput($this->emptyAccount(), $input);
        if (strlen($input['password']) > 1024) {
            return $this->renderForm(
                $session,
                $response,
                $account,
                false,
                null,
                'Password input is too long.',
                422,
            );
        }

        try {
            $this->accounts->create($input);
        } catch (InvalidArgumentException $exception) {
            return $this->renderForm(
                $session,
                $response,
                $account,
                false,
                null,
                $exception->getMessage(),
                422,
            );
        }

        return $this->redirect('/accounts?saved=1');
    }

    /** @param array<string, mixed> $args */
    public function edit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = [],
    ): ResponseInterface {
        $session = $this->requiredSession($request);
        $publicId = $this->routeAccountId($args);
        if ($publicId === '') {
            return $this->notFound();
        }

        try {
            $account = $this->accounts->view($publicId);
        } catch (InvalidArgumentException) {
            return $this->notFound();
        }

        [$notice, $error] = $this->messagesFromQuery($request->getQueryParams(), true);

        return $this->renderForm(
            $session,
            $response,
            $this->accountForDisplay($account),
            true,
            $notice,
            $error,
        );
    }

    /** @param array<string, mixed> $args */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = [],
    ): ResponseInterface {
        $session = $this->requiredSession($request);
        $publicId = $this->routeAccountId($args);
        if ($publicId === '') {
            return $this->notFound();
        }

        try {
            $existing = $this->accounts->view($publicId);
        } catch (InvalidArgumentException) {
            return $this->notFound();
        }

        $input = $this->inputFromBody($this->body($request));
        $account = $this->applyInput($this->accountForDisplay($existing), $input);
        if (strlen($input['password']) > 1024) {
            return $this->renderForm(
                $session,
                $response,
                $account,
                true,
                null,
                'Password input is too long.',
                422,
            );
        }

        try {
            $this->accounts->update($publicId, $input);
        } catch (InvalidArgumentException $exception) {
            try {
                $this->accounts->view($publicId);
            } catch (InvalidArgumentException) {
                return $this->notFound();
            }

            return $this->renderForm(
                $session,
                $response,
                $account,
                true,
                null,
                $exception->getMessage(),
                422,
            );
        }

        return $this->redirect('/accounts/' . rawurlencode($publicId) . '/edit?saved=1');
    }

    /** @param array<string, mixed> $args */
    public function disable(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = [],
    ): ResponseInterface {
        $this->requiredSession($request);
        $publicId = $this->routeAccountId($args);
        if ($publicId === '') {
            return $this->notFound();
        }

        try {
            $this->accounts->disable($publicId);
        } catch (InvalidArgumentException) {
            return $this->notFound();
        }

        return $this->redirect('/accounts?disabled=1');
    }

    /** @param array<string, mixed> $args */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = [],
    ): ResponseInterface {
        $session = $this->requiredSession($request);
        $publicId = $this->routeAccountId($args);
        if ($publicId === '') {
            return $this->notFound();
        }

        try {
            $account = $this->accounts->view($publicId);
        } catch (InvalidArgumentException) {
            return $this->notFound();
        }

        $body = $this->body($request);
        if (!$this->confirmationMatches($body['confirm'] ?? null, (string) ($account['label'] ?? ''))) {
            return $this->renderIndex(
                $session,
                $response,
                null,
                'Deletion was not confirmed. Type the account label or check the confirmation box to delete the account.',
                422,
            );
        }

        try {
            $this->accounts->delete($publicId);
        } catch (InvalidArgumentException) {
            return $this->notFound();
        }

        return $this->redirect('/accounts?deleted=1');
    }

    /** @param array<string, mixed> $args */
    public function test(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = [],
    ): ResponseInterface {
        $this->requiredSession($request);
        $publicId = $this->routeAccountId($args);
        if ($publicId === '') {
            return $this->notFound();
        }

        try {
            $account = $this->accounts->view($publicId);
            $password = $this->accounts->revealPasswordForWorker($publicId);
        } catch (InvalidArgumentException) {
            return $this->notFound();
        }

        $configuration = [
            'host' => (string) ($account['smtp_host'] ?? ''),
            'port' => (int) ($account['smtp_port'] ?? 0),
            'tls' => (string) ($account['smtp_tls'] ?? ''),
            'username' => (string) ($account['smtp_username'] ?? ''),
            'password' => $password,
            'from_email' => (string) ($account['from_email'] ?? ''),
        ];

        try {
            $result = $this->tester->test(
                $configuration,
                $this->connectTimeoutSeconds,
                $this->operationTimeoutSeconds,
            );
        } catch (Throwable) {
            $result = ['ok' => false, 'category' => 'protocol'];
        }

        $category = ($result['ok'] ?? false) === true
            ? 'ok'
            : (string) ($result['category'] ?? 'protocol');
        if ($category !== 'ok' && !in_array($category, SmtpConnectionTester::CATEGORIES, true)) {
            $category = 'protocol';
        }

        return $this->redirect(
            '/accounts/' . rawurlencode($publicId) . '/edit?test=' . rawurlencode($category),
        );
    }

    private function renderIndex(
        AdminSession $session,
        ResponseInterface $response,
        ?string $notice,
        ?string $error,
        int $status = 200,
    ): ResponseInterface {
        return $this->html($response->withStatus($status), 'accounts/index.twig', [
            'title' => 'Sending accounts',
            'administrator' => $session,
            'accounts' => $this->accounts->list(),
            'disable_csrf' => $this->csrf->issue('account-disable', $session->csrfSecret),
            'delete_csrf' => $this->csrf->issue('account-delete', $session->csrfSecret),
            'test_csrf' => $this->csrf->issue('account-test', $session->csrfSecret),
            'notice' => $notice,
            'error' => $error,
        ]);
    }

    /** @param array<string, mixed> $account */
    private function renderForm(
        AdminSession $session,
        ResponseInterface $response,
        array $account,
        bool $isEdit,
        ?string $notice,
        ?string $error,
        int $status = 200,
    ): ResponseInterface {
        $publicId = (string) ($account['public_id'] ?? '');

        return $this->html($response->withStatus($status), 'accounts/form.twig', [
            'title' => $isEdit ? 'Edit sending account' : 'New sending account',
            'administrator' => $session,
            'account' => $account,
            'is_edit' => $isEdit,
            'form_action' => $isEdit
                ? '/accounts/' . rawurlencode($publicId) . '/edit'
                : '/accounts',
            'csrf_token' => $this->csrf->issue(
                $isEdit ? 'account-update' : 'account-create',
                $session->csrfSecret,
            ),
            'test_csrf' => $isEdit
                ? $this->csrf->issue('account-test', $session->csrfSecret)
                : null,
            'notice' => $notice,
            'error' => $error,
        ]);
    }

    private function requiredSession(ServerRequestInterface $request): AdminSession
    {
        $session = $request->getAttribute(AuthMiddleware::ATTRIBUTE);
        if (!$session instanceof AdminSession) {
            throw new LogicException('Protected route has no administrator session.');
        }

        return $session;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function inputFromBody(array $body): array
    {
        return [
            'label' => $this->stringField($body, 'label'),
            'smtp_host' => $this->stringField($body, 'smtp_host'),
            'smtp_port' => $this->integerField($body, 'smtp_port', 0),
            'smtp_username' => $this->stringField($body, 'smtp_username'),
            'password' => $this->stringField($body, 'password'),
            'from_email' => $this->stringField($body, 'from_email'),
            'from_name' => $this->stringField($body, 'from_name'),
            'reply_to_email' => $this->stringField($body, 'reply_to_email'),
            'enabled' => !empty($body['enabled']),
            'max_recipients' => $this->integerField($body, 'max_recipients', 10),
            'max_hourly_recipients' => $this->integerField($body, 'max_hourly_recipients', 100),
            'max_outbound_bytes' => $this->integerField($body, 'max_outbound_bytes', 20971520),
        ];
    }

    /** @param array<string, mixed> $body */
    private function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }

        return is_int($value) ? (string) $value : '';
    }

    /** @param array<string, mixed> $body */
    private function integerField(array $body, string $key, int $default): int
    {
        if (!array_key_exists($key, $body)) {
            return $default;
        }

        $value = $body[$key];
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return 0;
    }

    /** @return array<string, mixed> */
    private function emptyAccount(): array
    {
        return [
            'public_id' => '',
            'label' => '',
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_tls' => 'starttls',
            'smtp_username' => '',
            'from_email' => '',
            'from_name' => '',
            'reply_to_email' => '',
            'enabled' => true,
            'pause_reason' => null,
            'max_recipients' => 10,
            'max_hourly_recipients' => 100,
            'max_outbound_bytes' => 20971520,
            'password_changed_at' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function accountForDisplay(array $source): array
    {
        $account = $this->emptyAccount();
        $account['public_id'] = is_string($source['public_id'] ?? null)
            ? $source['public_id']
            : '';

        $account = $this->applyInput($account, [
            'label' => $source['label'] ?? '',
            'smtp_host' => $source['smtp_host'] ?? '',
            'smtp_port' => $source['smtp_port'] ?? 0,
            'smtp_username' => $source['smtp_username'] ?? '',
            'from_email' => $source['from_email'] ?? '',
            'from_name' => $source['from_name'] ?? '',
            'reply_to_email' => $source['reply_to_email'] ?? '',
            'enabled' => $source['enabled'] ?? false,
            'max_recipients' => $source['max_recipients'] ?? 10,
            'max_hourly_recipients' => $source['max_hourly_recipients'] ?? 100,
            'max_outbound_bytes' => $source['max_outbound_bytes'] ?? 20971520,
        ]);

        $account['pause_reason'] = $source['pause_reason'] ?? null;
        $account['password_changed_at'] = $source['password_changed_at'] ?? null;
        $account['created_at'] = $source['created_at'] ?? null;
        $account['updated_at'] = $source['updated_at'] ?? null;

        return $account;
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function applyInput(array $account, array $input): array
    {
        $account['label'] = (string) ($input['label'] ?? '');
        $account['smtp_host'] = (string) ($input['smtp_host'] ?? '');

        $port = (int) ($input['smtp_port'] ?? 0);
        $account['smtp_port'] = $port;
        $account['smtp_tls'] = match ($port) {
            465 => 'implicit',
            587 => 'starttls',
            default => '',
        };

        $account['smtp_username'] = (string) ($input['smtp_username'] ?? '');
        $account['from_email'] = (string) ($input['from_email'] ?? '');
        $account['from_name'] = (string) ($input['from_name'] ?? '');
        $account['reply_to_email'] = (string) ($input['reply_to_email'] ?? '');
        $account['enabled'] = !empty($input['enabled']);
        $account['max_recipients'] = (int) ($input['max_recipients'] ?? 10);
        $account['max_hourly_recipients'] = (int) ($input['max_hourly_recipients'] ?? 100);
        $account['max_outbound_bytes'] = (int) ($input['max_outbound_bytes'] ?? 20971520);

        return $account;
    }

    /** @param array<string, mixed> $args */
    private function routeAccountId(array $args): string
    {
        $id = $args['id'] ?? $args['account_id'] ?? $args['account'] ?? null;

        return is_string($id) ? $id : '';
    }

    private function confirmationMatches(mixed $confirm, string $label): bool
    {
        if (!is_string($confirm)) {
            return false;
        }

        $confirm = trim($confirm);

        return $confirm !== '' && (
            ($label !== '' && $confirm === $label)
            || in_array($confirm, ['1', 'on', 'yes', 'true'], true)
        );
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: ?string, 1: ?string}
     */
    private function messagesFromQuery(array $query, bool $testAsError): array
    {
        $notice = null;
        $error = null;

        if (($query['saved'] ?? null) === '1') {
            $notice = 'Sending account saved.';
        } elseif (($query['disabled'] ?? null) === '1') {
            $notice = 'Sending account paused.';
        } elseif (($query['deleted'] ?? null) === '1') {
            $notice = 'Sending account deleted.';
        }

        $test = $query['test'] ?? null;
        if ($test === 'ok') {
            $notice = 'Connection test succeeded.';
        } elseif (is_string($test) && isset(self::TEST_MESSAGES[$test])) {
            if ($testAsError) {
                $error = self::TEST_MESSAGES[$test];
            } else {
                $notice = self::TEST_MESSAGES[$test];
            }
        }

        return [$notice, $error];
    }

    /** @param array<string, mixed> $data */
    private function html(ResponseInterface $response, string $template, array $data): ResponseInterface
    {
        $response->getBody()->write($this->view->render($template, $data));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function redirect(string $location): ResponseInterface
    {
        return $this->responses->createResponse(303)
            ->withHeader('Location', $location)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function notFound(): ResponseInterface
    {
        $response = $this->responses->createResponse(404)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
        $response->getBody()->write('Account not found.');

        return $response;
    }
}
