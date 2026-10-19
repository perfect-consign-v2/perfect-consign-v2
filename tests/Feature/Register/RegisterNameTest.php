<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Register;

use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class RegisterNameTest extends TestCase
{
    public function testAStoredValueNumberIsAtMostFortyCharacters(): void
    {
        $store = $this->store([]);
        try {
            $store->storedValueId('gift_certificate', '');
            self::fail('a blank number was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a stored value number is required', $error->getMessage());
        }
        try {
            $store->storedValueId('gift_certificate', str_repeat('1', 41));
            self::fail('a 41 character number was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a stored value number is required', $error->getMessage());
        }
        try {
            $store->storedValueId('gift_certificate', str_repeat('1', 40));
            self::fail('a missing certificate was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame(RegisterStore::GIFT_CERTIFICATE_MISSING, $error->getMessage());
        }
    }

    public function testARegisterNameIsAtMostFortyCharacters(): void
    {
        $saved = '';
        $statements = $this->createStub(Statements::class);
        $statements->method('change')->willReturnCallback(static function (string $sql, array $params) use (&$saved): void {
            $name = $params[0] ?? '';
            $saved = is_string($name) ? $name : '';
        });
        $store = $this->store($statements);
        $store->add(str_repeat('A', 40));
        self::assertSame(str_repeat('A', 40), $saved);
        try {
            $store->add('');
            self::fail('a blank register name was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a register name is required and at most 40 characters', $error->getMessage());
        }
        try {
            $store->add(str_repeat('A', 41));
            self::fail('a 41 character register name was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a register name is required and at most 40 characters', $error->getMessage());
        }
    }

    private function store(Statements|array $statements): RegisterStore
    {
        if (is_array($statements)) {
            $stub = $this->createStub(Statements::class);
            $stub->method('bound')->willReturn($statements);
            $statements = $stub;
        }
        $store = (new ReflectionClass(RegisterStore::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(RegisterStore::class, 'statements'))->setValue($store, $statements);

        return $store;
    }
}
