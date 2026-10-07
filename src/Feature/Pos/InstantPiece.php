<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\PieceWriter;

final class InstantPiece
{
    public function __construct(
        private readonly PieceWriter $writer,
        private readonly InventoryStore $items,
        private readonly TicketStore $tickets,
        private readonly TicketLines $lines,
    ) {
    }

    public function add(string $session, string $today, InstantAdd $piece): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $piece->vendorId) !== 1) {
            throw new \InvalidArgumentException('Choose a vendor.');
        }
        $description = trim($piece->description);
        if ($description === '') {
            throw new \InvalidArgumentException('Enter a description.');
        }
        if (trim($piece->price) === '') {
            throw new \InvalidArgumentException('Enter a price.');
        }
        $saved = $this->writer->entry([
            'id' => '',
            'code' => $this->code(),
            'barcode' => trim($piece->barcode),
            'description' => $description,
            'price' => $piece->price,
            'quantity' => trim($piece->quantity) === '' ? '1' : trim($piece->quantity),
            'vendor_id' => $piece->vendorId,
            'vendor_label' => '',
            'brand_id' => trim($piece->brand->id),
            'brand_label' => trim($piece->brand->label),
            'attributes' => $piece->attributes,
            'store_take' => '',
            'intake_on' => $today,
            'expires_on' => '',
            'fill_expire' => '',
        ]);
        $this->items->add($saved, $today);
        $this->tickets->scan($session, $saved->code);
        $this->finish($session, $saved->code, $saved->sale->quantity, $piece->discountKind, $piece->discountValue);
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
            throw new \InvalidArgumentException('That piece is not on the ticket.');
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
