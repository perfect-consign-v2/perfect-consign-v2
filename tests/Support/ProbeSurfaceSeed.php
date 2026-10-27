<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use RuntimeException;

/**
 * One-shot webprobe fixture for surface crawls: period, cashier, books, vendor + item.
 */
final class ProbeSurfaceSeed
{
    public function __construct(
        public readonly string $vendorId,
        public readonly string $itemId,
        public readonly string $itemCode,
        public readonly string $cashierUsername,
        public readonly string $cashierPassword,
        public readonly string $booksUsername,
        public readonly string $booksPassword,
        public readonly string $vendorUsername,
        public readonly string $vendorPassword,
    ) {
    }

    public static function ensure(FormClient $owner): self
    {
        $slug = ProbeMall::SLUG;
        $home = $owner->signIn($slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        if ($home->status !== 200) {
            throw new RuntimeException('owner sign-in failed for surface seed');
        }
        $owner->ensurePeriod($slug);

        $cashierUser = 'sc' . bin2hex(random_bytes(3));
        $cashierPass = 'cashier-surface';
        $cashier = $owner->post('/' . $slug . '/staff', [
            'display_name' => 'Surface Cash',
            'username' => $cashierUser,
            'password' => $cashierPass,
            'email' => $cashierUser . '@webprobe.test',
        ]);
        if (!str_contains($cashier->body, 'Cashier added.')) {
            throw new RuntimeException('cashier seed failed: ' . HtmlPage::error($cashier->body));
        }

        $booksUser = 'sb' . bin2hex(random_bytes(3));
        $booksPass = 'books-surface';
        $books = $owner->post('/' . $slug . '/staff/books', [
            'display_name' => 'Surface Books',
            'username' => $booksUser,
            'password' => $booksPass,
            'email' => $booksUser . '@webprobe.test',
        ]);
        if (!str_contains($books->body, 'Books login added.')) {
            throw new RuntimeException('books seed failed: ' . HtmlPage::error($books->body));
        }

        $code = 'S' . strtoupper(bin2hex(random_bytes(2)));
        $vendor = $owner->post('/' . $slug . '/vendors', [
            'code' => $code,
            'first_name' => 'Surface',
            'last_name' => 'Vendor',
        ]);
        if (!str_contains($vendor->body, 'Vendor created.')) {
            throw new RuntimeException('vendor seed failed: ' . HtmlPage::error($vendor->body));
        }
        if (preg_match('#/vendors/([0-9a-fA-F-]{36})/sign-in#', $vendor->body, $match) !== 1) {
            throw new RuntimeException('vendor id missing after create');
        }
        $vendorId = strtolower($match[1]);
        $vendorUser = 'sv' . bin2hex(random_bytes(3));
        $vendorPass = 'vendor-surface';
        $signIn = $owner->post('/' . $slug . '/vendors/' . $vendorId . '/sign-in', [
            'username' => $vendorUser,
            'display_name' => 'Surface Vendor',
            'password' => $vendorPass,
            'email' => $vendorUser . '@webprobe.test',
        ]);
        if (!str_contains($signIn->body, 'Vendor sign-in added.')) {
            throw new RuntimeException('vendor sign-in seed failed: ' . HtmlPage::error($signIn->body));
        }

        $itemCode = 't' . bin2hex(random_bytes(3));
        $item = $owner->post('/' . $slug . '/inventory', [
            'code' => $itemCode,
            'description' => 'Surface coat ' . $itemCode,
            'vendor_id' => $vendorId,
            'brand_id' => '',
            'price' => '12.00',
            'barcode' => '',
            'intake_on' => '2026-09-01',
        ]);
        if (!str_contains($item->body, 'Item created.')) {
            throw new RuntimeException('item seed failed: ' . HtmlPage::error($item->body));
        }
        $list = $owner->get('/' . $slug . '/inventory?q=' . rawurlencode($itemCode));
        $itemId = HtmlPage::taggedId($list->body, 'tr', 'item', 'Surface coat ' . $itemCode);

        return new self(
            $vendorId,
            $itemId,
            $itemCode,
            $cashierUser,
            $cashierPass,
            $booksUser,
            $booksPass,
            $vendorUser,
            $vendorPass,
        );
    }
}
