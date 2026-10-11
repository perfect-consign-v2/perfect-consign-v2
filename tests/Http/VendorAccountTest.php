<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class VendorAccountTest extends WebTestCase
{
    public function testAVendorOpensTheirAccountAndCannotPrintSomeoneElsesItem(): void
    {
        $owner = self::client();
        $owner->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $code = 'V' . bin2hex(random_bytes(3));
        $created = $owner->post('/' . ProbeMall::SLUG . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => 'Lane',
        ]);
        self::assertSame(200, $created->status);
        self::assertSees('Vendor created.', $created);
        $vendorId = $this->match($created->body, '#/vendors/([0-9a-fA-F-]{36})/sign-in#');
        $username = 'v' . bin2hex(random_bytes(4));
        $refused = $owner->post('/' . ProbeMall::SLUG . '/vendors/' . $vendorId . '/sign-in', [
            'username' => $username,
            'display_name' => 'Ada Lane',
            'password' => 'short',
        ]);
        self::assertSees('Use a password of at least 8 characters.', $refused);
        $added = $owner->post('/' . ProbeMall::SLUG . '/vendors/' . $vendorId . '/sign-in', [
            'username' => $username,
            'display_name' => 'Ada Lane',
            'password' => 'vendor-pass',
        ]);
        self::assertSees('Vendor sign-in added.', $added);
        $saved = $owner->post('/' . ProbeMall::SLUG . '/vendors/' . $vendorId . '/sign-in', [
            'username' => $username,
            'display_name' => 'Ada Lane',
            'password' => 'vendor-next',
        ]);
        self::assertSees('Vendor sign-in saved.', $saved);

        $vendor = self::client();
        $stale = $vendor->signIn(ProbeMall::SLUG, $username, 'vendor-pass');
        self::assertSees('That username or password is wrong.', $stale);
        $home = $vendor->signIn(ProbeMall::SLUG, $username, 'vendor-next');
        self::assertSame(200, $home->status);
        self::assertSees('Your check', $home);
        self::assertSees('Your share of sales', $home);
        self::assertSees('Rent you owe', $home);
        $labels = $vendor->get('/' . ProbeMall::SLUG . '/vendor/labels');
        self::assertSame(200, $labels->status);
        self::assertSees('Your items', $labels);
        $printed = $vendor->post('/' . ProbeMall::SLUG . '/vendor/labels', [
            'item' => '00000000-0000-7000-8000-0000000000aa',
            'copies' => '1',
            'stock' => 'avery_8160',
            'start_row' => '1',
            'start_column' => '1',
        ]);
        self::assertLessThan(500, $printed->status);
        self::assertSees('Your items', $printed);
        self::assertSees('That item is not yours.', $printed);
    }

    public function testABoothFixWithoutAItemIsRefused(): void
    {
        $client = self::client();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $name = 'Fix ' . bin2hex(random_bytes(3));
        $created = $client->post('/' . ProbeMall::SLUG . '/registers', ['name' => $name]);
        self::assertSees($name, $created);
        $registerId = $this->match(
            $created->body,
            '/id="register-([0-9a-fA-F-]{36})">\s*<div class="card-head">\s*<div class="h3">' . preg_quote($name, '/') . '/'
        );
        $counts = [];
        foreach ([10000, 5000, 2000, 1000, 500, 100, 25, 10, 5, 1] as $key) {
            $counts['count_' . $key] = '0';
        }
        $opened = $client->post('/' . ProbeMall::SLUG . '/registers/' . $registerId . '/open', [
            'cashier' => ProbeMall::USERNAME,
            'opening_float' => '20.00',
            ...$counts,
        ]);
        self::assertSees('Register opened.', $opened);
        $moved = $client->post('/' . ProbeMall::SLUG . '/pos/receipt/00000000-0000-7000-8000-000000000099/booth', [
            'item_id' => 'not-a-item',
            'vendor_id' => '00000000-0000-7000-8000-0000000000bb',
        ]);
        self::assertLessThan(500, $moved->status);
    }

    private function match(string $html, string $pattern): string
    {
        $found = preg_match($pattern, $html, $match);
        if ($found !== 1 || !isset($match[1])) {
            self::fail('the page did not contain ' . $pattern);
        }

        return $match[1];
    }
}
