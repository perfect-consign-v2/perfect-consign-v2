<?php

declare(strict_types=1);

use PerfectConsign\Feature\Register\DenominationCount;
use PerfectConsign\Shared\Http\View;

/** @var string $templateDirectory */
/** @var string $slug */
/** @var string $csrf */
/** @var string $cashier */
/** @var string $today */
/** @var string $modal */
/** @var string $registerId */
/** @var bool $confirmVariance */
/** @var array<string, mixed> $draft */
/** @var array<int, string> $denominations */
/** @var list<array<string, mixed>> $registers */
/** @var list<array{id: string, name: string, opened: string}> $leftOpen */

$labels = [
    'cash' => 'Cash',
    'card' => 'Card',
    'checks' => 'Checks',
    'gift_certificate' => 'Gift certificates',
    'gift_card' => 'Gift cards',
    'store_credit' => 'Store credit',
];
$draftCounts = is_array($draft['counts'] ?? null) ? $draft['counts'] : [];
$draftCard = is_string($draft['card'] ?? null) ? $draft['card'] : '0.00';
$draftChecks = is_string($draft['checks'] ?? null) ? $draft['checks'] : '0.00';
$draftCash = is_string($draft['cash'] ?? null) ? $draft['cash'] : '';
$openAction = $registerId !== '' ? '/' . $slug . '/registers/' . $registerId . '/open' : '';
$openCounts = $modal === 'register-open' && is_array($draft['counts'] ?? null) ? $draft['counts'] : [];
$openFloat = $modal === 'register-open' && is_string($draft['float'] ?? null) ? $draft['float'] : '';
$openCashier = $modal === 'register-open' && is_string($draft['cashier'] ?? null) && $draft['cashier'] !== ''
    ? $draft['cashier']
    : $cashier;
$renameName = $modal === 'register-rename' && is_string($draft['name'] ?? null) ? $draft['name'] : '';
$renameAction = $modal === 'register-rename' && $registerId !== ''
    ? '/' . $slug . '/registers/' . $registerId
    : '';
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Registers</h1>
        <?php if (($chooseRegister ?? false) === true): ?>
            <p>Two or more registers are open. Choose the active register.</p>
        <?php endif; ?>
    </div>
    <a class="btn btn-primary" href="/<?= View::text($slug) ?>/registers/new"><svg aria-hidden="true"><use href="#i-plus"></use></svg> New register</a>
</div>

