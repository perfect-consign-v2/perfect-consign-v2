<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class ActiveRegisterTest extends TestCase
{
    public function testTheOnlyOpenDrawerIsTheRegister(): void
    {
        $register = $this->register([['id' => 'drawer-1', 'name' => 'Front']]);

        $chosen = $register->chosen('demo', true);

        self::assertSame('drawer-1', $chosen['id']);
        self::assertSame('Front', $chosen['name']);
        self::assertFalse($chosen['choose']);
    }

    public function testTheStoredDrawerIsChosenWhenSeveralAreOpen(): void
    {
        $register = $this->register([
            ['id' => 'drawer-1', 'name' => 'Front'],
            ['id' => 'drawer-2', 'name' => 'Back'],
        ], ['register:demo' => 'drawer-2']);

        self::assertSame('drawer-2', $register->chosen('demo', true)['id']);
    }

    public function testADrawerWithoutANameIsSkipped(): void
    {
        $register = $this->register([['id' => 'drawer-1', 'name' => 4]]);

        self::assertSame('', $register->chosen('demo', true)['id']);
    }

    public function testADrawerOpenedTodayIsNotLeftFromYesterday(): void
    {
        $today = gmdate('Y-m-d');
        $register = $this->register([
            ['id' => 'today', 'name' => 'Front', 'opened_at' => $today . ' 12:00:00'],
            ['id' => 'old', 'name' => 'Back', 'opened_at' => '2000-01-01 00:00:00'],
            ['id' => 'blank', 'name' => 'Side', 'opened_at' => 4],
        ]);

        $left = $register->leftOpen(true);

        self::assertSame(['old'], array_column($left, 'id'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $bag
     */
    private function register(array $rows, array $bag = []): ActiveRegister
    {
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn($rows);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $mallRows = $this->createStub(Statements::class);
        $mallRows->method('rows')->willReturn([['time_zone' => 'UTC']]);
        $mall = new MallSettings($mallRows, new CompanyStore(
            $this->createStub(Statements::class),
            new DatabaseRole($pdo),
            $pdo,
            new CompanyLines(),
        ));
        $session = $this->createStub(Session::class);
        $session->method('get')->willReturnCallback(static function (string $key) use (&$bag): mixed {
            return $bag[$key] ?? null;
        });
        $session->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$bag): void {
            $bag[$key] = $value;
        });
        $session->method('delete')->willReturnCallback(static function (string $key) use (&$bag): void {
            unset($bag[$key]);
        });

        return new ActiveRegister($pdo, $session, $mall, new MallClock($mall));
    }
}
