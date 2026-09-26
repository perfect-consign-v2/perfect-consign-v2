<?php

declare(strict_types=1);

use PerfectApp\Container\Container;
use PerfectApp\Exception\RouteNotFoundException;
use PerfectApp\Logger\FileLogger;
use PerfectApp\Routing\Router;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\RegisterFeatures;
use PerfectConsign\Shared\Database\AppDatabase;
use PerfectConsign\Shared\Http\ErrorHandling;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use Psr\Log\LoggerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

loadEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

$debug = in_array(getenv('APP_ENV'), ['local', 'development', 'dev'], true) || getenv('APP_DEBUG') === 'true';
if ($debug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}
error_reporting(E_ALL);

$logDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log';
if (!is_dir($logDirectory) && !mkdir($logDirectory, 0755, true) && !is_dir($logDirectory)) {
    http_response_code(500);
    echo 'The log directory could not be created.';
    exit;
}
$logFile = $logDirectory . DIRECTORY_SEPARATOR . 'app.log';
$logger = new FileLogger($logFile);
ErrorHandling::register($logger);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'path' => '/',
]);

try {
    $request = requestFromGlobals();
    $pdo = (new AppDatabase())->connect(databaseConfig());
    $container = new Container();
    $container->set(PDO::class, $pdo);
    $container->set(LoggerInterface::class, $logger);
    $container->set(Session::class, new Session());
    $container->set(Request::class, $request);
    $container->set(View::class, new View(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src'));
    $container->set(MallApp::class, $container->get(MallApp::class));
    $router = new Router($container);
    $router->setNotFoundHandler(static function () use ($container): void {
        $container->get(MallApp::class)->notFound();
    });
    RegisterFeatures::register($router, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature');
    $router->dispatch($request->path, $request->method);
} catch (ResponseSent) {
    return;
} catch (RouteNotFoundException) {
    http_response_code(404);
    echo 'Not found';
} catch (Throwable $error) {
    $logger->error($error->getMessage(), ['file' => $error->getFile(), 'line' => $error->getLine()]);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo $debug ? $error->getMessage() : 'The page could not be shown.';
}

function loadEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        if ($line === '' || str_starts_with(trim($line), '#')) {
            continue;
        }
        $separator = strpos($line, '=');
        if ($separator === false) {
            continue;
        }
        $key = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));
        if ($key === '' || getenv($key) !== false) {
            continue;
        }
        putenv($key . '=' . $value);
    }
}

function requestFromGlobals(): Request
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!is_string($method) || $method === '') {
        $method = 'GET';
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    if (!is_string($uri) || $uri === '') {
        $uri = '/';
    }
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = '/';
    }
    if ($path !== '/') {
        $path = rtrim($path, '/');
    }

    return new Request($method, $path, $_GET, $_POST);
}

/**
 * @return array<string, mixed>
 */
function databaseConfig(): array
{
    $host = getenv('STALL_DB_HOST');
    $port = getenv('STALL_DB_PORT');
    $name = getenv('STALL_DB_NAME');
    $password = getenv('STALL_APP_PASSWORD');
    if (!is_string($host) || $host === '') {
        $host = '127.0.0.1';
    }
    if (!is_string($port) || $port === '') {
        $port = '5436';
    }
    if (!is_string($name) || $name === '') {
        $name = 'consign';
    }
    if (!is_string($password) || $password === '') {
        throw new RuntimeException('STALL_APP_PASSWORD is required');
    }

    return [
        'host' => $host,
        'port' => $port,
        'dbname' => $name,
        'username' => 'stall_app',
        'password' => $password,
    ];
}
