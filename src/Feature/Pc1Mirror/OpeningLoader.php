<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PerfectConsign\Feature\Opening\OpeningStore;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;

final class OpeningLoader
{
    public function __construct(private readonly OpeningLines $lines = new OpeningLines())
    {
    }

    /**
     * @param array{
     *     vendors: array<string, string>,
     *     booths: array<string, string>,
     *     stored_values: array<string, string>,
     *     items?: int,
     *     assignments?: int
     * } $idMap
     */
    public function load(MirrorMall $mall, MirrorArtifact $artifact, array $idMap, string $postingDate): string
    {
        if (!(new OpeningStore($mall->pdo))->openingAllowed()) {
            throw new MirrorException('opening is refused because the book already has journals');
        }
        $balances = MirrorFields::stringKeyed($artifact->readJson('balances.json'));
        $stored = [];
        foreach ($artifact->readJson('master/stored_values.json') as $row) {
            if (is_array($row)) {
                $stored[] = MirrorFields::stringKeyed($row);
            }
        }

        return $mall->ledger->post(new JournalDraft(
            postingKey: 'opening',
            postingDate: $postingDate,
            source: new JournalSource('opening', 'opening', 'PC1 mirror opening'),
            lines: $this->lines->build($mall, $idMap, $balances, $stored),
        ));
    }
}