<div class="register-grid mt-6">
<?php foreach ($registers as $register): ?>
    <?php
    $id = is_string($register['id'] ?? null) ? $register['id'] : '';
    $name = is_string($register['name'] ?? null) ? $register['name'] : '';
    $open = ($register['open'] ?? false) === true;
    $tenders = is_array($register['tenders'] ?? null) ? $register['tenders'] : [];
    $drops = is_array($register['drops'] ?? null) ? $register['drops'] : [];
    ?>
    <article class="card" id="register-<?= View::text($id) ?>">
        <div class="card-head">
            <div class="h3"><?= View::text($name) ?></div>
            <?php if ($open && ($register['session_id'] ?? '') === ($activeSessionId ?? '')): ?>
                <span class="badge badge-dot badge-success">Active</span>
            <?php else: ?>
                <span class="badge badge-dot <?= $open ? 'badge-success' : 'badge-danger' ?>"><?= $open ? 'Open' : 'Closed' ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body stack">
            <?php if ($open): ?>
                <p>Cashier <?= View::text(is_string($register['cashier'] ?? null) ? $register['cashier'] : '') ?></p>
                <p class="muted">Opened <?= View::text(is_string($register['opened_at'] ?? null) ? $register['opened_at'] : '') ?></p>
                <p>Opening float <?= View::text(is_string($register['opening_cash'] ?? null) ? $register['opening_cash'] : '') ?></p>
                <p>Drawer expected <?= View::text(is_string($register['expected_cash'] ?? null) ? $register['expected_cash'] : '') ?></p>
            <?php else: ?>
                <p class="muted">No shift is open.</p>
            <?php endif; ?>
            <div class="row gap-2">
                <?php if ($open): ?>
                    <?php if (($register['session_id'] ?? '') !== ($activeSessionId ?? '')): ?>
                        <form method="post" action="/<?= View::text($slug) ?>/registers/<?= View::text($id) ?>/use">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <button class="btn btn-primary" type="submit">Make active</button>
                        </form>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="button" data-open-modal="close-<?= View::text($id) ?>">Close register</button>
                    <button class="btn" type="button" data-open-modal="drop-<?= View::text($id) ?>">Drop cash</button>
                <?php else: ?>
                    <button class="btn btn-primary" type="button" data-open-modal="register-open" data-brand-action="/<?= View::text($slug) ?>/registers/<?= View::text($id) ?>/open">Open register</button>
                <?php endif; ?>
                <a class="btn" href="/<?= View::text($slug) ?>/registers/<?= View::text($id) ?>/shifts">Shift reports</a>
                <?php if (!$open && ($register['shifts'] ?? 0) === 0 && count($registers) > 1): ?>
                    <button class="btn" type="button" data-open-modal="delete-<?= View::text($id) ?>">Delete</button>
                <?php endif; ?>
                <button class="btn" type="button" data-open-modal="register-rename" data-brand-action="/<?= View::text($slug) ?>/registers/<?= View::text($id) ?>" data-brand-name="<?= View::text($name) ?>">Rename</button>
            </div>
        </div>
    </article>

    <?php if (!$open && ($register['shifts'] ?? 0) === 0 && count($registers) > 1): ?>
        <div id="delete-<?= View::text($id) ?>" class="modal" role="dialog" aria-modal="true" aria-labelledby="delete-title-<?= View::text($id) ?>"<?= $modal === 'delete-' . $id ? '' : ' hidden' ?>>
            <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/registers/<?= View::text($id) ?>/delete">
                <div class="card-head">
                    <?php $closeModal = 'delete-' . $id; require $templateDirectory . '/Shared/Web/modal-x.php'; ?>
                    <div class="h3" id="delete-title-<?= View::text($id) ?>">Delete <?= View::text($name) ?></div>
                </div>
                <div class="card-body stack">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <p><?= View::text($name) ?> has never been opened.</p>
                    <div class="row gap-2">
                        <button class="btn btn-primary" type="submit">Delete register</button>
                        <button class="btn" type="button" data-close-modal="delete-<?= View::text($id) ?>">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php
    $mustClose = false;
    foreach ($leftOpen as $stale) {
        if (is_array($stale) && ($stale['id'] ?? '') === $id) {
            $mustClose = true;
        }
    }
    ?>
    <?php
    $expectedCash = is_int($register['expected_minor'] ?? null) ? $register['expected_minor'] : 0;
    $expectedCard = is_int($tenders['card'] ?? null) ? $tenders['card'] : 0;
    $expectedChecks = is_int($tenders['checks'] ?? null) ? $tenders['checks'] : 0;
    ?>
    <?php if ($open): ?>
        <div id="close-<?= View::text($id) ?>" class="modal" role="dialog" aria-modal="true" aria-labelledby="close-title-<?= View::text($id) ?>"<?= $mustClose ? ' data-lock' : '' ?><?= $modal === 'close-' . $id ? '' : ' hidden' ?>>
            <form id="close-form-<?= View::text($id) ?>" class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/registers/<?= View::text($id) ?>/close" data-close-sheet data-expected-cash="<?= View::text((string) $expectedCash) ?>" data-expected-card="<?= View::text((string) $expectedCard) ?>" data-expected-checks="<?= View::text((string) $expectedChecks) ?>">
                <div class="card-head">
                    <?php if ($mustClose): ?>
                        <button class="modal-x" type="submit" formnovalidate formaction="/<?= View::text($slug) ?>/registers/close-cancel" aria-label="Close">
                            <svg aria-hidden="true"><use href="#i-x"></use></svg>
                        </button>
                    <?php else: ?>
                        <?php $closeModal = 'close-' . $id; require $templateDirectory . '/Shared/Web/modal-x.php'; ?>
                    <?php endif; ?>
                    <div class="h3" id="close-title-<?= View::text($id) ?>">Close <?= View::text($name) ?></div>
                </div>
                <div class="card-body stack">
                    <?php if ($confirmVariance && $modal === 'close-' . $id): ?>
                        <div class="register-mismatch" role="alert">
                            <p class="register-mismatch__title">Counted cash does not match the drawer.</p>
                            <p class="register-mismatch__amount"><?= View::text(DenominationCount::difference(DenominationCount::money(ltrim($draftCash, '$'), true), $expectedCash)) ?></p>
                            <p class="register-mismatch__next">Confirm the difference to close.</p>
                        </div>
                    <?php endif; ?>
                    <?php if (($register['open_ticket'] ?? false) === true): ?>
                        <div class="alert alert-danger" role="alert">
                            <p class="error">A sale is still open on this register. Finish it or cancel it, then close.</p>
                            <p><a class="text-link" href="/<?= View::text($slug) ?>/pos">Open the sale</a></p>
                        </div>
                    <?php endif; ?>
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <?php foreach (DenominationCount::KEYS as $key): ?>
                        <?php $saved = $modal === 'close-' . $id ? ($draftCounts[(string) $key] ?? 0) : 0; ?>
                        <input type="hidden" name="count_<?= View::text((string) $key) ?>" value="<?= View::text($saved) ?>">
                    <?php endforeach; ?>
                    <table class="close-sheet">
                        <colgroup>
                            <col>
                            <col>
                            <col>
                            <col class="diff-col">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Tender</th>
                                <th>Expected</th>
                                <th>Your count</th>
                                <th class="diff-cell">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <th scope="row">Cash</th>
                                <td><?= View::text(DenominationCount::dollars($expectedCash)) ?></td>
                                <td>
                                    <input class="input" id="counted-cash-<?= View::text($id) ?>" name="counted_cash" inputmode="decimal" data-counted="cash" value="<?= $modal === 'close-' . $id ? View::text($draftCash) : '' ?>" required>
                                    <button class="btn" type="button" data-open-modal="register-count" data-count-form="close-form-<?= View::text($id) ?>">Count bills and coins</button>
                                </td>
                                <td class="diff-cell" data-difference="cash">—</td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="counted-card-<?= View::text($id) ?>">Card</label></th>
                                <td><?= View::text(DenominationCount::dollars($expectedCard)) ?></td>
                                <td><input class="input" id="counted-card-<?= View::text($id) ?>" name="counted_card" inputmode="decimal" data-counted="card" value="<?= $modal === 'close-' . $id ? View::text($draftCard) : '0.00' ?>"></td>
                                <td class="diff-cell" data-difference="card">—</td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="counted-checks-<?= View::text($id) ?>">Checks</label></th>
                                <td><?= View::text(DenominationCount::dollars($expectedChecks)) ?></td>
                                <td><input class="input" id="counted-checks-<?= View::text($id) ?>" name="counted_checks" inputmode="decimal" data-counted="checks" value="<?= $modal === 'close-' . $id ? View::text($draftChecks) : '0.00' ?>"></td>
                                <td class="diff-cell" data-difference="checks">—</td>
                            </tr>
                            <?php foreach (['gift_certificate' => 'Gift certificates', 'gift_card' => 'Gift cards', 'store_credit' => 'Store credit'] as $role => $label): ?>
                                <?php $net = is_int($tenders[$role] ?? null) ? $tenders[$role] : 0; ?>
                                <tr>
                                    <th scope="row"><?= View::text($label) ?></th>
                                    <td><?= View::text(DenominationCount::dollars($net)) ?></td>
                                    <td class="muted">Posted</td>
                                    <td class="diff-cell">—</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($drops !== []): ?>
                        <p>Cash drops</p>
                        <ul>
                            <?php foreach ($drops as $drop): ?>
                                <?php if (!is_array($drop)) { continue; } ?>
                                <li><?= View::text(is_string($drop['when'] ?? null) ? $drop['when'] : '') ?> <?= View::text(is_string($drop['amount'] ?? null) ? $drop['amount'] : '') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="muted">No cash drops this shift.</p>
                    <?php endif; ?>
                    <?php if ($confirmVariance && $modal === 'close-' . $id): ?>
                        <input type="hidden" name="confirm_variance" value="yes">
                    <?php endif; ?>
                    <div class="row gap-2">
                        <button class="btn btn-primary" type="submit"><?= $confirmVariance && $modal === 'close-' . $id ? 'Confirm and close' : 'Close register' ?></button>
                        <?php if ($mustClose): ?>
                            <button class="btn" type="submit" formnovalidate formaction="/<?= View::text($slug) ?>/registers/close-cancel">Cancel</button>
                        <?php else: ?>
                            <button class="btn" type="button" data-close-modal="close-<?= View::text($id) ?>">Cancel</button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
        <div id="drop-<?= View::text($id) ?>" class="modal" role="dialog" aria-modal="true" aria-labelledby="drop-title-<?= View::text($id) ?>"<?= $modal === 'drop-' . $id ? '' : ' hidden' ?>>
            <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/registers/<?= View::text($id) ?>/drop">
                <div class="card-head">
                    <?php $closeModal = 'drop-' . $id; require $templateDirectory . '/Shared/Web/modal-x.php'; ?>
                    <div class="h3" id="drop-title-<?= View::text($id) ?>">Drop cash</div>
                </div>
                <div class="card-body stack">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <label for="drop-amount-<?= View::text($id) ?>">Amount</label>
                    <input class="input" id="drop-amount-<?= View::text($id) ?>" name="amount" inputmode="decimal" required>
                    <label for="drop-date-<?= View::text($id) ?>">Posting date</label>
                    <?php $openedOn = is_string($register['opened_on'] ?? null) ? $register['opened_on'] : ''; ?>
                    <input class="input" id="drop-date-<?= View::text($id) ?>" name="posting_date" type="date" value="<?= View::text($today) ?>" min="<?= View::text($openedOn) ?>" max="<?= View::text($today) ?>" required>
                    <div class="row gap-2">
                        <button class="btn btn-primary" type="submit">Drop cash</button>
                        <button class="btn" type="button" data-close-modal="drop-<?= View::text($id) ?>">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
