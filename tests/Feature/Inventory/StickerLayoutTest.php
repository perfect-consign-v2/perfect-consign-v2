<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Inventory;

use InvalidArgumentException;
use PerfectConsign\Feature\Inventory\BarcodePng;
use PerfectConsign\Feature\Inventory\Code128;
use PerfectConsign\Feature\Inventory\InchMeasure;
use PerfectConsign\Feature\Inventory\LabelBox;
use PerfectConsign\Feature\Inventory\LabelCatalog;
use PerfectConsign\Feature\Inventory\LabelFace;
use PerfectConsign\Feature\Inventory\LabelInk;
use PerfectConsign\Feature\Inventory\LabelRequest;
use PerfectConsign\Feature\Inventory\LabelSheet;
use PerfectConsign\Feature\Inventory\LabelStock;
use PerfectConsign\Feature\Inventory\StickerPiece;
use PerfectConsign\Feature\Inventory\StickerSheet;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StickerLayoutTest extends TestCase
{
    public function testABarcodeBecomesAPng(): void
    {
        $codes = new Code128();
        self::assertSame(66, $codes->modules('A'));
        self::assertSame('3b56cfa9ac20b6d323d4d1b0a2467a4a9f54261274fcb6b24f7d7a08c527cd02', hash('sha256', $codes->bits('A')));
        self::assertSame('266273c03ba9cea8c90af46bd07ce308d80310c3ba2811ca466c8d560c12a28b', hash('sha256', $codes->bits(' ')));
        self::assertSame('9d9b02dc41b7584d7fe8ddd7a2cbdd9c896c2a919e3abdc4ebbc644cf9df0193', hash('sha256', $codes->bits('~')));
        self::assertSame(121, $codes->modules('BC-100'));
        $png = (new BarcodePng())->draw($codes->bits('A'), 2, 20);
        self::assertSame('06ba4435d5c427663ef1525e01e745b3c96bdf3e0d20321eae2c60b69ea372e9', hash('sha256', $png));
        self::assertStringStartsWith("\x89PNG", (new BarcodePng())->draw($codes->bits('A'), 1, 1));
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
            $codes->bits(chr(31));
            self::fail('a low barcode was drawn');
        } catch (InvalidArgumentException $error) {
            self::assertSame('This barcode has a character the sticker cannot print.', $error->getMessage());
        }
        try {
            $codes->bits(chr(127));
            self::fail('a high barcode was drawn');
        } catch (InvalidArgumentException $error) {
            self::assertSame('This barcode has a character the sticker cannot print.', $error->getMessage());
        }
        try {
            (new BarcodePng())->draw($codes->bits('A'), 0, 1);
            self::fail('a zero module width was drawn');
        } catch (RuntimeException $error) {
            self::assertSame('barcode image could not be drawn', $error->getMessage());
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
        self::assertSame('0.000000in', InchMeasure::text(0));
        self::assertSame('1.500000in', InchMeasure::text(1500000));
        self::assertSame('-0.250000in', InchMeasure::text(-250000));
        $catalog = new LabelCatalog();
        self::assertCount(5, $catalog->choices());
        self::assertTrue($catalog->isKnown(LabelCatalog::DEFAULT));
        self::assertFalse($catalog->isKnown('missing'));
        try {
            $catalog->get('missing');
            self::fail('unknown stickers were returned');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Choose the stickers to print.', $error->getMessage());
        }
        self::assertSame(LabelCatalog::DEFAULT, $catalog->get(LabelCatalog::DEFAULT)->getKey());
        $codes = new Code128();
        $sheet = new LabelSheet($codes, new BarcodePng(), new LabelFace($codes));
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
        $expected = [
            'avery_8160' => ['0', '1', '10', '3', '852da6bd1e055f2c44b7d2d93f493100e46c2bd5cb5b159e0a75918882f764c0'],
            'avery_94073' => ['0', '0', '10', '5', '2b39b8200f410bb57364ebf5b270f630252cd62b7992eb4052375c1439768966'],
            'bcw_consignment' => ['0', '1', '1', '1', '8c266388f255841ad9c6b158751a76f6af7c954b709615d343fcd432b5a28d24'],
            'bcw_consignment_2up' => ['0', '1', '1', '2', 'd20258a6319eaad371e52562e6cbeb70cb4262e08e3ef948ad3b287dafc9e1a3'],
            'bcw_jewelry' => ['1', '0', '1', '1', '277bd25db2058e6d46353688d7da5775ac377e724d382316485d28b7af3935ce'],
        ];
        foreach ($catalog->all() as $stock) {
            $known = $expected[$stock->getKey()];
            self::assertSame($known[0] === '1', $stock->isJewelry());
            self::assertSame($known[1] === '1', $stock->isTagged());
            self::assertSame((int) $known[2], $stock->getRows());
            self::assertSame((int) $known[3], $stock->getColumns());
            self::assertSame(0, $stock->getRowGap());
            $laid = $sheet->compose($stock, [$piece, $long], 2, 2, 2);
            self::assertSame($known[4], $this->fingerprint($laid));
            self::assertCount($this->pages($stock->getKey()), $laid->pages);
        }
        $full = $sheet->compose($catalog->get('avery_8160'), [$piece], 30, 1, 1);
        self::assertCount(1, $full->pages);
        $fitted = str_repeat('b', 46);
        $clipped = str_repeat('c', 47);
        $avery = $catalog->get('avery_8160');
        $face = new LabelFace($codes);
        $exact = $face->layout($avery, new StickerPiece('BC-100', '$10.00', $fitted, 'Web Probe', 'ADA', 'Wool', '09/01/2026', 'T100'));
        self::assertSame($fitted, $exact->lines[1]->text);
        $over = $face->layout($avery, new StickerPiece('BC-100', '$10.00', $clipped, 'Web Probe', 'ADA', 'Wool', '09/01/2026', 'T100'));
        self::assertSame(str_repeat('c', 45) . '…', $over->lines[1]->text);
        $tight = new LabelStock(
            'tight',
            'Tight',
            'jewelry',
            new LabelBox(1, 1, 2000000, 2000000, 0, 0, 2000000, 123000, 0),
            new LabelInk(1000, 5000, 1000, 1000, 1000),
        );
        $price = $face->layout($tight, new StickerPiece('A', '$1.00', 'desc', 'store', 'ada', 'wool', 'day', 'tag'));
        self::assertCount(1, $price->lines);
        self::assertSame('price', $price->lines[0]->kind);
        self::assertSame('$1.00', $price->lines[0]->text);
        self::assertSame('0.120000in', $price->lines[0]->size);
        self::assertSame(1000, $price->bars);
        self::assertFalse($price->isNarrow());
        $capped = new LabelStock(
            'capped',
            'Capped',
            'jewelry',
            new LabelBox(1, 1, 2000000, 2000000, 0, 0, 2000000, 123000, 0),
            new LabelInk(1000, 500, 1000, 1000, 1000),
        );
        self::assertSame(1000, $face->layout($capped, new StickerPiece('A', '$1.00', '', '', '', '', '', ''))->bars);
        $exact = new LabelStock(
            'exact',
            'Exact',
            'sheet',
            new LabelBox(1, 1, 4000000, 4000000, 0, 0, 4000000, 3000000, 0),
            new LabelInk(1000, 2000, 1000, 1000, 1000),
        );
        self::assertSame('0.280000in', $face->layout($exact, $piece)->lines[0]->size);
        $edge = new LabelStock(
            'edge',
            'Edge',
            'sheet',
            new LabelBox(1, 1, 1000000, 1000000, 0, 0, 594000, 1000000, 0),
            new LabelInk(1000, 2000, 1000, 100000, 1000),
        );
        self::assertTrue($face->layout($edge, new StickerPiece('A', '$1.00', 'coat', 'store', 'ada', 'wool', 'day', 'tag'))->isNarrow());
        $even = new LabelStock(
            'even',
            'Even',
            'sheet',
            new LabelBox(1, 1, 1000000, 1000000, 0, 0, 496000, 1000000, 0),
            new LabelInk(1000, 2000, 1000, 1000, 1000),
        );
        self::assertFalse($face->layout($even, new StickerPiece('A', '$1.00', 'coat', 'store', 'ada', 'wool', 'day', 'tag'))->isNarrow());
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
        self::assertSame(2, $job['copies']);
        self::assertSame(1, $job['row']);
        self::assertSame(1, $job['column']);
        self::assertSame([$id], $job['ids']);
        $edge = $jobs->read([
            'copies' => '240',
            'start_row' => '10',
            'start_column' => '3',
            'item' => [$id, strtoupper($id)],
        ], 'avery_8160');
        self::assertSame(240, $edge['copies']);
        self::assertSame(10, $edge['row']);
        self::assertSame(3, $edge['column']);
        self::assertSame(['01900000-0000-7000-8000-0000000000AA'], $edge['ids']);
        $many = [];
        for ($index = 0; $index < 240; $index++) {
            $many[] = sprintf('01900000-0000-7000-8000-%012d', $index);
        }
        $filled = $jobs->read([
            'copies' => '1',
            'start_row' => '1',
            'start_column' => '1',
            'item' => $many,
        ], 'avery_8160');
        self::assertCount(240, $filled['ids']);
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
        try {
            $jobs->read(['copies' => '1', 'start_row' => '1', 'start_column' => '1', 'item' => 4], 'avery_8160');
            self::fail('a number was accepted as a piece');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Choose a piece.', $error->getMessage());
        }
    }

    private function fingerprint(StickerSheet $sheet): string
    {
        $parts = [$sheet->pageWidth, $sheet->pageHeight, $sheet->isNarrow() ? '1' : '0'];
        foreach ($sheet->pages as $page) {
            foreach ($page as $cell) {
                $parts[] = $cell->style;
                $parts[] = $cell->barHeight;
                $parts[] = $cell->barcode;
                $parts[] = $cell->codeHeight;
                $parts[] = hash('sha256', $cell->image);
                foreach ($cell->lines as $line) {
                    $parts[] = $line->kind . '=' . $line->text . '@' . $line->size;
                }
            }
        }

        return hash('sha256', implode("\n", $parts));
    }

    private function pages(string $key): int
    {
        return match ($key) {
            'avery_8160', 'avery_94073' => 1,
            'bcw_consignment_2up' => 4,
            default => 6,
        };
    }
}
