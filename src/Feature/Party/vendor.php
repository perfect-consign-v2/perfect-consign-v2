<?php

declare(strict_types=1);

use PerfectConsign\Feature\Party\PartyRecord;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $csrf */
/** @var string $today */
/** @var array{id: string, code: string, name: string, first_name: string, last_name: string, phone: string, email: string, address: string, status_label: string, status_badge: string, store_take: string, default_store_take: string, store_take_overridden: bool, agreement_starts_on: string, agreement_ends_on: string, tax_id: string, notes: string, tax_exempt: bool, tax_exempt_number: string, payable_minor: int, rent_minor: int, check_minor: int, owes_minor: int, settled_minor: int, rent_from_sales: bool, current: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}>, upcoming: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}>, past: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}>} $vendor */
/** @var list<array<string, mixed>> $booths */

$sections = [
    'Current Booths' => $vendor['current'],
    'Upcoming Booths' => $vendor['upcoming'],
    'Past' => $vendor['past'],
];
$currentBooth = $vendor['current'][0]['booth_code'] ?? '';
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

    <div class="vendor-layout">
    <div class="vendor-figures">
        <div class="stat vendor-fig-share">
            <div class="stat-label">Their share of sales</div>
            <div class="stat-value"><?= View::text(View::money($vendor['payable_minor'])) ?></div>
        </div>
        <?php if ($vendor['rent_from_sales']): ?>
            <div class="stat vendor-fig-rent">
                <div class="stat-label">Rent from their share</div>
                <div class="stat-value"><?= View::text(View::money($vendor['settled_minor'])) ?></div>
            </div>
        <?php elseif ($vendor['owes_minor'] > 0): ?>
            <a class="stat stat-link stat-action vendor-fig-rent" href="/<?= View::text($slug) ?>/rent?<?= View::text(http_build_query(['q' => $vendor['name'], 'standing' => 'owed'])) ?>">
                <div class="stat-label">Owes the store</div>
                <div class="stat-value"><?= View::text(View::money($vendor['owes_minor'])) ?></div>
                <div class="stat-delta">Collect rent</div>
            </a>
        <?php else: ?>
            <div class="stat vendor-fig-rent">
                <div class="stat-label">Open rent</div>
                <div class="stat-value"><?= View::text(View::money($vendor['rent_minor'])) ?></div>
            </div>
        <?php endif; ?>
        <?php if ($vendor['check_minor'] > 0): ?>
            <a class="stat stat-link stat-action vendor-fig-pay" href="/<?= View::text($slug) ?>/payout?vendor=<?= View::text($vendor['id']) ?>">
                <div class="stat-label">We pay them</div>
                <div class="stat-value"><?= View::text(View::money($vendor['check_minor'])) ?></div>
                <div class="stat-delta">Pay this vendor</div>
            </a>
        <?php elseif ($vendor['rent_from_sales'] && $vendor['owes_minor'] > 0): ?>
            <a class="stat stat-link stat-action vendor-fig-pay" href="/<?= View::text($slug) ?>/rent?<?= View::text(http_build_query(['q' => $vendor['name'], 'standing' => 'owed'])) ?>">
                <div class="stat-label">Owes the store</div>
                <div class="stat-value"><?= View::text(View::money($vendor['owes_minor'])) ?></div>
                <div class="stat-delta">Collect rent</div>
            </a>
        <?php else: ?>
            <div class="stat vendor-fig-pay">
                <div class="stat-label">We pay them</div>
                <div class="stat-value"><?= View::text(View::money(0)) ?></div>
            </div>
        <?php endif; ?>
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
                    <div class="card table-wrap list-dt-wrap">
                        <div class="card-head"><div class="h3"><?= View::text($heading) ?></div></div>
                        <?php
                        $sectionKey = match ($heading) {
                            'Current Booths' => 'current',
                            'Upcoming Booths' => 'upcoming',
                            default => 'past',
                        };
                        ListTable::open(['id' => 'vendor-' . $sectionKey, 'export' => 'vendor-' . $sectionKey]);
                        ListTable::head([
                            ['label' => 'Booth'],
                            ['label' => 'Start'],
                            ['label' => 'End'],
                            ['label' => 'Rent', 'sort' => 'num'],
                        ]);
                        ?>
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
                                        <?php if ($assignment['rent_minor'] === null): ?>
                                            <?= $blank ?>
                                        <?php else: ?>
                                            <?= View::text(View::money($assignment['rent_minor'])) ?>
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
    </div>
</article>
<?php ListTable::assets(); ?>
