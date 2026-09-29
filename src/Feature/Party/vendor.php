<?php

declare(strict_types=1);

use PerfectConsign\Feature\Party\MoveInRent;
use PerfectConsign\Feature\Party\PartyRecord;
use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $today */
/** @var array{id: string, code: string, name: string, first_name: string, last_name: string, phone: string, email: string, address: string, status_label: string, status_badge: string, store_take: string, default_store_take: string, store_take_overridden: bool, agreement_starts_on: string, agreement_ends_on: string, tax_id: string, notes: string, tax_exempt: bool, tax_exempt_number: string, payable_minor: int, rent_minor: int, current: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}>, upcoming: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}>, past: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}>} $vendor */
/** @var list<array<string, mixed>> $booths */
/** @var list<array<string, mixed>> $rates */

$sections = [
    'Current Booths' => $vendor['current'],
    'Upcoming Booths' => $vendor['upcoming'],
    'Past' => $vendor['past'],
];
$currentBooth = $vendor['current'][0]['booth_code'] ?? '';
$weOwe = max(0, $vendor['payable_minor']);
$blank = '—';

?>
<article id="vendor-<?= View::text($vendor['id']) ?>">
    <div class="page-head">
        <div class="titles">
            <nav class="breadcrumb">
                <a href="/<?= View::text($slug) ?>/parties">Vendors</a><span class="sep">/</span>
                <span><?= View::text($vendor['code']) ?></span>
            </nav>
            <h1 class="h1"><?= View::text($vendor['name']) ?></h1>
            <ul class="vendor-pills">
                <li><span class="badge badge-accent"><?= View::text($vendor['code']) ?></span></li>
                <li><span class="badge <?= View::text($vendor['status_badge']) ?>"><?= View::text($vendor['status_label']) ?></span></li>
                <li><span class="badge"><?= $currentBooth === '' ? 'Unassigned' : View::text($currentBooth) ?></span></li>
                <?php if ($vendor['store_take_overridden']): ?>
                    <li><span class="badge badge-warning">Overridden</span></li>
                <?php endif; ?>
            </ul>
        </div>
        <a class="btn" href="/<?= View::text($slug) ?>/vendors/<?= View::text($vendor['id']) ?>/edit"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Edit</a>
    </div>

    <div class="grid cols-2 mt-4">
        <a class="stat stat-link" href="/<?= View::text($slug) ?>/payout?vendor=<?= View::text($vendor['id']) ?>">
            <div class="stat-label">We owe them</div>
            <div class="stat-value"><?= View::text(View::money($weOwe)) ?></div>
        </a>
        <div class="stat">
            <div class="stat-label">Open rent</div>
            <div class="stat-value"><?= View::text(View::money($vendor['rent_minor'])) ?></div>
        </div>
    </div>

    <div class="vendor-bench">
        <div class="vendor-identity">
            <div class="record-cards">
                <section class="card" aria-labelledby="vendor-account">
                    <div class="card-head"><div class="h3" id="vendor-account">Account</div></div>
                    <div class="card-body">
                        <?php PartyRecord::list([
                            ['label' => 'Code', 'value' => $vendor['code'], 'href' => ''],
                            ['label' => 'Display name', 'value' => $vendor['name'], 'href' => ''],
                            ['label' => 'First name', 'value' => $vendor['first_name'], 'href' => ''],
                            ['label' => 'Last name', 'value' => $vendor['last_name'], 'href' => ''],
                        ], $blank); ?>
                    </div>
                </section>
                <section class="card" aria-labelledby="vendor-contact">
                    <div class="card-head"><div class="h3" id="vendor-contact">Contact</div></div>
                    <div class="card-body">
                        <?php PartyRecord::list([
                            ['label' => 'Email', 'value' => $vendor['email'], 'href' => $vendor['email'] === '' ? '' : 'mailto:' . $vendor['email']],
                            ['label' => 'Phone', 'value' => $vendor['phone'], 'href' => $vendor['phone'] === '' ? '' : 'tel:' . $vendor['phone']],
                            ['label' => 'Address', 'value' => $vendor['address'], 'href' => ''],
                        ], $blank); ?>
                    </div>
                </section>
                <section class="card" aria-labelledby="vendor-split">
                    <div class="card-head"><div class="h3" id="vendor-split">Store take</div></div>
                    <div class="card-body">
                        <dl class="record-dl">
                            <div class="record-row">
                                <dt>Store take</dt>
                                <dd>
                                    <?= View::text($vendor['store_take']) ?>%
                                    <?php if ($vendor['store_take_overridden']): ?>
                                        <span class="badge badge-warning">Overridden</span>
                                    <?php endif; ?>
                                </dd>
                            </div>
                            <div class="record-row">
                                <dt>Mall default</dt>
                                <dd><?= View::text($vendor['default_store_take']) ?>%</dd>
                            </div>
                        </dl>
                    </div>
                </section>
                <section class="card" aria-labelledby="vendor-agreement">
                    <div class="card-head"><div class="h3" id="vendor-agreement">Agreement and tax</div></div>
                    <div class="card-body">
                        <?php PartyRecord::list([
                            ['label' => 'Starts', 'value' => $vendor['agreement_starts_on'], 'href' => ''],
                            ['label' => 'Ends', 'value' => $vendor['agreement_ends_on'], 'href' => ''],
                            ['label' => 'Tax id', 'value' => $vendor['tax_id'], 'href' => ''],
                            ['label' => 'Tax exempt', 'value' => $vendor['tax_exempt'] ? 'Yes' : 'No', 'href' => ''],
                            ['label' => 'Resale number', 'value' => $vendor['tax_exempt'] ? $vendor['tax_exempt_number'] : '', 'href' => ''],
                        ], $blank); ?>
                    </div>
                </section>
                <section class="card record-span" aria-labelledby="vendor-notes">
                    <div class="card-head"><div class="h3" id="vendor-notes">Notes</div></div>
                    <div class="card-body">
                        <?php if ($vendor['notes'] === ''): ?>
                            <p class="muted">No notes.</p>
                        <?php else: ?>
                            <p class="keep-lines"><?= View::text($vendor['notes']) ?></p>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
            <div class="vendor-stays">
                <?php if ($vendor['current'] === [] && $vendor['upcoming'] === [] && $vendor['past'] === []): ?>
                    <div class="card">
                        <div class="card-body"><p class="muted">No booth yet.</p></div>
                    </div>
                <?php endif; ?>
                <?php foreach ($sections as $heading => $assignments): ?>
                    <?php if ($assignments === []) {
                        continue;
                    } ?>
                    <div class="card table-wrap">
                        <div class="card-head"><div class="h3"><?= View::text($heading) ?></div></div>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Booth</th>
                                    <th>Start</th>
                                    <th>End</th>
                                    <th class="num">Rent</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($assignments as $assignment): ?>
                                <tr>
                                    <td><a href="/<?= View::text($slug) ?>/booths#booth-<?= View::text($assignment['booth_id']) ?>"><?= View::text($assignment['booth_code']) ?></a></td>
                                    <td><?= View::text($assignment['starts_on']) ?></td>
                                    <td>
                                        <?php if ($assignment['ends_on'] === ''): ?>
                                            <form class="row-form" method="post" action="/<?= View::text($slug) ?>/assignments/<?= View::text($assignment['id']) ?>/end">
                                                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                                <label class="sr-only" for="ends-<?= View::text($assignment['id']) ?>">End date</label>
                                                <input class="input" id="ends-<?= View::text($assignment['id']) ?>" name="ends_on" type="date" required min="<?= View::text($assignment['starts_on']) ?>">
                                                <button class="btn btn-sm" type="submit">End</button>
                                            </form>
                                        <?php else: ?>
                                            <?= View::text($assignment['ends_on']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num">
                                        <?php
                                        $thisRate = null;
                                        $thisMonth = '';
                                        foreach ($rates as $rate) {
                                            if (($rate['booth_id'] ?? null) !== $assignment['booth_id']) {
                                                continue;
                                            }
                                            $month = is_string($rate['month_start'] ?? null) ? substr($rate['month_start'], 0, 10) : '';
                                            if ($month !== '' && $month <= $assignment['starts_on'] && $month >= $thisMonth) {
                                                $thisMonth = $month;
                                                $thisRate = View::minor($rate['amount_minor']);
                                            }
                                        }
                                        $monthly = $assignment['rent_minor'] ?? $thisRate;
                                        ?>
                                        <?php if ($monthly === null): ?>
                                            <?= $blank ?>
                                        <?php else: ?>
                                            <?= View::text(View::money(MoveInRent::due($monthly, $assignment['starts_on']))) ?>
                                            <?php if ($assignment['rent_minor'] !== null): ?>
                                                <span class="badge badge-warning">Overridden</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="vendor-work">
            <div class="card">
                <div class="card-head"><div class="h3">Assign a booth</div></div>
                <form method="post" action="/<?= View::text($slug) ?>/assignments">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <input type="hidden" name="vendor_id" value="<?= View::text($vendor['id']) ?>">
                    <div class="card-body stack">
                        <div class="field">
                            <label for="assign_booth">Booth</label>
                            <select class="select" id="assign_booth" name="booth_id" required>
                                <option value="">Choose a booth</option>
                                <?php foreach ($booths as $booth): ?>
                                    <option value="<?= View::text($booth['id']) ?>" data-rent="<?= View::text($booth['rent'] ?? '') ?>"><?= View::text($booth['code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="assign_rent">Rent</label>
                            <input class="input" id="assign_rent" name="rent" inputmode="decimal">
                        </div>
                        <div class="field">
                            <label for="starts_on">Start</label>
                            <input class="input" id="starts_on" name="starts_on" type="date" value="<?= View::text($today) ?>" required>
                        </div>
                        <div class="field">
                            <label for="ends_on">End</label>
                            <input class="input" id="ends_on" name="ends_on" type="date">
                        </div>
                    </div>
                    <div class="card-foot">
                        <button class="btn btn-primary" type="submit">Add assignment</button>
                    </div>
                </form>
                <script>
                    (function () {
                        var select = document.getElementById('assign_booth');
                        var rent = document.getElementById('assign_rent');
                        if (!select || !rent) {
                            return;
                        }
                        var fill = function () {
                            var option = select.options[select.selectedIndex];
                            rent.value = option ? (option.getAttribute('data-rent') || '') : '';
                        };
                        select.addEventListener('change', fill);
                        fill();
                    }());
                </script>
            </div>
        </div>
    </div>
</article>
