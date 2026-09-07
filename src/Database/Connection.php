<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

/**
 * Einfache PDO-Singleton-Verbindung. Auf Shared Hosting bewusst schlank
 * gehalten (kein schweres ORM), um Ressourcen zu sparen.
 */
final class Connection
{
    private static ?PDO $instance = null;

    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$instance = null; // erzwingt Neuverbindung mit neuer Config
    }

    public static function get(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $config = self::$config ?? require __DIR__ . '/../../config/database.php';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        self::$instance = new PDO($dsn, $config['username'], $config['password'], $config['options']);

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
