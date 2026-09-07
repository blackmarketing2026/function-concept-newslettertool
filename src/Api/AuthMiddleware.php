<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Bearer-Token Validierung fuer die REST-API.
 */
final class AuthMiddleware
{
    public function __construct(private readonly string $validToken)
    {
    }

    public function check(): bool
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (! str_starts_with($header, 'Bearer ')) {
            return false;
        }

        $token = substr($header, 7);

        return $this->validToken !== '' && hash_equals($this->validToken, $token);
    }

    public function requireAuth(): void
    {
        if (! $this->check()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized. Bearer-Token in Authorization-Header erforderlich.']);
            exit;
        }
    }
}
