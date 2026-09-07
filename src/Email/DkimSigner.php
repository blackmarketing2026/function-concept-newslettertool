<?php

declare(strict_types=1);

namespace App\Email;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * Konfiguriert DKIM-Signierung fuer PHPMailer.
 *
 * Nutzt PHPMailer's eingebautes DKIM-Handling (DKIM_domain, DKIM_private,
 * DKIM_selector, DKIM_identity) statt eigener Signatur-Implementierung -
 * das ist robuster und wird von PHPMailer aktiv gepflegt.
 *
 * Setup: openssl genrsa -out storage/dkim/private.key 2048
 *        openssl rsa -in storage/dkim/private.key -pubout -out storage/dkim/public.key
 *        -> Public Key als TXT-Record im DNS veroeffentlichen:
 *           <selector>._domainkey.<domain> IN TXT "v=DKIM1; k=rsa; p=<public-key-ohne-header>"
 */
final class DkimSigner
{
    public function __construct(
        private readonly bool $enabled,
        private readonly string $domain,
        private readonly string $selector,
        private readonly string $privateKeyPath,
    ) {
    }

    public static function fromConfig(array $appConfig): self
    {
        $dkim = $appConfig['dkim'] ?? [];

        return new self(
            enabled: (bool) ($dkim['enabled'] ?? false),
            domain: (string) ($dkim['domain'] ?? ''),
            selector: (string) ($dkim['selector'] ?? 'newsletter'),
            privateKeyPath: (string) ($dkim['private_key_path'] ?? ''),
        );
    }

    public function isConfigured(): bool
    {
        return $this->enabled
            && $this->domain !== ''
            && $this->privateKeyPath !== ''
            && file_exists($this->resolveKeyPath());
    }

    public function apply(PHPMailer $mailer): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $keyPath = $this->resolveKeyPath();
        $keyContent = file_get_contents($keyPath);
        if ($keyContent === false) {
            throw new RuntimeException("DKIM Private Key konnte nicht gelesen werden: {$keyPath}");
        }

        $mailer->DKIM_domain = $this->domain;
        $mailer->DKIM_selector = $this->selector;
        $mailer->DKIM_private_string = $keyContent;
        $mailer->DKIM_identity = $mailer->From;
    }

    /**
     * Generiert ein neues 2048-bit RSA Keypair fuer DKIM (falls noch keins existiert).
     *
     * @return array{private_key: string, public_key_dns: string}
     */
    public function generateKeyPair(): array
    {
        $keyPath = $this->resolveKeyPath();
        $dir = dirname($keyPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($res === false) {
            throw new RuntimeException('OpenSSL Key-Generierung fehlgeschlagen. Ist die openssl-Extension aktiv?');
        }

        openssl_pkey_export($res, $privateKeyPem);
        $details = openssl_pkey_get_details($res);
        $publicKeyPem = $details['key'];

        file_put_contents($keyPath, $privateKeyPem);
        chmod($keyPath, 0600);

        // Public Key fuer DNS TXT Record aufbereiten (Header/Footer entfernen, Zeilenumbrueche raus)
        $publicKeyDns = preg_replace('/-----[^-]+-----|\r|\n/', '', $publicKeyPem) ?? '';

        return [
            'private_key' => $privateKeyPem,
            'public_key_dns' => "v=DKIM1; k=rsa; p={$publicKeyDns}",
        ];
    }

    private function resolveKeyPath(): string
    {
        if (str_starts_with($this->privateKeyPath, '/') || preg_match('/^[A-Za-z]:\\\\/', $this->privateKeyPath)) {
            return $this->privateKeyPath;
        }

        return __DIR__ . '/../../' . ltrim($this->privateKeyPath, '/');
    }
}
