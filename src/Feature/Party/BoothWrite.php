<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\JournalAudit;

final class BoothWrite
{
    public function __construct(
        private readonly PartyStore $booths,
        private readonly AssignmentBook $rates,
        private readonly JournalAudit $audit,
    ) {
    }

    public function addPricedBooth(
        string $code,
        string $name,
        ?int $width,
        ?int $depth,
        ?string $location,
        ?int $amount,
    ): string {
        $id = '';
        $this->audit->within(function () use ($code, $name, $width, $depth, $location, $amount, &$id): void {
            $id = $this->booths->addBooth($code, $name, $width, $depth, $location, $amount);
            $this->audit->record('booth.create', 'booth', $id);
        });

        return $id;
    }

    public function setBooth(
        string $id,
        string $code,
        string $name,
        ?int $width,
        ?int $depth,
        ?string $location,
        ?int $amount,
    ): void {
        $this->audit->within(function () use ($id, $code, $name, $width, $depth, $location, $amount): void {
            $this->booths->saveBooth($id, $code, $name, $width, $depth, $location);
            if ($amount !== null) {
                $this->rates->setAskingRent($id, $amount);
            }
            $this->audit->record('booth.edit', 'booth', $id);
        });
    }

    public function askingRent(string $boothId): ?int
    {
        return $this->rates->askingRent($boothId);
    }

    public function setBoothPrice(string $boothId, int $amount): void
    {
        $this->audit->within(function () use ($boothId, $amount): void {
            $this->rates->setAskingRent($boothId, $amount);
            $this->audit->record('rent.rate', 'booth', $boothId);
        });
    }
}
