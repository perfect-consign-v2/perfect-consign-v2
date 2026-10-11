<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\ItemWriter;

final class InstantItem
{
    public function __construct(
        private readonly ItemWriter $writer,
        private readonly InventoryStore $items,
        private readonly TicketStore $tickets,
        private readonly TicketLines $lines,
        private readonly BoothVendor $booths,
    ) {
    }

    public function sell(string $session, string $today, string $boothCode, InstantAdd $item): void
    {
        $this->add($session, $today, new InstantAdd(
            $this->booths->vendor($boothCode, $today),
            $item->description,
            $item->price,
            $item->barcode,
            $item->quantity,
            $item->brand,
            $item->attributes,
            $item->discountKind,
            $item->discountValue,
        ));
    }

    public function add(string $session, string $today, InstantAdd $item): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $item->vendorId) !== 1) {
            throw new \InvalidArgumentException('Choose a vendor.');
        }
        $description = trim($item->description);
        if ($description === '') {
            throw new \InvalidArgumentException('Enter a description.');
        }
        if (trim($item->price) === '') {
            throw new \InvalidArgumentException('Enter a price.');
        }
        $saved = $this->writer->entry([
            'id' => '',
            'code' => $this->code(),
            'barcode' => trim($item->barcode),
            'description' => $description,
            'price' => $item->price,
            'quantity' => trim($item->quantity) === '' ? '1' : trim($item->quantity),
            'vendor_id' => $item->vendorId,
            'vendor_label' => '',
            'brand_id' => trim($item->brand->id),
            'brand_label' => trim($item->brand->label),
            'attributes' => $item->attributes,
            'store_take' => '',
            'intake_on' => $today,
            'expires_on' => '',
            'fill_expire' => '',
        ]);
        $this->items->add($saved, $today);
        $this->tickets->scan($session, $saved->code);
        $this->finish($session, $saved->code, $saved->sale->quantity, $item->discountKind, $item->discountValue);
    }

    private function finish(string $session, string $code, int $quantity, string $discountKind, string $discountValue): void
    {
        $cart = $this->tickets->cart($session);
        $lineNo = $this->lineNo($cart['lines'], $code);
        if ($quantity > 1) {
            $this->lines->quantity($cart['ticket_id'], $lineNo, $quantity);
        }
        $this->discount($cart['ticket_id'], $lineNo, $discountKind, $discountValue);
    }

    /**
     * @param list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, quantity: int, commission: int, tax: int, discount: int, line_discount: int, line_discount_kind: string, line_discount_value: string}> $lines
     */
    private function lineNo(array $lines, string $code): int
    {
        $lineNo = 0;
        foreach ($lines as $line) {
            if ($line['code'] === $code) {
                $lineNo = $line['line_no'];
            }
        }
        if ($lineNo < 1) {
            throw new \InvalidArgumentException('That item is not on the ticket.');
        }

        return $lineNo;
    }

    private function discount(string $ticketId, int $lineNo, string $discountKind, string $discountValue): void
    {
        $kind = strtolower(trim($discountKind));
        if ($kind === 'flat') {
            $kind = 'amount';
        }
        if ($kind !== 'percent' && $kind !== 'amount') {
            return;
        }
        if (trim($discountValue) === '') {
            throw new \InvalidArgumentException('Enter a line discount.');
        }
        $posted = TicketAdjustment::discount('apply', $kind, $discountValue, 'other');
        if ($posted['kind'] === null) {
            return;
        }
        $this->lines->discount($ticketId, $lineNo, $posted['kind'], $posted['value'], $posted['reason']);
    }

    private function code(): string
    {
        return 'i' . bin2hex(random_bytes(4));
    }
}