</div>

<div id="register-open" class="modal" role="dialog" aria-modal="true" aria-labelledby="register-open-title"<?= $modal === 'register-open' ? '' : ' hidden' ?>>
    <form id="open-form" class="modal-panel card" method="post" action="<?= View::text($openAction) ?>">
        <div class="card-head">
            <?php $closeModal = 'register-open'; require $templateDirectory . '/Shared/Web/modal-x.php'; ?>
            <div class="h3" id="register-open-title">Open register</div>
        </div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <?php foreach (DenominationCount::KEYS as $key): ?>
                <?php $saved = $openCounts[(string) $key] ?? 0; ?>
                <input type="hidden" name="count_<?= View::text((string) $key) ?>" value="<?= View::text($saved) ?>">
            <?php endforeach; ?>
            <label for="cashier">Cashier</label>
            <input class="input" id="cashier" name="cashier" value="<?= View::text($openCashier) ?>" required>
            <label for="opening_float">Opening float</label>
            <input class="input" id="opening_float" name="opening_float" inputmode="decimal" value="<?= View::text($openFloat) ?>" required>
            <p class="muted text-sm">The bills and coins you put in the drawer before the first sale.</p>
            <button class="btn" type="button" data-open-modal="register-count" data-count-form="open-form">Count cash</button>
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit">Open register</button>
                <button class="btn" type="button" data-close-modal="register-open">Cancel</button>
            </div>
        </div>
    </form>
