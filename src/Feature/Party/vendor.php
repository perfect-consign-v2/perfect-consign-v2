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
/** @var array{username: string, display_name: string}|null $signIn */

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
    <?php
    $openStay = null;
    foreach ($vendor['current'] as $stay) {
        if ($stay['ends_on'] === '') {
            $openStay = $stay;
            break;
        }
    }
    ?>
    <?php if (is_array($openStay)): ?>
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
                            if (!is_string($boothId) || !is_string($boothCode)) {
                                continue;
                            }
                            ?>
                            <option value="<?= View::text($boothId) ?>"><?= View::text($boothCode) ?></option>
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
        <div class="card-head"><div class="h3">Vendor sign-in</div></div>
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
                <label for="vendor_password">Password</label>
                <input class="input" id="vendor_password" name="password" type="password" required minlength="8" autocomplete="off">
            </div>
            <p><button class="btn" type="submit"><?= $signIn === null ? 'Add sign-in' : 'Save sign-in' ?></button></p>
        </div>
    </form>
</article>
<?php ListTable::assets(); ?>
