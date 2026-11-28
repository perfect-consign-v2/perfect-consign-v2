<?php

declare(strict_types=1);

use PerfectConsign\Bootstrap\Permission;
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
/** @var array<string, int> $paidAhead */
/** @var array{vendor_id: string, name: string, ends_on: string, items: int}|null $leaving */
/** @var list<array{code: string, said: string}> $countHistory */
/** @var list<array{kind: string, booth: string, open: int, since: string, eligible_on: string}> $debts */
/** @var list<array{journal_id: string, said: string, left: int}> $writeOffs */
/** @var array{months: list<array{month: string, charges: list<array{booth: string, kind: string, what: string, date: string, journal_id: string, charged: int, waived: bool, paid: int, owed: int, payments: list<array{number: string, date: string, amount: int, how: string, register: string, cashier: string, run_id: string, run_date: string, journal_id: string, paid_by: string}>}>}>, more: bool}|null $rentPaid */

$rentHistories ??= [];
$paidAhead ??= [];
$leaving ??= null;
$countHistory ??= [];
$debts ??= [];
$writeOffs ??= [];
$sections = [
    'Current Booths' => $vendor['current'],
    'Upcoming Booths' => $vendor['upcoming'],
    'Past Booths' => $vendor['past'],
];
$blank = '—';
$actorRole = is_string($actorRole ?? null) ? $actorRole : '';
$readsReports = Permission::allows($actorRole, 'report.read') || Permission::allows($actorRole, 'settings.edit');
$consignmentOnly = ($consignmentOnly ?? false) === true;
$partyLabel = $consignmentOnly ? 'Consignors' : 'Vendors';
$partyOne = $consignmentOnly ? 'consignor' : 'vendor';
$sellerPrefix = is_string($sellerPrefix ?? null) && $sellerPrefix !== ''
    ? $sellerPrefix
    : ($consignmentOnly ? 'consignors' : 'vendors');
$sellerBase = is_string($sellerBase ?? null) && $sellerBase !== ''
    ? $sellerBase
    : '/' . $slug . '/' . $sellerPrefix;
$boothCodes = [];
foreach ($vendor['current'] as $stay) {
    $boothCodes[] = $stay['booth_code'];
}
$boothLine = match (count($boothCodes)) {
    0 => 'No booth',
    1 => 'Booth ' . $boothCodes[0],
    default => 'Booths ' . implode(' · ', $boothCodes),
};

