<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;

final class DraftAwareDiscard
{
    public function __construct(
        private readonly TicketStore $tickets,
        private readonly TicketDrafts $drafts,
    ) {
    }

    public function run(string $session): void
    {
        $hadDrafts = $this->drafts->hasAny($session);
        try {
            $this->tickets->discard($session);
        } catch (DatabaseRefusal $refusal) {
            if (!$hadDrafts || $refusal->getMessage() !== 'there is no open ticket') {
                throw $refusal;
            }
        }
        $this->drafts->clear($session);
    }
}
