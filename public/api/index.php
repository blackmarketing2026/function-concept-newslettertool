<?php

declare(strict_types=1);

/**
 * public/api/index.php
 *
 * Front-Controller fuer die REST-API. Auf Apache/Shared Hosting per
 * .htaccess (mod_rewrite) hierher geroutet, siehe public/api/.htaccess.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Api\AuthMiddleware;
use App\Api\CampaignController;
use App\Api\ContactController;
use App\Api\Router;
use App\Api\TemplateController;
use App\Support\Bootstrap;

$app = Bootstrap::boot();
$appConfig = $app->appConfig();

// Health-Endpoint ausnehmen (fuer Uptime-Monitoring ohne Token)
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/api/health') {
    (new AuthMiddleware($appConfig['api']['token']))->requireAuth();
}

$pdo = $app->pdo();
$tagManager = $app->tagManager();
$auditLogger = $app->auditLogger();

$contactController = new ContactController($pdo, $tagManager, $auditLogger);
$campaignController = new CampaignController($pdo, $tagManager, $app->complianceValidator(), $auditLogger, $appConfig);
$templateController = new TemplateController($pdo, $auditLogger);

$router = new Router();

$router->get('/api/health', static function (): void {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'time' => date(DATE_ATOM)]);
});

// Contacts
$router->get('/api/contacts', [$contactController, 'index']);
$router->get('/api/contacts/export', [$contactController, 'export']);
$router->post('/api/contacts/import', [$contactController, 'import']);
$router->get('/api/contacts/{id}', [$contactController, 'show']);
$router->post('/api/contacts', [$contactController, 'store']);
$router->put('/api/contacts/{id}', [$contactController, 'update']);
$router->delete('/api/contacts/{id}', [$contactController, 'destroy']);
$router->post('/api/contacts/{id}/tags', [$contactController, 'addTag']);
$router->delete('/api/contacts/{id}/tags/{tag}', [$contactController, 'removeTag']);

// Campaigns
$router->get('/api/campaigns', [$campaignController, 'index']);
$router->post('/api/campaigns', [$campaignController, 'store']);
$router->get('/api/campaigns/{id}', [$campaignController, 'show']);
$router->put('/api/campaigns/{id}', [$campaignController, 'update']);
$router->get('/api/campaigns/{id}/audience-preview', [$campaignController, 'previewAudience']);
$router->post('/api/campaigns/{id}/send', [$campaignController, 'send']);
$router->get('/api/campaigns/{id}/stats', [$campaignController, 'stats']);

// Templates
$router->get('/api/templates', [$templateController, 'index']);
$router->post('/api/templates', [$templateController, 'store']);
$router->get('/api/templates/{id}', [$templateController, 'show']);
$router->put('/api/templates/{id}', [$templateController, 'update']);
$router->delete('/api/templates/{id}', [$templateController, 'destroy']);
$router->get('/api/templates/{id}/preview', [$templateController, 'preview']);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] ?? '/');
