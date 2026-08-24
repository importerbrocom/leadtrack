<?php

/**
 * API front controller.
 *
 * On cPanel this file lives at public_html/api/index.php and the .htaccess
 * beside it rewrites /api/<anything> to here.
 */

declare(strict_types=1);

// Works whether public_html/ is part of the project or a separate cPanel
// document root. See bootstrap-locator.php.
require (require dirname(__DIR__) . '/bootstrap-locator.php');

use App\Controllers\AuthController;
use App\Controllers\CallController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentController;
use App\Controllers\FollowUpController;
use App\Controllers\FormTemplateController;
use App\Controllers\LeadController;
use App\Controllers\ProjectController;
use App\Controllers\UserController;
use App\Core\ApiException;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

// ---------------------------------------------------------------- CORS
$origins = Config::get('cors.allowed_origins', ['*']);
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array('*', $origins, true)) {
    header('Access-Control-Allow-Origin: *');
} elseif ($origin !== '' && in_array($origin, $origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With');
header('Access-Control-Max-Age: 86400');

$request = new Request();
$router  = new Router();

// ---------------------------------------------------------------- routes
$router->get('/', function () {
    Response::ok([
        'name'    => Config::get('app.name'),
        'version' => '1.0.0',
        'status'  => 'ok',
        'time'    => date('c'),
    ]);
});

$router->get('/health', function () {
    \App\Core\Database::scalar('SELECT 1');
    Response::ok(['database' => 'connected']);
});

// auth
$auth = new AuthController();
$router->post('/auth/login',           [$auth, 'login']);
$router->get('/auth/me',               [$auth, 'me']);
$router->post('/auth/logout',          [$auth, 'logout']);
$router->post('/auth/device',          [$auth, 'updateDevice']);
$router->post('/auth/change-password', [$auth, 'changePassword']);

// users (admin creates partners, partners create telecallers)
$users = new UserController();
$router->get('/users',             [$users, 'index']);
$router->get('/users/assignable', [$users, 'assignable']);
$router->get('/users/{id}',        [$users, 'show']);
$router->post('/users',            [$users, 'store']);
$router->patch('/users/{id}',      [$users, 'update']);

// leads
$leads = new LeadController();
$router->get('/leads',                 [$leads, 'index']);
$router->get('/leads/lookup',          [$leads, 'lookup']);
$router->post('/leads',                [$leads, 'store']);
$router->post('/leads/import',         [$leads, 'import']);
$router->post('/leads/bulk-assign',    [$leads, 'bulkAssign']);
$router->get('/leads/{id}',            [$leads, 'show']);
$router->patch('/leads/{id}',          [$leads, 'update']);
$router->post('/leads/{id}/status',    [$leads, 'updateStatus']);
$router->post('/leads/{id}/assign',    [$leads, 'assign']);
$router->delete('/leads/{id}',         [$leads, 'destroy']);

// automatic call tracking
$calls = new CallController();
$router->post('/calls/sync',  [$calls, 'sync']);
$router->get('/calls',        [$calls, 'index']);
$router->get('/calls/stats',  [$calls, 'stats']);
$router->patch('/calls/{id}', [$calls, 'update']);

// follow-ups / callbacks
$followUps = new FollowUpController();
$router->get('/follow-ups',       [$followUps, 'index']);
$router->get('/follow-ups/due',   [$followUps, 'due']);
$router->post('/follow-ups',      [$followUps, 'store']);
$router->patch('/follow-ups/{id}', [$followUps, 'update']);

// projects (converted leads)
$projects = new ProjectController();
$router->post('/leads/{id}/convert',    [$projects, 'convert']);
$router->get('/projects',               [$projects, 'index']);
$router->get('/projects/{id}',          [$projects, 'show']);
$router->patch('/projects/{id}',        [$projects, 'update']);
$router->post('/projects/{id}/status',  [$projects, 'updateStatus']);

// blank form templates (admin uploads -> field team downloads)
$templates = new FormTemplateController();
$router->get('/form-templates',                 [$templates, 'index']);
$router->post('/form-templates',                [$templates, 'store']);
$router->get('/form-templates/{id}/download',   [$templates, 'download']);
$router->patch('/form-templates/{id}',          [$templates, 'update']);
$router->delete('/form-templates/{id}',         [$templates, 'destroy']);

// collected documents (field team uploads -> admin downloads/verifies)
$documents = new DocumentController();
$router->get('/documents',                  [$documents, 'index']);
$router->post('/documents',                 [$documents, 'store']);
$router->get('/document-types',             [$documents, 'types']);
$router->get('/documents/{id}/download',    [$documents, 'download']);
$router->post('/documents/{id}/verify',     [$documents, 'verify']);
$router->delete('/documents/{id}',          [$documents, 'destroy']);

// dashboard, lookups, notifications
$dashboard = new DashboardController();
$router->get('/dashboard',           [$dashboard, 'summary']);
$router->get('/lookups',             [$dashboard, 'lookups']);
$router->get('/notifications',       [$dashboard, 'notifications']);
$router->post('/notifications/read', [$dashboard, 'markNotificationsRead']);

// ---------------------------------------------------------------- dispatch
try {
    $router->dispatch($request);
} catch (ApiException $e) {
    Response::error($e->getMessage(), $e->statusCode(), $e->errors());
} catch (\PDOException $e) {
    error_log('[DB] ' . $e->getMessage());
    Response::error(
        Config::get('app.debug') ? 'Database error: ' . $e->getMessage() : 'A database error occurred',
        500
    );
} catch (\Throwable $e) {
    error_log('[ERR] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::error(
        Config::get('app.debug')
            ? $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
            : 'Something went wrong. Please try again.',
        500
    );
}
