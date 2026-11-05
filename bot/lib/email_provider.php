<?php

/** Provider-independent email OTP delivery. Mock is the default in tests. */
interface ModasrEmailProvider
{
    public function name(): string;

    /** @return array{status:string,message_id:?string} sent, failed, or unknown */
    public function send(string $email, string $subject, string $message, string $requestId): array;
}

final class ModasrMockEmailProvider implements ModasrEmailProvider
{
    /** @var array<int,array{email:string,subject:string,message:string,request_id:string}> */
    public array $outbox = [];

    public function __construct(private string $outcome = 'sent')
    {
        if (!in_array($this->outcome, ['sent', 'failed', 'unknown'], true)) {
            throw new InvalidArgumentException('invalid mock outcome');
        }
    }

    public function name(): string
    {
        return 'mock_email';
    }

    public function send(string $email, string $subject, string $message, string $requestId): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-f0-9]{32}$/', $requestId)) {
            throw new InvalidArgumentException('invalid mock email request');
        }
        foreach ($this->outbox as $entry) {
            if ($entry['request_id'] === $requestId) {
                return ['status' => $this->outcome, 'message_id' => 'email_' . $requestId];
            }
        }
        $this->outbox[] = ['email' => $email, 'subject' => $subject, 'message' => $message, 'request_id' => $requestId];
        return ['status' => $this->outcome, 'message_id' => 'email_' . $requestId];
    }
}

/**
 * Optional zero-provider-cost transport using the server's configured MTA.
 * It is never selected by default. mail() acceptance is not proof of delivery.
 */
final class ModasrNativeMailProvider implements ModasrEmailProvider
{
    public function __construct(
        private string $fromEmail,
        private string $fromName = 'MODASR HUB',
        private string $replyTo = ''
    ) {
        if (!filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $this->fromEmail)) {
            throw new InvalidArgumentException('email_from_invalid');
        }
        if ($this->replyTo !== '' && (!filter_var($this->replyTo, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $this->replyTo))) {
            throw new InvalidArgumentException('email_reply_to_invalid');
        }
        if (preg_match('/[\r\n]/', $this->fromName)) {
            throw new InvalidArgumentException('email_from_name_invalid');
        }
    }

    public function name(): string
    {
        return 'native_mail';
    }

    public function send(string $email, string $subject, string $message, string $requestId): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-f0-9]{32}$/', $requestId)) {
            throw new InvalidArgumentException('invalid native email request');
        }
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . $this->fromName . ' <' . $this->fromEmail . '>',
            'X-Modasr-Request-ID: ' . $requestId,
        ];
        if ($this->replyTo !== '') {
            $headers[] = 'Reply-To: ' . $this->replyTo;
        }

        try {
            $accepted = mail($email, $subject, $message, implode("\r\n", $headers));
            return ['status' => $accepted ? 'sent' : 'failed', 'message_id' => $accepted ? 'mail_' . $requestId : null];
        } catch (Throwable) {
            return ['status' => 'unknown', 'message_id' => null];
        }
    }
}

function modasrEmailProviderFromEnvironment(): ModasrEmailProvider
{
    $provider = strtolower(trim((string) (getenv('MODASR_EMAIL_PROVIDER') ?: 'mock')));
    if ($provider === 'mock') {
        return new ModasrMockEmailProvider();
    }
    if ($provider === 'native') {
        $from = trim((string) (getenv('MODASR_EMAIL_FROM') ?: ''));
        $fromName = trim((string) (getenv('MODASR_EMAIL_FROM_NAME') ?: 'MODASR HUB'));
        $replyTo = trim((string) (getenv('MODASR_EMAIL_REPLY_TO') ?: ''));
        if ($from === '') {
            throw new RuntimeException('email_provider_not_configured');
        }
        return new ModasrNativeMailProvider($from, $fromName, $replyTo);
    }
    throw new RuntimeException('email_provider_not_configured');
}
