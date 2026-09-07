<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Api\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function test_matches_static_route(): void
    {
        $router = new Router();
        $called = false;
        $router->get('/api/health', function () use (&$called): void {
            $called = true;
        });

        ob_start();
        $router->dispatch('GET', '/api/health');
        ob_end_clean();

        $this->assertTrue($called);
    }

    public function test_matches_route_with_parameter(): void
    {
        $router = new Router();
        $capturedParams = null;
        $router->get('/api/contacts/{id}', function (array $params) use (&$capturedParams): void {
            $capturedParams = $params;
        });

        ob_start();
        $router->dispatch('GET', '/api/contacts/42');
        ob_end_clean();

        $this->assertSame('42', $capturedParams['id']);
    }

    public function test_matches_route_with_multiple_parameters(): void
    {
        $router = new Router();
        $capturedParams = null;
        $router->delete('/api/contacts/{id}/tags/{tag}', function (array $params) use (&$capturedParams): void {
            $capturedParams = $params;
        });

        ob_start();
        $router->dispatch('DELETE', '/api/contacts/7/tags/segment%3Avip');
        ob_end_clean();

        $this->assertSame('7', $capturedParams['id']);
        $this->assertSame('segment%3Avip', $capturedParams['tag']);
    }

    public function test_returns_404_for_unknown_route(): void
    {
        $router = new Router();
        $router->get('/api/health', function (): void {});

        ob_start();
        $router->dispatch('GET', '/api/does-not-exist');
        $output = ob_get_clean();

        $this->assertStringContainsString('Not Found', $output);
    }

    public function test_wrong_method_does_not_match(): void
    {
        $router = new Router();
        $called = false;
        $router->post('/api/campaigns', function () use (&$called): void {
            $called = true;
        });

        ob_start();
        $router->dispatch('GET', '/api/campaigns');
        ob_end_clean();

        $this->assertFalse($called);
    }
}
