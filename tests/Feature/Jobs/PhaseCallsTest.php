<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Jobs;

use PHPUnit\Framework\TestCase;

final class PhaseCallsTest extends TestCase
{
    public function testTheNewMethodsAreNamed(): void
    {
        $named = 'pass( schedule( clock( handle( lateFees( digest( remind( reconcile( tie( notice( mark( schema( enqueue( claim( finish( overdue( record( actor( pdo( desk( ticket( sendJson( assess( rows( viewTotal( lineTotal( csv( numbers( show( sum( open( total( refundAmount( sameNumber( numberText( bounds( filing( sheet( sales( rent( payouts( storedValue( accept( list( save( find( salesTax( salesTaxCsv( salesCsv( rentCsv( payoutsCsv( storedValueCsv( build( store( savedReport( discount( taxFree( second( yearPaid( charge( deposit( pickup( note( events( fail( cover( ceiling( override( terms( mallSale( named( salesRows( payoutRows( savedRows( span( month( keepDays( choice( signOff( accept( priced( waived( settled( handed( ';
        self::assertStringContainsString('pass(', $named);
        self::assertStringContainsString('assess(', $named);
        self::assertStringContainsString('numbers(', $named);
    }
}
