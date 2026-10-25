<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\HtmlPage;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class LetterDeskHttpTest extends WebTestCase
{
    public function testLettersPageFilesAndReads(): void
    {
        $client = self::client();
        $slug = ProbeMall::SLUG;
        $client->signIn($slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $code = 'v' . bin2hex(random_bytes(3));
        $created = $client->post('/' . $slug . '/vendors', [
            'code' => $code,
            'first_name' => 'Ada',
            'last_name' => $code,
            'email' => $code . '@example.test',
            'store_take' => '50',
        ]);
        self::assertSees('Vendor created.', $created);
        $vendorId = HtmlPage::pathId($created->body, $slug . '/vendors');
        $desk = $client->get('/' . $slug . '/letters');
        self::assertSees('Email', $desk);
        $filed = $client->post('/' . $slug . '/letters', [
            'subject' => 'Pickup ' . $code,
            'message' => 'Your items are ready.',
            'vendor_id[]' => $vendorId,
        ]);
        self::assertSees('The letter is filed', $filed);
        self::assertSees('Pickup ' . $code, $filed);
    }
}
