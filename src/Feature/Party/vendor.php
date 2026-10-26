<?php

declare(strict_types=1);

use PerfectConsign\Feature\Party\PartyRecord;
use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $csrf */
/** @var string $today */
/** @var array{id: string, code: string, name: string, first_name: string, last_name: string, phone: string, email: string, email_bounced: bool, address: string, status_label: string, status_badge: string, store_take: string, default_store_take: string, store_take_overridden: bool, agreement_starts_on: string, agreement_ends_on: string, tax_id: string, notes: string, tax_exempt: bool, tax_exempt_number: string, payable_minor: int, rent_minor: int, check_minor: int, owes_minor: int, settled_minor: int, clawback_minor: int, rent_from_sales: bool, current: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}>, upcoming: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}>, past: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}>} $vendor */
/** @var list<array{id: string, code: string, rent: string, available_on: string}> $booths */
/** @var array{username: string, display_name: string, email: string}|null $signIn */
/** @var array<string, list<array{starts_on: string, amount_minor: int}>> $rentHistories */

$rentHistories ??= [];
$sections = [
    'Current Booths' => $vendor['current'],
    'Upcoming Booths' => $vendor['upcoming'],
    'Past' => $vendor['past'],
];
$currentBooth = $vendor['current'][0]['booth_code'] ?? '';
$blank = '—';
$consignmentOnly = ($consignmentOnly ?? false) === true;
$partyLabel = $consignmentOnly ? 'Consignors' : 'Vendors';
$partyOne = $consignmentOnly ? 'consignor' : 'vendor';

