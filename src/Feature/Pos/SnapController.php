<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Http\JsonResponse;

final class SnapController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly RegisterGate $gate,
        private readonly MallSettings $mall,
        private readonly SnapSync $snaps,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/snap', ['POST'])]
    public function save(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $request = $this->app->request();
        try {
            $this->snaps->save([
                'session_id' => $this->gate->session($slug),
                'posting_key' => $request->field('posting_key'),
                'business_date' => $request->field('business_date') !== '' ? $request->field('business_date') : $this->mall->today(),
                'item_id' => $request->field('item_id'),
                'price' => Money::parseMinor($request->field('price')),
                'tax' => $this->optionalMoney($request->field('tax')),
                'commission' => 0,
                'quantity' => $this->quantity($request->field('quantity')),
                'commission_bps' => 0,
                'tax_bps' => 0,
            ]);
        } catch (DatabaseRefusal $refusal) {
            $this->send(['error' => $this->app->catchRefusal($refusal), 'posted' => false], 422);
        }
        $this->send(['posted' => false, 'notice' => 'This sale is not posted.']);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/pos/sync', ['POST'])]
    public function sync(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $key = $this->app->request()->field('posting_key');
        try {
            $number = $this->snaps->sync($key);
        } catch (DatabaseRefusal $refusal) {
            $this->send(['error' => $this->app->catchRefusal($refusal), 'posted' => false], 422);
        }
        if ($number === null) {
            $this->send([
                'posted' => false,
                'error' => $this->snaps->refusal($key),
                'notice' => 'This sale is not posted.',
            ], 422);
        }
        $this->send(['posted' => true, 'journal_no' => $number]);
    }

    private function optionalMoney(string $value): int
    {
        if ($value === '' || $value === '0' || $value === '0.00') {
            return 0;
        }

        return Money::parseMinor($value);
    }

    private function quantity(string $value): int
    {
        if ($value === '') {
            return 1;
        }
        if (preg_match('/^[1-9][0-9]{0,5}$/', $value) !== 1) {
            throw new DatabaseRefusal('that snap is not on this register');
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send(array $payload, int $status = 200): never
    {
        JsonResponse::send($payload, $status, 'The ticket could not be sent.');
    }
}
