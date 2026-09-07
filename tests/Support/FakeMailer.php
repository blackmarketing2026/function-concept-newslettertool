<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Queue\MailerInterface;
use App\Queue\MailerMessage;
use App\Queue\MailerResult;

/**
 * Test-Double fuer MailerInterface: sendet keine echten Emails, sondern
 * simuliert konfigurierbares Erfolgs-/Fehlverhalten fuer QueueWorker-Tests.
 */
final class FakeMailer implements MailerInterface
{
    /** @var string[] toEmail-Adressen, die als "hard bounce" fehlschlagen sollen */
    public array $hardBounceEmails = [];

    /** @var string[] toEmail-Adressen, die als "soft bounce" fehlschlagen sollen */
    public array $softBounceEmails = [];

    /** @var MailerMessage[] */
    public array $sentMessages = [];

    public function send(MailerMessage $message): MailerResult
    {
        if (in_array($message->toEmail, $this->hardBounceEmails, true)) {
            return MailerResult::failure('550 No such user here', 'hard');
        }

        if (in_array($message->toEmail, $this->softBounceEmails, true)) {
            return MailerResult::failure('450 Mailbox temporarily unavailable', 'soft');
        }

        $this->sentMessages[] = $message;

        return MailerResult::success();
    }
}
