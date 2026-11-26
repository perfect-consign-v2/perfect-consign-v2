<?php

declare(strict_types=1);

use PerfectApp\Container\Container;
use PerfectApp\Logger\FileLogger;
use PerfectApp\Routing\Router;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\SignInDevice;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\RegisterFeatures;
use PerfectConsign\Bootstrap\SignedInVisitor;
use PerfectConsign\Feature\Admin\AdminPath;
use PerfectConsign\Feature\Admin\AdminRouter;
use PerfectConsign\Feature\Inventory\PhotoFacts;
use PerfectConsign\Feature\Inventory\ServerPhotoFacts;
use PerfectConsign\Shared\Database\AppDatabase;
use PerfectConsign\Shared\Http\AssetServer;
use PerfectConsign\Shared\Http\DatabaseConfig;
use PerfectConsign\Shared\Http\EdgeFile;
use PerfectConsign\Shared\Http\EmptyNotFound;
use PerfectConsign\Shared\Http\ErrorHandling;
use PerfectConsign\Shared\Http\ProbeBanStore;
use PerfectConsign\Shared\Http\ProbeShield;
use PerfectConsign\Shared\Http\QuietMiss;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestCoverage;
use PerfectConsign\Shared\Http\RequestId;
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

$debug = ErrorHandling::exposeDetails(getenv('APP_ENV'), getenv('APP_DEBUG'));
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
$shield = null;
$pdo = null;
$countOutcome = false;
$signedIn = false;
$routeMissed = false;
$session = null;
try {
    ob_start();
    $request = RequestFactory::fromGlobals();
    if (str_starts_with($request->path, '/assets/')) {
        AssetServer::send($request->path);

        return;
    }
    if (EdgeFile::send($request->path)) {
        return;
    }
    if (QuietMiss::matches($request->path)) {
        EmptyNotFound::send();

        return;
    }
    $adminPath = AdminPath::fromEnv();
    $pdo = (new AppDatabase())->connect(DatabaseConfig::fromEnv());
    $probeLog = new FileLogger($logDirectory . DIRECTORY_SEPARATOR . 'probe.log');
    $shield = new ProbeShield(new ProbeBanStore($pdo), $probeLog);
    if (SignedInVisitor::hasCookie($_COOKIE)) {
        $session = new Session();
        $signedIn = SignedInVisitor::present($session, $request);
    }
    if ($shield->blocked($request, $signedIn)) {
        EmptyNotFound::send();

        return;
    }
    $countOutcome = true;
    $container = new Container();
    $container->set(PDO::class, $pdo);
    $container->set(LoggerInterface::class, $logger);
    $container->set(PhotoFacts::class, ServerPhotoFacts::class);
    $container->set(AdminPath::class, $adminPath);
    $container->set(Session::class, static function () use (&$session): Session {
        return $session ??= new Session();
    });
    $container->set(Request::class, $request);
    $container->set(SignInDevice::class, new SignInDevice($_COOKIE, $https));
    $container->set(RequestId::class, new RequestId($requestId));
    $container->set(View::class, new View(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src'));
    $container->set(MallApp::class, $container->get(MallApp::class));
    $featureDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Feature';
    $adminRouter = new AdminRouter($adminPath);
    $adminRouter->register($featureDirectory);
    if (!$adminRouter->dispatch($request->path, $request->method, $container)) {
        $router = new Router($container);
        $router->setNotFoundHandler(static function () use ($container, &$routeMissed): void {
            $routeMissed = true;
            $container->get(MallApp::class)->notFound(MallApp::MISS_ROUTE);
        });
        RegisterFeatures::register($router, $featureDirectory);
        $router->dispatch($request->path, $request->method);
    }
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
    if ($pdo instanceof PDO) {
        $pdo->exec('RESET ROLE');
    }
    $status = http_response_code();
    if ($countOutcome && $shield instanceof ProbeShield && $request instanceof Request && is_int($status)) {
        $shield->finished($request, $status, $routeMissed, $signedIn);
    }
    RequestCoverage::flush($coverageStarted, $coverageDirectory);
}
