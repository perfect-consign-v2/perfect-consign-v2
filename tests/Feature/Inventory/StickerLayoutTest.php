<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Inventory;

use InvalidArgumentException;
use PerfectConsign\Feature\Inventory\BarcodePng;
use PerfectConsign\Feature\Inventory\Code128;
use PerfectConsign\Feature\Inventory\LabelCatalog;
use PerfectConsign\Feature\Inventory\LabelRequest;
use PerfectConsign\Feature\Inventory\LabelSheet;
use PerfectConsign\Feature\Inventory\StickerPiece;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StickerLayoutTest extends TestCase
{
    public function testABarcodeBecomesAPng(): void
    {
        $codes = new Code128();
        $bits = $codes->bits('BC-100');
        self::assertNotSame('', $bits);
        self::assertGreaterThan(20, $codes->modules('BC-100'));
        $png = (new BarcodePng())->draw($bits, 2, 20);
        self::assertStringStartsWith("\x89PNG", $png);
    }

    public function testABlankOrOddBarcodeIsRefused(): void
    {
        $codes = new Code128();
        try {
            $codes->bits('');
            self::fail('a blank barcode was drawn');
        } catch (InvalidArgumentException $error) {
            self::assertSame('This piece has no barcode.', $error->getMessage());
        }
        try {
            $codes->bits("BC\n");
            self::fail('an unprintable barcode was drawn');
        } catch (InvalidArgumentException $error) {
            self::assertSame('This barcode has a character the sticker cannot print.', $error->getMessage());
        }
        try {
            (new BarcodePng())->draw('', 2, 20);
            self::fail('an empty image was drawn');
        } catch (RuntimeException $error) {
            self::assertSame('barcode image could not be drawn', $error->getMessage());
        }
    }

    public function testEachStickerStockLaysOutAPiece(): void
    {
        $codes = new Code128();
        $sheet = new LabelSheet($codes, new BarcodePng(), new \PerfectConsign\Feature\Inventory\LabelFace($codes));
        $piece = new StickerPiece('BC-100', '$10.00', 'A wool coat', 'Web Probe', 'ADA', 'Wool', '09/01/2026', 'T100');
        $long = new StickerPiece(
            str_repeat('A', 40),
            '$1.00',
            str_repeat('word ', 40),
            '',
            '',
            '',
            '09/01/2026',
            'T200',
        );
        foreach ((new LabelCatalog())->all() as $stock) {
            $laid = $sheet->compose($stock, [$piece, $long], 2, 2, 2);
            self::assertNotSame('', $laid->pageWidth);
            self::assertNotSame([], $laid->pages);
        }
    }

    public function testAPrintJobRefusesABadRequest(): void
    {
        $jobs = new LabelRequest(new LabelCatalog());
        $id = '01900000-0000-7000-8000-0000000000aa';
        $job = $jobs->read([
            'stock' => '',
            'copies' => '2',
            'start_row' => '1',
            'start_column' => '1',
            'item' => $id,
        ], 'avery_8160');
        self::assertSame('avery_8160', $job['stock']->getKey());
        self::assertSame([$id], $job['ids']);
        try {
            $jobs->read(['copies' => '0', 'item' => $id], 'avery_8160');
            self::fail('zero copies were accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('How many is a number from 1 to 999.', $error->getMessage());
        }
        try {
            $jobs->read(['copies' => '1', 'item' => 'nope'], 'avery_8160');
            self::fail('a bad piece was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Choose a piece.', $error->getMessage());
        }
        try {
            $jobs->read(['copies' => '1', 'start_row' => '11', 'item' => $id], 'avery_8160');
            self::fail('a row past the sheet was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('A new sheet starts at row 1, column 1.', $error->getMessage());
        }
        try {
            $jobs->read(['copies' => '241', 'start_row' => '1', 'start_column' => '1', 'item' => $id], 'avery_8160');
            self::fail('too many stickers were accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('That is more than 240 stickers. Print fewer pieces or fewer copies.', $error->getMessage());
        }
        try {
            $jobs->read(['copies' => '1', 'start_row' => '1', 'item' => $id], 'avery_8160');
            self::fail('a missing column was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('A new sheet starts at row 1, column 1.', $error->getMessage());
        }
        try {
            $jobs->read(['stock' => 'missing', 'copies' => '1', 'item' => $id], 'avery_8160');
            self::fail('unknown stickers were accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Choose the stickers to print.', $error->getMessage());
        }
    }
}
