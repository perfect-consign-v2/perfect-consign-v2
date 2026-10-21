<?php

declare(strict_types=1);

use PerfectApp\Container\Container;
use PerfectApp\Exception\RouteNotFoundException;
use PerfectApp\Logger\FileLogger;
use PerfectApp\Routing\Router;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\RegisterFeatures;
use PerfectConsign\Feature\Inventory\PhotoFacts;
use PerfectConsign\Feature\Inventory\ServerPhotoFacts;
use PerfectConsign\Shared\Database\AppDatabase;
use PerfectConsign\Shared\Http\ErrorHandling;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use Psr\Log\LoggerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$coverageDirectory = getenv('PC_COVERAGE');
$coverageStarted = false;
if (
    is_string($coverageDirectory)
    && $coverageDirectory !== ''
    && function_exists('xdebug_start_code_coverage')
    && defined('XDEBUG_FILTER_CODE_COVERAGE')
    && defined('XDEBUG_PATH_INCLUDE')
    && defined('XDEBUG_CC_UNUSED')
    && defined('XDEBUG_CC_DEAD_CODE')
) {
    xdebug_set_filter(
        XDEBUG_FILTER_CODE_COVERAGE,
        XDEBUG_PATH_INCLUDE,
        [dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src']
    );
    xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
    $coverageStarted = true;
}

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

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'path' => '/',
    'secure' => requestIsHttps(),
]);

$container = null;
try {
    $request = requestFromGlobals();
    if (str_starts_with($request->path, '/assets/')) {
        serveAsset($request->path);
        exit;
    }
    $pdo = (new AppDatabase())->connect(databaseConfig());
    $container = new Container();
    $container->set(PDO::class, $pdo);
    $container->set(LoggerInterface::class, $logger);
    $container->set(PhotoFacts::class, ServerPhotoFacts::class);
    $container->set(Session::class, new Session());
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
} catch (RouteNotFoundException) {
    if ($container !== null) {
        try {
            $container->get(MallApp::class)->notFound(MallApp::MISS_ROUTE);
        } catch (ResponseSent) {
            return;
        }
    }
    http_response_code(404);
    echo 'Not found';
} catch (Throwable $error) {
    $logger->error($error->getMessage(), ['file' => $error->getFile(), 'line' => $error->getLine()]);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo $debug ? $error->getMessage() : 'The page could not be shown.';
} finally {
    writeRequestCoverage($coverageStarted, $coverageDirectory);
}

function writeRequestCoverage(bool $started, mixed $directory): void
{
    if (!$started || !is_string($directory) || $directory === '' || !function_exists('xdebug_get_code_coverage')) {
        return;
    }
    $coverage = xdebug_get_code_coverage();
    xdebug_stop_code_coverage(false);
    if (!is_array($coverage)) {
        return;
    }
    $src = strtolower(str_replace('\\', '/', dirname(__DIR__) . '/src/'));
    $kept = [];
    foreach ($coverage as $file => $lines) {
        if (!is_string($file) || !is_array($lines)) {
            continue;
        }
        $real = realpath($file);
        if ($real === false) {
            continue;
        }
        $key = strtolower(str_replace('\\', '/', $real));
        if (!str_starts_with($key, $src)) {
            continue;
        }
        $kept[$real] = $lines;
    }
    if ($kept === []) {
        return;
    }
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return;
    }
    $written = file_put_contents(
        $directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '.php',
        '<?php return ' . var_export($kept, true) . ";\n"
    );
    if ($written === false) {
        return;
    }
}

function serveAsset(string $path): void
{
    $root = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets');
    $resolved = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $path));
    if (
        $root === false
        || $resolved === false
        || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)
        || !is_file($resolved)
    ) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    $extension = pathinfo($resolved, PATHINFO_EXTENSION);
    $types = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8'];
    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
    $contents = file_get_contents($resolved);
    if ($contents === false) {
        http_response_code(500);
        echo 'The page could not be shown.';
        return;
    }
    echo $contents;
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

function requestIsHttps(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
        return true;
    }
    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';

    return is_string($forwarded) && strtolower($forwarded) === 'https';
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

    return new Request(
        $method,
        $path,
        $_GET,
        $_POST,
        sameHostPath(refererHeader(), hostHeader()),
        requestOrigin(),
        clientAddress(),
        requestUploads(),
        acceptHeader(),
    );
}

function acceptHeader(): string
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (!is_string($accept)) {
        return '';
    }

    return $accept;
}

function clientAddress(): string
{
    $address = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($address)) {
        return '';
    }
    $address = trim($address);
    if (preg_match('/^[A-Za-z0-9:.]{1,64}$/', $address) !== 1) {
        return '';
    }

    return $address;
}

/**
 * @return array<string, array{tmp_name: string, error: int, size: int, name: string}>
 */
function requestUploads(): array
{
    $uploads = [];
    foreach ($_FILES as $key => $file) {
        if (!is_string($key) || !is_array($file)) {
            continue;
        }
        $tmp = $file['tmp_name'] ?? '';
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $size = $file['size'] ?? 0;
        $name = $file['name'] ?? '';
        if (!is_string($tmp) || !is_int($error) || !is_int($size) || !is_string($name)) {
            continue;
        }
        $uploads[$key] = [
            'tmp_name' => $tmp,
            'error' => $error,
            'size' => $size,
            'name' => $name,
        ];
    }

    return $uploads;
}

function requestOrigin(): string
{
    $host = hostHeader();
    if (preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host) !== 1) {
        return '';
    }

    return (requestIsHttps() ? 'https' : 'http') . '://' . $host;
}

function refererHeader(): string
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (!is_string($referer)) {
        return '';
    }

    return $referer;
}

function hostHeader(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!is_string($host)) {
        return '';
    }

    return $host;
}

function sameHostPath(string $referer, string $host): string
{
    if ($referer === '' || $host === '') {
        return '';
    }
    $parts = parse_url($referer);
    if (!is_array($parts)) {
        return '';
    }
    $scheme = $parts['scheme'] ?? '';
    $refererHost = $parts['host'] ?? '';
    $path = $parts['path'] ?? '';
    if (!is_string($scheme) || !is_string($refererHost) || !is_string($path)) {
        return '';
    }
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }
    $requestHost = preg_replace('/:\d+$/', '', strtolower($host));
    if (!is_string($requestHost) || strtolower($refererHost) !== $requestHost) {
        return '';
    }
    if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return '';
    }

    return $path;
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