?>
<article id="vendor-<?= View::text($vendor['id']) ?>">
    <div class="page-head">
        <div class="titles">
            <nav class="breadcrumb">
                <a href="/<?= View::text($slug) ?>/parties"><?= View::text($partyLabel) ?></a><span class="sep">/</span>
                <span><?= View::text($vendor['code']) ?></span>
            </nav>
            <h1 class="h1"><?= View::text($vendor['name']) ?></h1>
            <ul class="vendor-pills">
                <li><span class="badge badge-accent"><?= View::text($vendor['code']) ?></span></li>
                <li><span class="badge <?= View::text($vendor['status_badge']) ?>"><?= View::text($vendor['status_label']) ?></span></li>
                <?php if (!$consignmentOnly): ?>
                <li><span class="badge"><?= $currentBooth === '' ? 'Unassigned' : View::text($currentBooth) ?></span></li>
                <?php endif; ?>
                <?php if ($vendor['store_take_overridden']): ?>
                    <li><span class="badge badge-warning">Overridden</span></li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="row gap-2">
            <a class="btn" href="<?= View::text(VendorPreview::with('/' . $slug . '/vendor', $vendor['id'])) ?>" target="_blank" rel="noopener noreferrer"><svg aria-hidden="true"><use href="#i-vendor"></use></svg> Open as <?= View::text($partyOne) ?></a>
            <a class="btn" href="/<?= View::text($slug) ?>/letters?vendor=<?= View::text($vendor['id']) ?>">Email</a>
            <a class="btn" href="/<?= View::text($slug) ?>/vendors/<?= View::text($vendor['id']) ?>/edit"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Edit</a>
        </div>
    </div>

    <div class="vendor-layout">
    <div class="vendor-figures">
        <div class="stat vendor-fig-share">
            <div class="stat-label">Their share of sales</div>
            <div class="stat-value"><?= View::text(View::money($vendor['payable_minor'])) ?></div>
        </div>
        <?php if (!$consignmentOnly && $vendor['rent_from_sales']): ?>
            <div class="stat vendor-fig-rent">
                <div class="stat-label">Rent from their share</div>
                <div class="stat-value"><?= View::text(View::money($vendor['settled_minor'])) ?></div>
            </div>
        <?php elseif (!$consignmentOnly && $vendor['owes_minor'] > 0): ?>
            <a class="stat stat-link stat-action vendor-fig-rent" href="/<?= View::text($slug) ?>/rent?<?= View::text(http_build_query(['q' => $vendor['name'], 'standing' => 'owed'])) ?>">
                <div class="stat-label">Owes the store</div>
                <div class="stat-value"><?= View::text(View::money($vendor['owes_minor'])) ?></div>
                <div class="stat-delta">Collect rent</div>
            </a>
        <?php elseif (!$consignmentOnly): ?>
            <div class="stat vendor-fig-rent">
                <div class="stat-label">Open rent</div>
                <div class="stat-value"><?= View::text(View::money($vendor['rent_minor'])) ?></div>
            </div>
        <?php endif; ?>
        <?php if ($vendor['check_minor'] > 0): ?>
            <a class="stat stat-link stat-action vendor-fig-pay" href="/<?= View::text($slug) ?>/payout?vendor=<?= View::text($vendor['id']) ?>">
                <div class="stat-label">We pay them</div>
                <div class="stat-value"><?= View::text(View::money($vendor['check_minor'])) ?></div>
                <div class="stat-delta">Pay this <?= View::text($partyOne) ?></div>
            </a>
        <?php elseif (!$consignmentOnly && $vendor['rent_from_sales'] && $vendor['owes_minor'] > 0): ?>
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
        <?php if (($vendor['clawback_minor'] ?? 0) > 0): ?>
            <div class="stat vendor-fig-clawback">
                <div class="stat-label">They owe us (returns)</div>
                <div class="stat-value"><?= View::text(View::money($vendor['clawback_minor'])) ?></div>
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
                            ['label' => 'Sale mail', 'value' => $vendor['email_bounced'] ? 'Bounced' : 'Clear', 'href' => ''],
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
                                <dt><?= $consignmentOnly ? 'Store default' : 'Mall default' ?></dt>
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
                            ['label' => 'Paid this year', 'value' => View::money(is_int($vendor['year_paid_minor'] ?? null) ? $vendor['year_paid_minor'] : 0), 'href' => ''],
                            ['label' => 'Who may discount', 'value' => is_string($vendor['discount_by'] ?? null) ? $vendor['discount_by'] : '', 'href' => ''],
                            ['label' => 'Days goods stay', 'value' => is_string($vendor['keep_days'] ?? null) ? $vendor['keep_days'] : '', 'href' => ''],
                            ['label' => 'At expiry', 'value' => is_string($vendor['expiry_action'] ?? null) ? $vendor['expiry_action'] : '', 'href' => ''],
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
            <?php if (!$consignmentOnly): ?>
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
                                    <td><?= View::date($assignment['starts_on']) ?></td>
                                    <td>
                                        <?php if ($assignment['ends_on'] === ''): ?>
                                            <form class="row-form" method="post" action="/<?= View::text($slug) ?>/assignments/<?= View::text($assignment['id']) ?>/end">
                                                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                                <label class="sr-only" for="ends-<?= View::text($assignment['id']) ?>">End date</label>
                                                <input class="input" id="ends-<?= View::text($assignment['id']) ?>" name="ends_on" type="date" required min="<?= View::text($assignment['starts_on']) ?>">
                                                <button class="btn btn-sm" type="submit">End</button>
                                            </form>
                                        <?php else: ?>
                                            <?= View::date($assignment['ends_on']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num">
                                        <?php
                                        $rates = $rentHistories[$assignment['id']] ?? [];
                                        $openStay = $assignment['ends_on'] === '';
                                        ?>
                                        <?php if ($assignment['rent_minor'] === null): ?>
                                            <?= $blank ?>
                                        <?php else: ?>
                                            <div><?= View::text(View::money($assignment['rent_minor'])) ?></div>
                                        <?php endif; ?>
                                        <?php if ($openStay): ?>
                                            <form class="row-form stack" method="post" action="/<?= View::text($slug) ?>/assignments/<?= View::text($assignment['id']) ?>/rent">
                                                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                                <label class="sr-only" for="rent-amount-<?= View::text($assignment['id']) ?>">New rent</label>
                                                <input class="input" id="rent-amount-<?= View::text($assignment['id']) ?>" name="rent" inputmode="decimal" required placeholder="New rent">
                                                <label class="sr-only" for="rent-starts-<?= View::text($assignment['id']) ?>">Effective date</label>
                                                <input class="input" id="rent-starts-<?= View::text($assignment['id']) ?>" name="starts_on" type="date" required min="<?= View::text($assignment['starts_on']) ?>" value="<?= View::text($today) ?>">
                                                <button class="btn btn-sm" type="submit">Change rent</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (count($rates) > 1 || ($rates !== [] && $openStay)): ?>
                                            <ul class="muted text-sm stack">
                                                <?php foreach ($rates as $rate): ?>
                                                    <li><?= View::date($rate['starts_on']) ?> — <?= View::text(View::money($rate['amount_minor'])) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!$consignmentOnly): ?>
        <div class="vendor-work">
            <div class="card">
                <div class="card-head"><div class="h3">Assign a booth</div></div>
                <form method="post" action="/<?= View::text($slug) ?>/assignments" data-today="<?= View::text($today) ?>">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <input type="hidden" name="vendor_id" value="<?= View::text($vendor['id']) ?>">
                    <div class="card-body stack">
                        <div class="field">
                            <label for="assign_booth">Booth</label>
                            <select class="select" id="assign_booth" name="booth_id" required>
                                <option value="">Choose a booth</option>
                                <?php foreach ($booths as $booth): ?>
                                    <option value="<?= View::text($booth['id']) ?>" data-rent="<?= View::text($booth['rent']) ?>" data-available="<?= View::text($booth['available_on']) ?>"><?= View::text($booth['code']) ?><?php if ($booth['available_on'] !== ''): ?> — available <?= View::date($booth['available_on']) ?><?php endif; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="assign_rent">Rent <span class="badge badge-warning" id="assign_rent_badge" hidden></span></label>
                            <input class="input" id="assign_rent" name="rent" inputmode="decimal">
                            <p class="muted text-sm">Default booth rent. You can change it.</p>
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
                        var start = document.getElementById('starts_on');
                        var badge = document.getElementById('assign_rent_badge');
                        var form = select ? select.form : null;
                        if (!select || !rent || !start || !form) {
                            return;
                        }
                        var today = form.getAttribute('data-today') || '';
                        var cents = function (text) {
                            var amount = String(text || '').replace(/[$,\s]/g, '');
                            var match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(amount);
                            if (!match) {
                                return null;
                            }
                            var fraction = (match[2] || '').padEnd(2, '0');
                            return (parseInt(match[1], 10) * 100) + parseInt(fraction, 10);
                        };
                        var mark = function () {
                            if (!badge) {
                                return;
                            }
                            var option = select.options[select.selectedIndex];
                            var asking = cents(option ? option.getAttribute('data-rent') : '');
                            var typed = cents(rent.value);
                            badge.hidden = asking === null || typed === null || typed === asking;
                            badge.textContent = badge.hidden ? '' : 'Overridden';
                        };
                        var fill = function () {
                            var option = select.options[select.selectedIndex];
                            var available = option ? (option.getAttribute('data-available') || '') : '';
                            rent.value = option ? (option.getAttribute('data-rent') || '') : '';
                            if (available !== '') {
                                start.value = available;
                                start.min = available;
                            } else {
                                start.value = today;
                                start.removeAttribute('min');
                            }
                            mark();
                        };
                        select.addEventListener('change', fill);
                        rent.addEventListener('input', mark);
                        fill();
                    }());
                </script>
            </div>
        </div>
        <?php endif; ?>
    </div>
    </div>
    <?php
    $openStay = null;
    foreach ($vendor['current'] as $stay) {
        if ($stay['ends_on'] === '') {
            $openStay = $stay;
            break;
        }
    }
    ?>
    <?php if (!$consignmentOnly && is_array($openStay)): ?>
        <form class="card mt-6" method="post" action="/<?= View::text($slug) ?>/vendors/<?= View::text($vendor['id']) ?>/move">
            <div class="card-head"><div class="h3">Move to another booth</div></div>
            <div class="card-body stack">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <input type="hidden" name="assignment_id" value="<?= View::text($openStay['id']) ?>">
                <input type="hidden" name="rent" value="">
                <p class="muted">They leave on the date you pick. The next booth starts the following day. Rent for the month they leave stays the full month. Rent for the move-in month is the days left.</p>
                <div class="field">
                    <label for="move_booth">Next booth</label>
                    <select class="select" id="move_booth" name="booth_id" required>
                        <option value="">Choose a booth</option>
                        <?php foreach ($booths as $booth): ?>
                            <?php
                            $boothId = $booth['id'] ?? null;
                            $boothCode = $booth['code'] ?? null;
                            $available = $booth['available_on'] ?? '';
                            if (!is_string($boothId) || !is_string($boothCode) || !is_string($available)) {
                                continue;
                            }
                            ?>
                            <option value="<?= View::text($boothId) ?>"><?= View::text($boothCode) ?><?php if ($available !== ''): ?> — available <?= View::date($available) ?><?php endif; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="move_out">Move-out date</label>
                    <input class="input" id="move_out" name="ends_on" type="date" required min="<?= View::text($openStay['starts_on']) ?>">
                </div>
                <p><button class="btn btn-primary" type="submit">Move</button></p>
            </div>
        </form>
    <?php endif; ?>
    <form class="card mt-6" method="post" action="/<?= View::text($slug) ?>/vendors/<?= View::text($vendor['id']) ?>/sign-in" autocomplete="off">
        <div class="card-head"><div class="h3"><?= $consignmentOnly ? 'Consignor sign-in' : 'Vendor sign-in' ?></div></div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <?php if ($signIn === null): ?>
                <p class="muted">They see their check, their sales, and stickers for their own items.</p>
            <?php else: ?>
                <p class="muted">Sign-in name is <?= View::text($signIn['username']) ?>. A new password replaces the old one.</p>
            <?php endif; ?>
            <div class="field">
                <label for="vendor_display_name">Name</label>
                <input class="input" id="vendor_display_name" name="display_name" required maxlength="80" value="<?= View::text($signIn === null ? '' : $signIn['display_name']) ?>">
            </div>
            <div class="field">
                <label for="vendor_username">Sign-in name</label>
                <input class="input" id="vendor_username" name="username" required maxlength="63" autocapitalize="none" autocomplete="off" value="<?= View::text($signIn === null ? '' : $signIn['username']) ?>">
            </div>
            <div class="field">
                <label for="vendor_email">Recovery email</label>
                <input class="input" id="vendor_email" name="email" type="email" required maxlength="200" autocomplete="off" value="<?= View::text($signIn !== null && $signIn['email'] !== '' ? $signIn['email'] : $vendor['email']) ?>">
            </div>
            <div class="field">
                <label for="vendor_password">Password</label>
                <input class="input" id="vendor_password" name="password" type="password" required minlength="8" autocomplete="off">
            </div>
            <p><button class="btn" type="submit"><?= $signIn === null ? 'Add sign-in' : 'Save sign-in' ?></button></p>
        </div>
    </form>
</article>
<?php ListTable::assets(); ?>
