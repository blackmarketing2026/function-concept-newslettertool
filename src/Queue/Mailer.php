<?php

declare(strict_types=1);

namespace App\Queue;

use App\Email\DkimSigner;
use App\Email\HeaderManager;
use Monolog\Logger;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;

/**
 * Duenner Wrapper um PHPMailer: SMTP-Verbindung, DKIM/Header-Management,
 * strukturiertes Logging pro Email. Wirft niemals - liefert stattdessen
 * ein Ergebnis-Objekt, damit der QueueWorker Fehler sauber verarbeiten kann.
 */
final class Mailer implements MailerInterface
{
    public function __construct(
        private readonly array $smtpConfig,
        private readonly DkimSigner $dkimSigner,
        private readonly HeaderManager $headerManager,
        private readonly Logger $logger,
    ) {
    }

    public function send(MailerMessage $message): MailerResult
    {
        $mailer = new PHPMailer(true);

        try {
            $this->configureTransport($mailer);

            $mailer->setFrom($message->fromEmail, $message->fromName);
            $mailer->addAddress($message->toEmail);

            if ($message->replyTo !== null && $message->replyTo !== '') {
                $mailer->addReplyTo($message->replyTo);
            }

            $mailer->Subject = $message->subject;
            $mailer->isHTML(true);
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->Body = $message->bodyHtml;
            $mailer->AltBody = $message->bodyText !== '' ? $message->bodyText : strip_tags($message->bodyHtml);

            $this->headerManager->apply($mailer, $message->unsubscribeToken);
            $this->dkimSigner->apply($mailer);

            $mailer->Timeout = $this->smtpConfig['timeout'] ?? 30;

            $mailer->send();

            $this->logger->info('Email sent', [
                'to' => $message->toEmail,
                'tracking_id' => $message->trackingId,
            ]);

            return MailerResult::success();
        } catch (PHPMailerException|Throwable $e) {
            $this->logger->error('Email send failed', [
                'to' => $message->toEmail,
                'tracking_id' => $message->trackingId,
                'error' => $e->getMessage(),
            ]);

            return MailerResult::failure($e->getMessage(), $this->classifyBounce($e->getMessage()));
        }
    }

    private function configureTransport(PHPMailer $mailer): void
    {
        $mailer->isSMTP();
        $mailer->Host = $this->smtpConfig['host'];
        $mailer->Port = $this->smtpConfig['port'];
        $mailer->SMTPAuth = ($this->smtpConfig['username'] ?? '') !== '';
        $mailer->Username = $this->smtpConfig['username'] ?? '';
        $mailer->Password = $this->smtpConfig['password'] ?? '';

        $encryption = $this->smtpConfig['encryption'] ?? 'tls';
        if ($encryption === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mailer->SMTPAutoTLS = false;
        }
    }

    /**
     * Grobe Klassifizierung: soft (temporaer) vs. hard (permanent) Bounce
     * anhand der SMTP-Fehlermeldung. Fuer produktive Nutzung sollte
     * zusaetzlich Feedback-Loop / VERP-Bounce-Parsing eingesetzt werden
     * (siehe src/Email/BounceHandler.php).
     */
    private function classifyBounce(string $errorMessage): ?string
    {
        $message = strtolower($errorMessage);

        $hardIndicators = ['user unknown', 'does not exist', 'no such user', 'mailbox unavailable', '550', '551', '553'];
        foreach ($hardIndicators as $indicator) {
            if (str_contains($message, $indicator)) {
                return 'hard';
            }
        }

        $softIndicators = ['mailbox full', 'quota exceeded', 'timeout', 'temporarily', 'try again', '421', '450', '451', '452'];
        foreach ($softIndicators as $indicator) {
            if (str_contains($message, $indicator)) {
                return 'soft';
            }
        }

        return null;
    }
}
