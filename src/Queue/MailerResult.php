<?php

declare(strict_types=1);

namespace App\Queue;

final class MailerResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $errorMessage = null,
        public readonly ?string $bounceType = null, // 'hard' | 'soft' | null
    ) {
    }

    public static function success(): self
    {
        return new self(true);
    }

    public static function failure(string $errorMessage, ?string $bounceType = null): self
    {
        return new self(false, $errorMessage, $bounceType);
    }
}
