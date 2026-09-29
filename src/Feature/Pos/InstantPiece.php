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

    public function add(
        string $session,
        string $today,
        string $vendorId,
        string $description,
        string $price,
        string $barcode,
        string $quantity,
        string $brandId,
        string $brandLabel,
        string $discountKind,
        string $discountValue,
    ): void {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            throw new \InvalidArgumentException('Choose a vendor.');
        }
        $description = trim($description);
        if ($description === '') {
            throw new \InvalidArgumentException('Enter a description.');
        }
        if (trim($price) === '') {
            throw new \InvalidArgumentException('Enter a price.');
        }
        $piece = $this->writer->entry([
            'id' => '',
            'code' => $this->code(),
            'barcode' => trim($barcode),
            'description' => $description,
            'price' => $price,
            'quantity' => trim($quantity) === '' ? '1' : trim($quantity),
            'vendor_id' => $vendorId,
            'vendor_label' => '',
            'brand_id' => trim($brandId),
            'brand_label' => trim($brandLabel),
            'store_take' => '',
            'intake_on' => $today,
            'expires_on' => '',
            'fill_expire' => '',
        ]);
        $this->items->add($piece, $today);
        $this->tickets->scan($session, $piece->code);
        $this->finish($session, $piece->code, $piece->quantity, $discountKind, $discountValue);
    }

    private function finish(string $session, string $code, int $quantity, string $discountKind, string $discountValue): void
    {
        $cart = $this->tickets->cart($session);
        $lineNo = 0;
        foreach ($cart['lines'] as $line) {
            if ($line['code'] === $code) {
                $lineNo = $line['line_no'];
            }
        }
        if ($lineNo < 1) {
            throw new \InvalidArgumentException('That piece is not on the ticket.');
        }
        if ($quantity > 1) {
            $this->lines->quantity($cart['ticket_id'], $lineNo, $quantity);
        }
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
        $this->lines->discount($cart['ticket_id'], $lineNo, $posted['kind'], $posted['value'], $posted['reason']);
    }

    private function code(): string
    {
        return 'i' . bin2hex(random_bytes(4));
    }
}
