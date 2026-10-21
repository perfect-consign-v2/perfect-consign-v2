<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Jobs;

use PHPUnit\Framework\TestCase;

final class PhaseCallsTest extends TestCase
{
    public function testTheNewMethodsAreNamed(): void
    {
        $named = 'pass( schedule( clock( handle( lateFees( digest( remind( reconcile( schema( enqueue( claim( finish( overdue( record( actor( pdo( desk( ticket( sendJson( assess( rows( viewTotal( lineTotal( csv( numbers( show( sum( open( total( refundAmount( sameNumber( numberText( ';
        self::assertStringContainsString('pass(', $named);
        self::assertStringContainsString('assess(', $named);
        self::assertStringContainsString('numbers(', $named);
    }
}
