<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CspSurfaceTest extends TestCase
{
    public function testSrcTemplatesRefuseInlineScriptStyleAndHandlers(): void
    {
        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src';
        $violations = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $path = $file->getPathname();
            $raw = file_get_contents($path);
            if ($raw === false) {
                self::fail('could not read ' . $path);
            }
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            if (preg_match('/\bstyle=/', $raw) === 1) {
                $violations[] = $relative . ': style= attribute';
            }
            if (preg_match('/<style\b/i', $raw) === 1) {
                $violations[] = $relative . ': inline <style>';
            }
            if (preg_match('/\bon(?:click|change|error|load|submit)\s*=/i', $raw) === 1) {
                $violations[] = $relative . ': inline event handler';
            }
            if (preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $raw) === 1) {
                $violations[] = $relative . ': inline <script> without src';
            }
        }
        self::assertSame([], $violations, "CSP surface violations:\n" . implode("\n", $violations));
    }
}
