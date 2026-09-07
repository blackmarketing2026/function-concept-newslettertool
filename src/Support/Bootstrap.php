<?php

declare(strict_types=1);

namespace App\Support;

use App\Audit\AuditLogger;
use App\Database\Connection;
use App\Email\BounceHandler;
use App\Email\ComplianceValidator;
use App\Email\DkimSigner;
use App\Email\HeaderManager;
use App\Gdpr\ConsentManager;
use App\Queue\Mailer;
use App\Queue\QueueWorker;
use App\Tags\TagManager;
use Dotenv\Dotenv;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PDO;

/**
 * Minimaler Service-Container. Bewusst kein schweres DI-Framework -
 * auf Shared Hosting zaehlt jede Millisekunde Bootstrap-Zeit.
 */
final class Bootstrap
{
    private static ?self $instance = null;

    private PDO $pdo;
    private Logger $logger;
    private array $appConfig;
    private array $queueConfig;

    private function __construct(private readonly string $basePath)
    {
        $this->loadEnv();

        $this->appConfig = require $this->basePath . '/config/app.php';
        $this->queueConfig = require $this->basePath . '/config/email-queue.php';
        $dbConfig = require $this->basePath . '/config/database.php';

        date_default_timezone_set($this->appConfig['timezone']);

        Connection::configure($dbConfig);
        $this->pdo = Connection::get();

        $this->logger = new Logger('email-marketing');
        $this->logger->pushHandler(new StreamHandler($this->basePath . '/logs/app.log', Logger::INFO));
    }

    public static function boot(?string $basePath = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($basePath ?? dirname(__DIR__, 2));
        }

        return self::$instance;
    }

    private function loadEnv(): void
    {
        if (file_exists($this->basePath . '/.env')) {
            Dotenv::createImmutable($this->basePath)->load();
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function appConfig(): array
    {
        return $this->appConfig;
    }

    public function queueConfig(): array
    {
        return $this->queueConfig;
    }

    public function auditLogger(): AuditLogger
    {
        return new AuditLogger($this->pdo);
    }

    public function tagManager(): TagManager
    {
        return new TagManager($this->pdo, $this->auditLogger());
    }

    public function consentManager(): ConsentManager
    {
        return new ConsentManager($this->pdo, $this->auditLogger());
    }

    public function complianceValidator(): ComplianceValidator
    {
        return new ComplianceValidator();
    }

    public function bounceHandler(): BounceHandler
    {
        return new BounceHandler($this->pdo, $this->tagManager(), $this->auditLogger());
    }

    public function mailer(): Mailer
    {
        $mail = $this->appConfig['mail'];

        $smtpConfig = [
            'host' => $mail['host'],
            'port' => $mail['port'],
            'encryption' => $mail['encryption'],
            'username' => $mail['username'],
            'password' => $mail['password'],
            'timeout' => $this->queueConfig['send_timeout'],
        ];

        return new Mailer(
            $smtpConfig,
            DkimSigner::fromConfig($this->appConfig),
            new HeaderManager($this->appConfig['url'], $mail['bounce_address']),
            $this->logger,
        );
    }

    public function queueWorker(): QueueWorker
    {
        return new QueueWorker(
            $this->pdo,
            $this->mailer(),
            $this->complianceValidator(),
            $this->auditLogger(),
            $this->logger,
            $this->queueConfig,
        );
    }

    public function basePath(string $suffix = ''): string
    {
        return $this->basePath . ($suffix !== '' ? '/' . ltrim($suffix, '/') : '');
    }
}
