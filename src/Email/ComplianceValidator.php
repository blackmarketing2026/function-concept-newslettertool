<?php

declare(strict_types=1);

namespace App\Email;

/**
 * Pre-Send Content-Validierung laut DEVELOPMENT_1.md:
 * - Unsubscribe-Link vorhanden?
 * - Keine Spam-Keywords?
 * - Footer mit Impressum & Datenschutz vorhanden?
 * - From/Reply-To korrekt gesetzt?
 */
final class ComplianceValidator
{
    /** @var string[] */
    private const array SPAM_KEYWORDS = [
        'viagra', 'casino', 'lottery winner', 'nigerian prince', 'click here now',
        'free money', 'act now', 'wire transfer', 'cialis', 'weight loss miracle',
        'earn money fast', 'work from home guaranteed', 'risk free', '100% free',
    ];

    /**
     * @return array{valid: bool, errors: string[]}
     */
    public function validate(
        string $bodyHtml,
        string $fromEmail,
        ?string $replyTo,
        string $impressumUrl,
        string $datenschutzUrl,
    ): array {
        $errors = [];

        if (! $this->hasUnsubscribeLink($bodyHtml)) {
            $errors[] = 'Kein Unsubscribe-Link im Email-Body gefunden.';
        }

        if (! filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "From-Adresse ist ungueltig: {$fromEmail}";
        }

        if ($replyTo !== null && $replyTo !== '' && ! filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Reply-To Adresse ist ungueltig: {$replyTo}";
        }

        $spamHits = $this->findSpamKeywords($bodyHtml);
        if ($spamHits !== []) {
            $errors[] = 'Moegliche Spam-Keywords gefunden: ' . implode(', ', $spamHits);
        }

        if ($impressumUrl === '') {
            $errors[] = 'Kein Impressum in den Einstellungen hinterlegt (rechtlich bindend).';
        }

        if ($datenschutzUrl === '') {
            $errors[] = 'Keine Datenschutzerklaerung in den Einstellungen hinterlegt.';
        }

        if (! $this->containsUrl($bodyHtml, $impressumUrl) && $impressumUrl !== '') {
            $errors[] = 'Impressum-Link fehlt im Footer der Email.';
        }

        if (! $this->containsUrl($bodyHtml, $datenschutzUrl) && $datenschutzUrl !== '') {
            $errors[] = 'Datenschutz-Link fehlt im Footer der Email.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    public function isRecipientSuppressed(\PDO $pdo, string $email): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM suppression_list WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);

        return (bool) $stmt->fetchColumn();
    }

    private function hasUnsubscribeLink(string $html): bool
    {
        return (bool) preg_match('/unsubscribe/i', $html);
    }

    /** @return string[] */
    private function findSpamKeywords(string $html): array
    {
        $plain = strtolower(strip_tags($html));
        $hits = [];
        foreach (self::SPAM_KEYWORDS as $keyword) {
            if (str_contains($plain, $keyword)) {
                $hits[] = $keyword;
            }
        }

        return $hits;
    }

    private function containsUrl(string $html, string $url): bool
    {
        if ($url === '') {
            return true;
        }

        return str_contains($html, $url);
    }
}
