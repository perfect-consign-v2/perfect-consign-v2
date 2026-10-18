<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class SettingsAndIntakeTest extends WebTestCase
{
    private FormClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::client();
        $signedIn = $this->client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSees("Today's sales", $signedIn);
    }

    public function testMoneyBuildingAndReceiptSaveOnTheirOwn(): void
    {
        $slug = ProbeMall::SLUG;
        $money = [
            'settings_part' => 'money',
            'default_store_take' => 'nope',
            'tax_rate' => '0',
            'operating_mode' => 'both',
        ];
        self::assertSees('store take is a percent from 0 to 100', $this->client->post('/' . $slug . '/settings', $money));
        $money['default_store_take'] = '10';
        $money['tax_rate'] = 'nope';
        self::assertSees('tax rate is a percent from 0 to 100', $this->client->post('/' . $slug . '/settings', $money));
        $money['tax_rate'] = '8.5';
        $money['cash_round_nickel'] = '1';
        $money['booth_rentals_enabled'] = '1';
        $money['rent_cycle_day'] = '1';
        $money['late_fee_grace_days'] = '0';
        self::assertSees('Money saved.', $this->client->post('/' . $slug . '/settings', $money));

        $building = [
            'settings_part' => 'building',
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => '0',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
            'floor_days' => '',
            'label_stock' => $this->labelStock(),
            'use_color' => '1',
            'use_brand' => '1',
        ];
        self::assertSees('booth size is whole feet', $this->client->post('/' . $slug . '/settings', $building));
        $building['booth_width_ft'] = '10';
        self::assertSees('Building saved.', $this->client->post('/' . $slug . '/settings', $building));

        self::assertSees('Receipt saved.', $this->client->post('/' . $slug . '/settings', [
            'settings_part' => 'receipt',
            'receipt_footer' => 'Thanks for visiting.',
        ]));
        self::assertSees('receipt footer is 400 characters or less', $this->client->post('/' . $slug . '/settings', [
            'settings_part' => 'receipt',
            'receipt_footer' => str_repeat('Thanks. ', 80),
        ]));
        self::assertSees('that time zone is not an IANA name', $this->client->post('/' . $slug . '/settings', [
            'time_zone' => 'Not/AZone',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '120',
            'floor_depth_ft' => '90',
            'floor_days' => '',
            'default_store_take' => '10',
            'tax_rate' => '8.5',
            'label_stock' => $this->labelStock(),
            'operating_mode' => 'both',
            'booth_rentals_enabled' => '1',
            'rent_cycle_day' => '1',
        ]));
    }

    public function testCustomersAndSalesPagesOpen(): void
    {
        $slug = ProbeMall::SLUG;
        $customers = $this->client->get('/' . $slug . '/customers');
        self::assertSame(200, $customers->status);
        self::assertSees('Customers', $customers);
        $phone = $this->client->get('/' . $slug . '/customers?phone=000');
        self::assertSees('No customer has that phone.', $phone);
        $sales = $this->client->get('/' . $slug . '/sales');
        self::assertSame(200, $sales->status);
        self::assertSees('Sales', $sales);
        $yesterday = $this->client->get('/' . $slug . '/sales?range=yesterday');
        self::assertSees('Yesterday', $yesterday);
    }

    public function testATagFileIsCheckedBeforeItIsBroughtIn(): void
    {
        $slug = ProbeMall::SLUG;
        $page = $this->client->get('/' . $slug . '/inventory/import');
        self::assertSees('Bring in tags', $page);
        self::assertSees('Choose a CSV file.', $this->client->post('/' . $slug . '/inventory/import', []));

        $bad = $this->file("name\n");
        self::assertSees(
            'The first row must be code, vendor, description, price, barcode, quantity.',
            $this->client->upload('/' . $slug . '/inventory/import', [], ['tags' => $bad]),
        );

        $code = 'c' . bin2hex(random_bytes(3));
        $created = $this->client->post('/' . $slug . '/vendors', [
            'code' => $code,
            'first_name' => 'Cora',
            'last_name' => $code,
            'store_take' => '0',
        ]);
        self::assertSees('Vendor created.', $created);
        $missing = $this->file("code,vendor,description,price,barcode,quantity\nz1,missing-vendor,Coat,10.00,,\n");
        self::assertSees(
            'uses a vendor code that is not in this mall.',
            $this->client->upload('/' . $slug . '/inventory/import', [], ['tags' => $missing]),
        );
        $tag = 't' . bin2hex(random_bytes(3));
        $good = $this->file(
            "code,vendor,description,price,barcode,quantity\n"
            . $tag . ',' . $code . ',Imported coat,10.00,BC' . $tag . ",\n"
            . "\n",
        );
        self::assertSees('1 tag brought in.', $this->client->upload('/' . $slug . '/inventory/import', [], ['tags' => $good]));
        self::assertSees($tag, $this->client->get('/' . $slug . '/inventory?q=' . rawurlencode($tag)));
    }

    public function testAPhotoCanBeAttachedToAnItem(): void
    {
        $slug = ProbeMall::SLUG;
        $code = 'p' . bin2hex(random_bytes(3));
        $this->client->post('/' . $slug . '/vendors', [
            'code' => $code,
            'first_name' => 'Pat',
            'last_name' => $code,
            'store_take' => '0',
        ]);
        $directory = $this->client->get('/' . $slug . '/parties');
        $vendorId = HtmlPage::taggedId($directory->body, 'tr', 'vendor', $code);
        $tag = 't' . bin2hex(random_bytes(3));
        $created = $this->client->post('/' . $slug . '/inventory', [
            'code' => $tag,
            'description' => 'Photo ' . $tag,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '4.00',
            'barcode' => 'BC-' . $tag,
            'intake_on' => '2026-09-01',
        ]);
        self::assertSees('Item created.', $created);
        $list = $this->client->get('/' . $slug . '/inventory?q=' . rawurlencode($tag));
        $itemId = HtmlPage::taggedId($list->body, 'tr', 'item', 'Photo ' . $tag);
        $photo = '/' . $slug . '/inventory/' . $itemId . '/photo';
        self::assertSame(404, $this->client->get($photo)->status);
        self::assertSees('Choose a photo.', $this->client->post($photo, []));

        $text = $this->file('not-a-photo');
        self::assertSees('Use a JPEG, PNG, or WebP photo.', $this->client->upload($photo, [], ['photo' => $text]));

        $pngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        self::assertNotFalse($pngBytes);
        $png = $this->file($pngBytes);
        self::assertSees('Photo saved.', $this->client->upload($photo, [], ['photo' => $png]));
        $stored = $this->client->get($photo);
        self::assertSame(200, $stored->status);
        self::assertStringStartsWith("\x89PNG", $stored->body);
        self::assertSame(404, $this->client->post('/' . $slug . '/inventory/01900000-0000-7000-8000-0000000000e1/photo', [])->status);
        $badIntake = $this->client->post('/' . $slug . '/inventory', [
            'code' => 'x' . bin2hex(random_bytes(3)),
            'description' => 'No intake',
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '4.00',
            'barcode' => '',
            'intake_on' => 'not-a-day',
        ]);
        self::assertSees('Enter an intake date.', $badIntake);
        $expired = $this->client->post('/' . $slug . '/inventory', [
            'code' => 'x' . bin2hex(random_bytes(3)),
            'description' => 'Expired',
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '4.00',
            'barcode' => '',
            'intake_on' => '2026-09-01',
            'expires_on' => '2026-08-01',
        ]);
        self::assertSees('The expire date is before the intake date.', $expired);
    }

    private function labelStock(): string
    {
        $page = $this->client->get('/' . ProbeMall::SLUG . '/settings');
        $matched = preg_match('/id="label_stock"[^>]*>.*?<option value="([^"]+)"/s', $page->body, $stock);
        self::assertSame(1, $matched);

        return $stock[1];
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pc-upload-');
        self::assertNotFalse($path);
        $written = file_put_contents($path, $contents);
        self::assertNotFalse($written);

        return $path;
    }
}
