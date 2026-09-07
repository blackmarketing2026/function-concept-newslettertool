<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Email\ComplianceValidator;
use PHPUnit\Framework\TestCase;

final class ComplianceValidatorTest extends TestCase
{
    private ComplianceValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ComplianceValidator();
    }

    public function test_valid_email_passes_all_checks(): void
    {
        $html = '<p>Hello {{first_name}}</p><a href="https://example.com/unsubscribe">Abmelden</a>'
            . '<a href="https://example.com/impressum">Impressum</a>'
            . '<a href="https://example.com/datenschutz">Datenschutz</a>';

        $result = $this->validator->validate(
            $html,
            'newsletter@example.com',
            'reply@example.com',
            'https://example.com/impressum',
            'https://example.com/datenschutz',
        );

        $this->assertTrue($result['valid'], implode(', ', $result['errors']));
        $this->assertSame([], $result['errors']);
    }

    public function test_missing_unsubscribe_link_fails(): void
    {
        $result = $this->validator->validate(
            '<p>Hello</p>',
            'newsletter@example.com',
            null,
            'https://example.com/impressum',
            'https://example.com/datenschutz',
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Unsubscribe', implode(' ', $result['errors']));
    }

    public function test_invalid_from_email_fails(): void
    {
        $result = $this->validator->validate(
            '<p>unsubscribe link here</p>',
            'not-an-email',
            null,
            '',
            '',
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('From-Adresse', implode(' ', $result['errors']));
    }

    public function test_spam_keywords_are_detected(): void
    {
        $result = $this->validator->validate(
            '<p>Buy viagra now! unsubscribe</p>',
            'newsletter@example.com',
            null,
            '',
            '',
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('viagra', implode(' ', $result['errors']));
    }

    public function test_missing_impressum_and_datenschutz_settings_fail(): void
    {
        $result = $this->validator->validate(
            '<p>unsubscribe</p>',
            'newsletter@example.com',
            null,
            '',
            '',
        );

        $this->assertFalse($result['valid']);
        $joined = implode(' ', $result['errors']);
        $this->assertStringContainsString('Impressum', $joined);
        $this->assertStringContainsString('Datenschutz', $joined);
    }
}