?>
<article id="vendor-<?= View::text($vendor['id']) ?>">
    <div class="page-head">
        <div class="titles">
            <nav class="breadcrumb">
                <a href="<?= View::text($sellerBase) ?>"><?= View::text($partyLabel) ?></a><span class="sep">/</span>
                <span><?= View::text($vendor['code']) ?></span>
            </nav>
            <h1 class="h1"><?= View::text($vendor['name']) ?></h1>
            <ul class="vendor-pills">
                <li><span class="badge <?= View::text($vendor['status_badge']) ?>"><?= View::text($vendor['status_label']) ?></span></li>
                <?php if (!$consignmentOnly): ?>
                <li><span class="badge"><?= View::text($boothLine) ?></span></li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="row gap-2">
            <a class="btn" href="<?= View::text(VendorPreview::with('/' . $slug . '/vendor', $vendor['id'])) ?>" target="_blank" rel="noopener noreferrer"><svg aria-hidden="true"><use href="#i-vendor"></use></svg> Open as <?= View::text($partyOne) ?></a>
            <a class="btn" href="/<?= View::text($slug) ?>/letters?vendor=<?= View::text($vendor['id']) ?>">Email</a>
            <?php if ($readsReports): ?>
                <a class="btn" href="/<?= View::text($slug) ?>/reports/vendor-register?<?= View::text(http_build_query(['vendor' => $vendor['id']])) ?>">Register</a>
            <?php endif; ?>
            <a class="btn" href="<?= View::text($sellerBase) ?>/<?= View::text($vendor['id']) ?>/close-out">Close out</a>
            <a class="btn" href="<?= View::text($sellerBase) ?>/<?= View::text($vendor['id']) ?>/edit"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Edit</a>
        </div>
    </div>
    <?php if ($leaving !== null): ?>
        <div class="alert alert-warning mt-4" role="status">
            <?= View::text($vendor['name']) ?>'s booth ends <?= View::date($leaving['ends_on']) ?>.
            <?= View::text((string) $leaving['items']) ?> <?= $leaving['items'] === 1 ? 'item is' : 'items are' ?> still in the system.
            Rent for the month they leave is the full month.
            <a href="<?= View::text($sellerBase) ?>/<?= View::text($vendor['id']) ?>/close-out">Close out</a>
        </div>
    <?php endif; ?>

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
                        <?php
                        $phoneHref = View::phoneTel($vendor['phone']);
                        PartyRecord::list([
                            ['label' => 'Email', 'value' => $vendor['email'], 'href' => $vendor['email'] === '' ? '' : 'mailto:' . $vendor['email']],
                            ['label' => 'Sale mail', 'value' => $vendor['email_bounced'] ? 'Bounced' : 'Clear', 'href' => ''],
                            ['label' => 'Phone', 'value' => View::phone($vendor['phone']), 'href' => $phoneHref],
                            ['label' => 'Street', 'value' => is_string($vendor['address'] ?? null) ? $vendor['address'] : '', 'href' => ''],
                            ['label' => 'City', 'value' => is_string($vendor['city'] ?? null) ? $vendor['city'] : '', 'href' => ''],
                            ['label' => 'State', 'value' => is_string($vendor['state'] ?? null) ? $vendor['state'] : '', 'href' => ''],
                            ['label' => 'ZIP', 'value' => is_string($vendor['postal'] ?? null) ? $vendor['postal'] : '', 'href' => ''],
                        ], $blank);
                        ?>
                    </div>
                </section>
                <section class="card" aria-labelledby="vendor-split">
                    <div class="card-head"><div class="h3" id="vendor-split">Store take override</div></div>
                    <div class="card-body">
                        <dl class="record-dl">
                            <div class="record-row">
                                <dt>Store take override</dt>
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
                                    <td>
                                        <a href="/<?= View::text($slug) ?>/booths#booth-<?= View::text($assignment['booth_id']) ?>"><?= View::text($assignment['booth_code']) ?></a>
                                        <?php if (isset($paidAhead[$assignment['id']])) {
                                            $held = $paidAhead[$assignment['id']];
                                            $isOwner = $actorRole === 'owner';
                                            require __DIR__ . '/vendor-reservation.php';
                                        } ?>
                                    </td>
                                    <td><?= View::date($assignment['starts_on']) ?></td>
                                    <td>
                                        <?php if ($assignment['ends_on'] === ''): ?>
                                            <form class="row-form" method="post" action="/<?= View::text($slug) ?>/assignments/<?= View::text($assignment['id']) ?>/end">
                                                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                                <label class="sr-only" for="ends-<?= View::text($assignment['id']) ?>">End date</label>
                                                <input class="input" id="ends-<?= View::text($assignment['id']) ?>" name="ends_on" type="date" required min="<?= View::text($assignment['starts_on']) ?>">
                                                <button class="btn btn-sm" type="submit">End booth</button>
                                                <span class="muted text-sm">The month it ends stays full rent.</span>
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
            <?php
            $booking = $booking ?? null;
            $earliestStart = is_string($earliestStart ?? null) ? $earliestStart : $today;
            require __DIR__ . '/vendor-booking.php';
            ?>
        </div>
        <?php endif; ?>
    </div>
    </div>
    <?php if (!$consignmentOnly && isset($rentPaid)) {
        $rentPaidAll = ($rentPaidAll ?? false) === true;
        require __DIR__ . '/vendor-rent-history.php';
    } ?>
    <?php $isOwner = $actorRole === 'owner'; require __DIR__ . '/vendor-debts.php'; ?>
    <?php if ($countHistory !== []): ?>
        <section class="card mt-6" aria-labelledby="vendor-count-history">
            <div class="card-head"><div class="h3" id="vendor-count-history">Item count history</div></div>
            <div class="card-body">
                <ul class="stack text-sm">
                    <?php foreach ($countHistory as $line): ?>
                        <li><strong><?= View::text($line['code']) ?></strong> · <?= View::text($line['said']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    <?php endif; ?>
    <form class="card mt-6" method="post" action="<?= View::text($sellerBase) ?>/<?= View::text($vendor['id']) ?>/customer-role">
        <div class="card-head"><div class="h3">Also a customer</div></div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <p class="muted">Same person can buy at the register. This adds the customer role on their party.</p>
            <p><button class="btn" type="submit">Add customer role</button></p>
        </div>
    </form>
    <form class="card mt-6" method="post" action="<?= View::text($sellerBase) ?>/<?= View::text($vendor['id']) ?>/sign-in" autocomplete="off">
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
                <label for="vendor_email">Email</label>
                <input class="input" id="vendor_email" name="email" type="email" required maxlength="200" autocomplete="off" value="<?= View::text($signIn !== null && $signIn['email'] !== '' ? $signIn['email'] : $vendor['email']) ?>">
            </div>
            <div class="field">
                <label for="vendor_password">Password</label>
                <?php View::requiredPasswordField(id: 'vendor_password', name: 'password', minlength: 8, autocomplete: 'off'); ?>
            </div>
            <p><button class="btn" type="submit"><?= $signIn === null ? 'Add sign-in' : 'Save sign-in' ?></button></p>
        </div>
    </form>
</article>
<?php ListTable::assets(); ?>
