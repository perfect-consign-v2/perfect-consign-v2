<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\RequestUploads;
use PHPUnit\Framework\TestCase;

final class RequestUploadsTest extends TestCase
{
    public function testNonStringKeysAndNonArrayValuesAreSkipped(): void
    {
        self::assertSame([], RequestUploads::normalize([
            0 => ['tmp_name' => '/tmp/x'],
            'bad' => 'not-an-array',
        ]));
    }

    public function testMultiFileShapeIsSkipped(): void
    {
        self::assertSame([], RequestUploads::normalize([
            'photos' => [
                'tmp_name' => ['/tmp/a'],
                'error' => [0],
                'size' => [1],
                'name' => ['a.jpg'],
            ],
        ]));
    }

    public function testPartialMultiShapeIsSkipped(): void
    {
        self::assertSame([], RequestUploads::normalize([
            'mixed' => [
                'tmp_name' => '/tmp/a',
                'error' => [0],
                'size' => 1,
                'name' => 'a.jpg',
            ],
        ]));
    }

    public function testIncompleteSingleUploadIsSkipped(): void
    {
        self::assertSame([], RequestUploads::normalize([
            'single' => [
                'tmp_name' => '/tmp/a',
                'error' => '0',
                'size' => 1,
                'name' => 'a.jpg',
            ],
        ]));
    }

    public function testValidSingleUploadIsKept(): void
    {
        $uploads = RequestUploads::normalize([
            'single' => [
                'tmp_name' => '/tmp/c',
                'error' => 0,
                'size' => 3,
                'name' => 'c.jpg',
            ],
        ]);
        self::assertSame([
            'tmp_name' => '/tmp/c',
            'error' => 0,
            'size' => 3,
            'name' => 'c.jpg',
        ], $uploads['single'] ?? null);
    }
}
