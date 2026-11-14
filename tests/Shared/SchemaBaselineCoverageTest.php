<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PerfectConsign\Schema\SchemaBaseline;
use PerfectConsign\Tests\Support\ScriptPdo;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaBaselineCoverageTest extends TestCase
{
    private const SCHEMA = 'tenant_0123456789abcdef0123456789abcdef';

    public function testAbsentNamesTheFirstMissingTableRoutineOrNothing(): void
    {
        $missingTable = new ScriptPdo();
        $missingTable->onQuery('to_regclass', false);
        self::assertSame('booths', (new SchemaBaseline($missingTable))->absent(self::SCHEMA, ['booths'], []));

        $blank = new ScriptPdo();
        $blank->onQuery('to_regclass', [['reg' => '']]);
        self::assertSame('booths', (new SchemaBaseline($blank))->absent(self::SCHEMA, ['booths'], []));

        $missingRoutine = new ScriptPdo();
        $missingRoutine->onQuery('to_regclass', [['reg' => 'tenant.booths']]);
        $missingRoutine->onPrepare('pg_proc', []);
        self::assertSame(
            'stall_rent_charge',
            (new SchemaBaseline($missingRoutine))->absent(self::SCHEMA, ['booths'], ['stall_rent_charge'])
        );

        $present = new ScriptPdo();
        $present->onQuery('to_regclass', [['reg' => 'tenant.booths']]);
        $present->onPrepare('pg_proc', [['ok' => '1']]);
        self::assertNull((new SchemaBaseline($present))->absent(self::SCHEMA, ['booths'], ['stall_rent_charge']));
    }

    public function testAnInvalidObjectNameIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('schema object name is invalid');
        (new SchemaBaseline(new ScriptPdo()))->absent(self::SCHEMA, ['Bad Name'], []);
    }

    public function testTheClosedPayoutWindowReadsTheTriggerFlag(): void
    {
        $pdo = new ScriptPdo();
        $pdo->onPrepare('journals_closed_payout_window', [['open' => 't']]);
        $baseline = new SchemaBaseline($pdo);

        self::assertTrue($baseline->window(self::SCHEMA));
    }
}