</div>

<div id="register-rename" class="modal" role="dialog" aria-modal="true" aria-labelledby="register-rename-title"<?= $modal === 'register-rename' ? '' : ' hidden' ?>>
    <form class="modal-panel card" method="post" action="<?= View::text($renameAction) ?>">
        <div class="card-head">
            <?php $closeModal = 'register-rename'; require $templateDirectory . '/Shared/Web/modal-x.php'; ?>
            <div class="h3" id="register-rename-title">Rename register</div>
        </div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <label for="register-name">Name</label>
            <input class="input" id="register-name" name="name" value="<?= View::text($renameName) ?>" maxlength="40" required>
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit">Rename register</button>
                <button class="btn" type="button" data-close-modal="register-rename">Cancel</button>
            </div>
        </div>
    </form>
</div>

<div id="register-count" class="modal" role="dialog" aria-modal="true" aria-labelledby="register-count-title" hidden>
    <div class="modal-panel card count-panel">
        <div class="card-head">
            <?php $closeModal = 'register-count'; require $templateDirectory . '/Shared/Web/modal-x.php'; ?>
            <div class="h3" id="register-count-title">Count cash</div>
        </div>
        <div class="card-body stack">
            <div class="count-columns">
                <div class="count-card">
                    <h3>Cash</h3>
                    <div class="count-card-body">
                    <?php foreach ([10000, 5000, 2000, 1000, 500, 100] as $key): ?>
                        <div class="count-row">
                            <label for="count-<?= View::text((string) $key) ?>"><?= View::text($denominations[$key]) ?></label>
                            <input class="input" id="count-<?= View::text((string) $key) ?>" data-count-key="<?= View::text((string) $key) ?>" inputmode="numeric" value="0">
                            <span data-count-line>$0.00</span>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
                <div class="count-card">
                    <h3>Coins</h3>
                    <div class="count-card-body">
                    <?php foreach ([25, 10, 5, 1] as $key): ?>
                        <div class="count-row">
                            <label for="count-<?= View::text((string) $key) ?>"><?= View::text($denominations[$key]) ?></label>
                            <input class="input" id="count-<?= View::text((string) $key) ?>" data-count-key="<?= View::text((string) $key) ?>" inputmode="numeric" value="0">
                            <span data-count-line>$0.00</span>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <p class="count-total">Counted total <strong data-live-total>$0.00</strong></p>
            <div class="row gap-2">
                <button class="btn btn-primary" type="button" data-apply-count>Use this count</button>
                <button class="btn" type="button" data-close-modal="register-count">Cancel</button>
            </div>
        </div>
    </div>
