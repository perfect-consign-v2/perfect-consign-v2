<?php

declare(strict_types=1);

use PerfectApp\Container\Container;
use PerfectApp\Logger\FileLogger;
use PerfectApp\Routing\Router;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\RegisterFeatures;
use PerfectConsign\Feature\Inventory\PhotoFacts;
use PerfectConsign\Feature\Inventory\ServerPhotoFacts;
use PerfectConsign\Shared\Database\AppDatabase;
use PerfectConsign\Shared\Http\AssetServer;
use PerfectConsign\Shared\Http\DatabaseConfig;
use PerfectConsign\Shared\Http\ErrorHandling;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestCoverage;
use PerfectConsign\Shared\Http\RequestFactory;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\TlsSignal;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use Psr\Log\LoggerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bin/load-env.php';
loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

$coverageDirectory = getenv('PC_COVERAGE');
$coverageStarted = RequestCoverage::enable($coverageDirectory, getenv('APP_ENV'));
register_shutdown_function(static function () use ($coverageStarted, $coverageDirectory): void {
    RequestCoverage::flush($coverageStarted, $coverageDirectory);
});

$debug = in_array(getenv('APP_ENV'), ['local', 'development', 'dev'], true) || getenv('APP_DEBUG') === 'true';
ErrorHandling::applyDisplayErrors($debug);
error_reporting(E_ALL);

$logDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log';
if (!is_dir($logDirectory) && !mkdir($logDirectory, 0700, true) && !is_dir($logDirectory)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo 'The log directory could not be created.';
    exit;
}
$logFile = $logDirectory . DIRECTORY_SEPARATOR . 'app.log';
$logger = new FileLogger($logFile);
$operatorNotices = new PlatformOperatorNotice($logger);
ErrorHandling::register($logger, $operatorNotices);

$trustedProxies = getenv('STALL_TRUSTED_PROXIES');
$trustedProxies = is_string($trustedProxies) ? $trustedProxies : '';
$https = TlsSignal::secure($_SERVER, $trustedProxies);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'path' => '/',
    'secure' => $https,
]);
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header(
    "Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; "
    . "frame-ancestors 'none'; object-src 'none'; script-src 'self'; style-src 'self'; "
    . "img-src 'self' data:; font-src 'self'; connect-src 'self'; worker-src 'self'"
);
if ($https) {
    header('Strict-Transport-Security: max-age=86400');
}

$requestId = bin2hex(random_bytes(8));
$request = null;
try {
    ob_start();
    $request = RequestFactory::fromGlobals();
    if (str_starts_with($request->path, '/assets/')) {
        AssetServer::send($request->path);
        exit;
    }
    $pdo = (new AppDatabase())->connect(DatabaseConfig::fromEnv());
    $container = new Container();
    $container->set(PDO::class, $pdo);
    $container->set(LoggerInterface::class, $logger);
    $container->set(PhotoFacts::class, ServerPhotoFacts::class);
    $session = null;
    $container->set(Session::class, static function () use (&$session): Session {
        return $session ??= new Session();
    });
    $container->set(Request::class, $request);
    $container->set(View::class, new View(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src'));
    $container->set(MallApp::class, $container->get(MallApp::class));
    $router = new Router($container);
    $router->setNotFoundHandler(static function () use ($container): void {
        $container->get(MallApp::class)->notFound(MallApp::MISS_ROUTE);
    });
    RegisterFeatures::register($router, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature');
    $router->dispatch($request->path, $request->method);
} catch (ResponseSent) {
    return;
} catch (Throwable $error) {
    $fault = [
        'file' => $error->getFile(),
        'line' => $error->getLine(),
        'trace' => $error->getTraceAsString(),
        'request_id' => $requestId,
        'method' => $request?->method ?? ($_SERVER['REQUEST_METHOD'] ?? ''),
        'path' => $request?->path ?? ($_SERVER['REQUEST_URI'] ?? ''),
        'message' => $error->getMessage(),
    ];
    $logger->error($error->getMessage(), $fault);
    $operatorNotices->serverFailed('uncaught exception', $fault);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo $debug ? $error->getMessage() : 'The page could not be shown.';
} finally {
    RequestCoverage::flush($coverageStarted, $coverageDirectory);
}
