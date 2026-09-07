<?php

declare(strict_types=1);

namespace App\Email;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Setzt die laut DEVELOPMENT_1.md verpflichtenden Header auf jeder Email:
 * List-Unsubscribe (RFC 8058 One-Click), X-Mailer, X-Priority, Return-Path.
 */
final class HeaderManager
{
    public function __construct(
        private readonly string $appUrl,
        private readonly string $bounceAddress,
    ) {
    }

    public function apply(PHPMailer $mailer, string $unsubscribeToken): void
    {
        $unsubscribeUrl = rtrim($this->appUrl, '/') . '/unsubscribe.php?token=' . urlencode($unsubscribeToken);

        // One-Click Unsubscribe (RFC 8058) - Pflicht seit 2024 fuer Gmail/Yahoo Bulk Sender
        $mailer->addCustomHeader('List-Unsubscribe', "<{$unsubscribeUrl}>");
        $mailer->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $mailer->XMailer = 'Email-Marketing-System/1.0';
        $mailer->Priority = 3; // Normal

        if ($this->bounceAddress !== '') {
            $mailer->Sender = $this->bounceAddress; // Return-Path
        }
    }

    public function unsubscribeUrl(string $token): string
    {
        return rtrim($this->appUrl, '/') . '/unsubscribe.php?token=' . urlencode($token);
    }
}
