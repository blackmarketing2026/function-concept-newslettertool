<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Email\DkimSigner;
use PHPUnit\Framework\TestCase;

final class DkimSignerTest extends TestCase
{
    private string $tmpKeyPath;

    protected function setUp(): void
    {
        $this->tmpKeyPath = sys_get_temp_dir() . '/dkim-test-' . uniqid('', true) . '.key';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpKeyPath)) {
            unlink($this->tmpKeyPath);
        }
    }

    public function test_not_configured_when_disabled(): void
    {
        $signer = new DkimSigner(false, 'example.com', 'newsletter', $this->tmpKeyPath);
        $this->assertFalse($signer->isConfigured());
    }

    public function test_not_configured_when_key_missing(): void
    {
        $signer = new DkimSigner(true, 'example.com', 'newsletter', $this->tmpKeyPath);
        $this->assertFalse($signer->isConfigured());
    }

    public function test_generate_key_pair_creates_valid_rsa_key_and_dns_record(): void
    {
        $signer = new DkimSigner(true, 'example.com', 'newsletter', $this->tmpKeyPath);

        $result = $signer->generateKeyPair();

        $this->assertFileExists($this->tmpKeyPath);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $result['private_key']);
        $this->assertStringStartsWith('v=DKIM1; k=rsa; p=', $result['public_key_dns']);
        $this->assertTrue($signer->isConfigured());

        // Der generierte private Key muss von OpenSSL geladen werden koennen.
        $privateKeyResource = openssl_pkey_get_private(file_get_contents($this->tmpKeyPath));
        $this->assertNotFalse($privateKeyResource);
    }
}
