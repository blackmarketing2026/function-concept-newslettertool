<?php

declare(strict_types=1);

namespace App\Queue;

final class MailerMessage
{
    public function __construct(
        public readonly string $toEmail,
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly ?string $replyTo,
        public readonly string $subject,
        public readonly string $bodyHtml,
        public readonly string $bodyText,
        public readonly string $trackingId,
        public readonly string $unsubscribeToken,
    ) {
    }
}
