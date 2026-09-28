<?php

declare(strict_types=1);

use PerfectConsign\Feature\Register\DenominationCount;
use PerfectConsign\Shared\Http\View;

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
$pageError = is_string($error ?? null) ? $error : '';
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
                <a class="btn" href="/<?= View::text($slug) ?>/registers/<?= View::text($id) ?>/shifts">Shifts</a>
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
                <div class="card-head"><div class="h3" id="delete-title-<?= View::text($id) ?>">Delete <?= View::text($name) ?></div></div>
                <div class="card-body stack">
                    <?php if ($modal === 'delete-' . $id && $pageError !== ''): ?>
                        <div class="alert alert-danger" role="alert">
                            <p class="error"><?= View::text($pageError) ?></p>
                        </div>
                    <?php endif; ?>
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

    <?php if ($open): ?>
        <div id="close-<?= View::text($id) ?>" class="modal" role="dialog" aria-modal="true" aria-labelledby="close-title-<?= View::text($id) ?>"<?= $modal === 'close-' . $id ? '' : ' hidden' ?>>
            <form id="close-form-<?= View::text($id) ?>" class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/registers/<?= View::text($id) ?>/close">
                <div class="card-head"><div class="h3" id="close-title-<?= View::text($id) ?>">Close <?= View::text($name) ?></div></div>
                <div class="card-body stack">
                    <?php if ($modal === 'close-' . $id && $pageError !== ''): ?>
                        <div class="alert alert-danger" role="alert">
                            <p class="error"><?= View::text($pageError) ?></p>
                        </div>
                    <?php endif; ?>
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <?php foreach (DenominationCount::KEYS as $key): ?>
                        <?php $saved = $modal === 'close-' . $id ? ($draftCounts[(string) $key] ?? 0) : 0; ?>
                        <input type="hidden" name="count_<?= View::text((string) $key) ?>" value="<?= View::text($saved) ?>">
                    <?php endforeach; ?>
                    <p>Expected drawer <strong><?= View::text(is_string($register['expected_cash'] ?? null) ? $register['expected_cash'] : '') ?></strong></p>
                    <label for="counted-cash-<?= View::text($id) ?>">Counted cash</label>
                    <input class="input" id="counted-cash-<?= View::text($id) ?>" name="counted_cash" inputmode="decimal" value="<?= $modal === 'close-' . $id ? View::text($draftCash) : '' ?>" required>
                    <button class="btn" type="button" data-open-modal="register-count" data-count-form="close-form-<?= View::text($id) ?>">Count bills and coins</button>
                    <?php foreach ($labels as $role => $label): ?>
                        <?php if ($role === 'cash') { continue; } ?>
                        <?php $net = is_int($tenders[$role] ?? null) ? $tenders[$role] : (is_string($tenders[$role] ?? null) ? (int) $tenders[$role] : 0); ?>
                        <p><?= View::text($label) ?> posted <?= View::text(DenominationCount::dollars($net)) ?></p>
                    <?php endforeach; ?>
                    <label for="counted-card-<?= View::text($id) ?>">Counted card</label>
                    <input class="input" id="counted-card-<?= View::text($id) ?>" name="counted_card" inputmode="decimal" value="<?= $modal === 'close-' . $id ? View::text($draftCard) : '0.00' ?>">
                    <label for="counted-checks-<?= View::text($id) ?>">Counted checks</label>
                    <input class="input" id="counted-checks-<?= View::text($id) ?>" name="counted_checks" inputmode="decimal" value="<?= $modal === 'close-' . $id ? View::text($draftChecks) : '0.00' ?>">
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
                        <p>Post the cash difference to over/short. Card and check differences stay on the tape.</p>
                    <?php endif; ?>
                    <div class="row gap-2">
                        <button class="btn btn-primary" type="submit"><?= $confirmVariance && $modal === 'close-' . $id ? 'Confirm and close' : 'Close register' ?></button>
                        <button class="btn" type="button" data-close-modal="close-<?= View::text($id) ?>">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
        <div id="drop-<?= View::text($id) ?>" class="modal" role="dialog" aria-modal="true" aria-labelledby="drop-title-<?= View::text($id) ?>"<?= $modal === 'drop-' . $id ? '' : ' hidden' ?>>
            <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/registers/<?= View::text($id) ?>/drop">
                <div class="card-head"><div class="h3" id="drop-title-<?= View::text($id) ?>">Drop cash</div></div>
                <div class="card-body stack">
                    <?php if ($modal === 'drop-' . $id && $pageError !== ''): ?>
                        <div class="alert alert-danger" role="alert">
                            <p class="error"><?= View::text($pageError) ?></p>
                        </div>
                    <?php endif; ?>
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
        <div class="card-head"><div class="h3" id="register-open-title">Open register</div></div>
        <div class="card-body stack">
            <?php if ($modal === 'register-open' && $pageError !== ''): ?>
                <div class="alert alert-danger" role="alert">
                    <p class="error"><?= View::text($pageError) ?></p>
                </div>
            <?php endif; ?>
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <?php foreach (DenominationCount::KEYS as $key): ?>
                <?php $saved = $openCounts[(string) $key] ?? 0; ?>
                <input type="hidden" name="count_<?= View::text((string) $key) ?>" value="<?= View::text($saved) ?>">
            <?php endforeach; ?>
            <label for="cashier">Cashier</label>
            <input class="input" id="cashier" name="cashier" value="<?= View::text($openCashier) ?>" required>
            <label for="opening_float">Opening float</label>
            <input class="input" id="opening_float" name="opening_float" inputmode="decimal" value="<?= View::text($openFloat) ?>" required>
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
        <div class="card-head"><div class="h3" id="register-rename-title">Rename register</div></div>
        <div class="card-body stack">
            <?php if ($modal === 'register-rename' && $pageError !== ''): ?>
                <div class="alert alert-danger" role="alert">
                    <p class="error"><?= View::text($pageError) ?></p>
                </div>
            <?php endif; ?>
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
    <div class="modal-panel card">
        <div class="card-head"><div class="h3" id="register-count-title">Count cash</div></div>
        <div class="card-body stack">
            <div class="count-grid">
                <?php foreach ($denominations as $key => $label): ?>
                    <label for="count-<?= View::text((string) $key) ?>"><?= View::text($label) ?></label>
                    <input class="input" id="count-<?= View::text((string) $key) ?>" data-count-key="<?= View::text((string) $key) ?>" inputmode="numeric" value="0">
                <?php endforeach; ?>
            </div>
            <p>Total <strong data-live-total>0.00</strong></p>
            <div class="row gap-2">
                <button class="btn btn-primary" type="button" data-apply-count>Use this count</button>
                <button class="btn" type="button" data-close-modal="register-count">Cancel</button>
            </div>
        </div>
    </div>
</div>
<script src="/assets/js/register-count.js"></script>