</div>
<div id="register-variance" class="register-variance" hidden>
    <div class="register-variance__backdrop" data-variance-dismiss></div>
    <div class="register-variance__panel modal-panel card" role="dialog" aria-modal="true" aria-labelledby="register-variance-title" tabindex="-1">
        <div class="card-head">
            <button class="modal-x" type="button" data-variance-dismiss aria-label="Close">
                <svg aria-hidden="true"><use href="#i-x"></use></svg>
            </button>
            <div class="h3" id="register-variance-title">Cash does not match</div>
        </div>
        <div class="card-body stack">
            <div class="register-mismatch" role="alert">
                <p class="register-mismatch__title" id="register-variance-lead">Counted cash does not match the drawer.</p>
                <p class="register-mismatch__amount" id="register-variance-combined"></p>
            </div>
            <div class="register-variance__scroll">
                <table class="close-sheet register-variance__table">
                    <colgroup>
                        <col>
                        <col>
                        <col>
                        <col class="diff-col">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col">Tender</th>
                            <th scope="col">Expected</th>
                            <th scope="col">Your count</th>
                            <th class="diff-cell" scope="col">Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row">Cash</th>
                            <td id="register-var-exp-cash"></td>
                            <td id="register-var-ct-cash"></td>
                            <td class="diff-cell" id="register-var-var-cash"></td>
                        </tr>
                        <tr>
                            <th scope="row">Card</th>
                            <td id="register-var-exp-card"></td>
                            <td id="register-var-ct-card"></td>
                            <td class="diff-cell" id="register-var-var-card"></td>
                        </tr>
                        <tr>
                            <th scope="row">Checks</th>
                            <td id="register-var-exp-checks"></td>
                            <td id="register-var-ct-checks"></td>
                            <td class="diff-cell" id="register-var-var-checks"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="muted text-sm">Confirm closes the register and records the cash difference. Cancel returns to the count.</p>
            <div class="row gap-2 register-variance__actions">
                <button class="btn btn-primary" type="button" id="register-variance-confirm">Confirm and close</button>
                <button class="btn" type="button" data-variance-dismiss>Cancel</button>
            </div>
        </div>
    </div>
</div>
<script src="/assets/js/register-count.js"></script>
