<?php

declare(strict_types=1);

namespace Plugin\jtl_dbbackup_tool\Service;

/**
 * Spec decision "Benachrichtigung": e-mail only on failure (backup or
 * upload), never on success, to avoid notification fatigue burying the one
 * message that actually matters.
 *
 * Uses plain PHP mail() rather than a core mail service — JTL-Shop almost
 * certainly has its own mail-sending abstraction (templated HTML mails,
 * proper SMTP config) that this should probably use instead, but that API
 * hasn't been verified yet; mail() is a safe, dependency-free placeholder
 * that will actually deliver on most hosting setups in the meantime.
 */
final class NotificationService
{
    public function __construct(private readonly ?string $notifyEmail)
    {
    }

    public function notifyFailure(string $subject, string $message): void
    {
        if ($this->notifyEmail === null || $this->notifyEmail === '') {
            return;
        }

        $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
        @\mail($this->notifyEmail, '[DB Backup Tool] ' . $subject, $message, $headers);
    }
}
