<?php

declare(strict_types=1);

namespace App\Queue;

interface MailerInterface
{
    public function send(MailerMessage $message): MailerResult;
}
