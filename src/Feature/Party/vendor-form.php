<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array<string, mixed>|null $vendor */
/** @var string $storeTake */
/** @var string $defaultStoreTake */
/** @var bool $overridden */

$id = is_array($vendor) ? ($vendor['id'] ?? null) : null;
$code = is_array($vendor) ? ($vendor['code'] ?? '') : '';
$firstName = is_array($vendor) ? ($vendor['first_name'] ?? '') : '';
$lastName = is_array($vendor) ? ($vendor['last_name'] ?? '') : '';
$phone = is_array($vendor) ? ($vendor['phone'] ?? '') : '';
$email = is_array($vendor) ? ($vendor['email'] ?? '') : '';
$address = is_array($vendor) ? ($vendor['address'] ?? '') : '';
$editing = is_string($id) && $id !== '';
$action = $editing ? '/' . $slug . '/vendors/' . $id : '/' . $slug . '/vendors';
$crumb = $editing && is_string($code) && $code !== '' ? $code : 'New';
$codeText = is_string($code) ? $code : '';
$firstText = is_string($firstName) ? $firstName : '';
$lastText = is_string($lastName) ? $lastName : '';
$phoneText = is_string($phone) ? $phone : '';
$emailText = is_string($email) ? $email : '';
$addressText = is_string($address) ? $address : '';
$display = $firstText !== '' && $firstText === $lastText ? $firstText : trim($firstText . ' ' . $lastText);
$consignmentOnly = ($consignmentOnly ?? false) === true;
$partyOne = $consignmentOnly ? 'consignor' : 'vendor';
$partyLabel = $consignmentOnly ? 'Consignors' : 'Vendors';
$heading = $editing && $display !== '' ? $display : ($editing ? 'Edit ' . $partyOne : 'New ' . $partyOne);
$agreementStart = is_array($vendor) && is_string($vendor['agreement_starts_on'] ?? null) ? $vendor['agreement_starts_on'] : '';
$agreementEnd = is_array($vendor) && is_string($vendor['agreement_ends_on'] ?? null) ? $vendor['agreement_ends_on'] : '';
$taxId = is_array($vendor) && is_string($vendor['tax_id'] ?? null) ? $vendor['tax_id'] : '';
$discountBy = is_array($vendor) && is_string($vendor['discount_by'] ?? null) ? $vendor['discount_by'] : 'staff';
$keepDays = is_array($vendor) && (is_string($vendor['keep_days'] ?? null) || is_int($vendor['keep_days'] ?? null)) ? (string) $vendor['keep_days'] : '';
$expiryAction = is_array($vendor) && is_string($vendor['expiry_action'] ?? null) ? $vendor['expiry_action'] : 'return';
$notes = is_array($vendor) && is_string($vendor['notes'] ?? null) ? $vendor['notes'] : '';
$taxExemptNumber = is_array($vendor) && is_string($vendor['tax_exempt_number'] ?? null) ? $vendor['tax_exempt_number'] : '';
$taxFlag = is_array($vendor) ? ($vendor['tax_exempt'] ?? false) : false;
$taxExempt = $taxFlag === true || $taxFlag === 1 || $taxFlag === '1' || $taxFlag === 't' || $taxFlag === 'true';
$active = !is_array($vendor) || ($vendor['active'] ?? true) === true || ($vendor['active'] ?? null) === 't' || ($vendor['active'] ?? null) === '1' || ($vendor['active'] ?? null) === 'true';
$payoutMode = is_array($vendor) && is_string($vendor['payout_mode'] ?? null) ? $vendor['payout_mode'] : 'pay';
$standing = $active ? 'Active' : 'Inactive';
$standingBadge = $active ? 'badge-success' : 'badge-warning';
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb">
            <a href="/<?= View::text($slug) ?>/parties"><?= View::text($partyLabel) ?></a><span class="sep">/</span>
            <?php if ($editing): ?>
                <a href="/<?= View::text($slug) ?>/vendors/<?= View::text($id) ?>"><?= View::text($crumb) ?></a><span class="sep">/</span>
            <?php endif; ?>
            <span><?= $editing ? 'Edit' : 'New' ?></span>
        </nav>
        <h1 class="h1"><?= View::text($heading) ?></h1>
        <?php if ($editing): ?>
            <ul class="vendor-pills">
                <li><span class="badge badge-accent"><?= View::text($codeText) ?></span></li>
                <li><span class="badge <?= View::text($standingBadge) ?>"><?= View::text($standing) ?></span></li>
                <?php if ($overridden): ?>
                    <li><span class="badge badge-warning">Overridden</span></li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<form method="post" action="<?= View::text($action) ?>" class="vendor-edit">
    <div class="record-cards">
        <section class="card" aria-labelledby="vendor-account">
            <div class="card-head"><div class="h3" id="vendor-account">Account</div></div>
            <div class="card-body stack">
                <div class="field">
                    <label for="code">Code</label>
                    <input class="input" id="code" name="code" value="<?= View::text($codeText) ?>" required>
                    <p class="muted text-sm"><?= $consignmentOnly ? 'The code is how this store finds the consignor.' : 'The code is how this mall finds the vendor.' ?></p>
                </div>
                <div class="field">
                    <label for="business_name">Business name</label>
                    <input class="input" id="business_name" name="business_name" value="<?= View::text(is_string($vendor['business_name'] ?? null) ? $vendor['business_name'] : '') ?>">
                    <p class="muted text-sm"><?= $consignmentOnly ? 'Shown on the list and the receipt when this consignor is a shop or a couple.' : 'Shown on the list and the receipt when this vendor is a shop or a couple.' ?></p>
                </div>
                <div class="vendor-pair">
                    <div class="field">
                        <label for="first_name">First name</label>
                        <input class="input" id="first_name" name="first_name" value="<?= View::text($firstText) ?>" required>
                    </div>
                    <div class="field">
                        <label for="last_name">Last name</label>
                        <input class="input" id="last_name" name="last_name" value="<?= View::text($lastText) ?>" required>
                    </div>
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="vendor-contact">
            <div class="card-head"><div class="h3" id="vendor-contact">Contact</div></div>
            <div class="card-body stack">
                <div class="vendor-pair">
                    <div class="field">
                        <label for="phone">Phone</label>
                        <input class="input" id="phone" name="phone" value="<?= View::text($phoneText) ?>">
                    </div>
                    <div class="field">
                        <label for="email">Email</label>
                        <input class="input" id="email" name="email" type="email" value="<?= View::text($emailText) ?>">
                    </div>
                </div>
                <div class="field">
                    <label for="address">Address</label>
                    <textarea class="textarea" id="address" name="address"><?= View::text($addressText) ?></textarea>
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="vendor-split">
            <div class="card-head"><div class="h3" id="vendor-split">Store take</div></div>
            <div class="card-body">
                <div class="field">
                    <label for="store_take">Store take<?php if ($overridden): ?> <span class="badge badge-warning">Overridden</span><?php endif; ?></label>
                    <input class="input" id="store_take" name="store_take" inputmode="decimal" value="<?= View::text($storeTake) ?>">
                    <p class="muted text-sm"><?= $consignmentOnly
                        ? 'Store default is ' . View::text($defaultStoreTake) . '%. Saving a different amount marks this consignor overridden.'
                        : 'Mall default is ' . View::text($defaultStoreTake) . '%. Saving a different amount marks this vendor overridden.' ?></p>
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="vendor-agreement">
            <div class="card-head"><div class="h3" id="vendor-agreement">Agreement and tax</div></div>
            <div class="card-body stack">
                <div class="vendor-pair">
                    <div class="field">
                        <label for="agreement_starts_on">Agreement start</label>
                        <input class="input" id="agreement_starts_on" name="agreement_starts_on" type="date" value="<?= View::text($agreementStart) ?>">
                    </div>
                    <div class="field">
                        <label for="agreement_ends_on">Agreement end</label>
                        <input class="input" id="agreement_ends_on" name="agreement_ends_on" type="date" value="<?= View::text($agreementEnd) ?>"<?= $agreementStart !== '' ? ' min="' . View::text($agreementStart) . '"' : '' ?>>
                    </div>
                </div>
                <div class="field">
                    <label for="tax_id">Tax id</label>
                    <input class="input" id="tax_id" name="tax_id" maxlength="40" value="<?= View::text($taxId) ?>">
                </div>
                <div class="field">
                    <label for="discount_by">Who may discount</label>
                    <select class="select" id="discount_by" name="discount_by">
                        <option value="staff"<?= $discountBy === 'staff' ? ' selected' : '' ?>>Staff</option>
                        <option value="owner"<?= $discountBy === 'owner' ? ' selected' : '' ?>>Owner</option>
                    </select>
                </div>
                <div class="field">
                    <label for="keep_days">How long goods stay (days)</label>
                    <input class="input" id="keep_days" name="keep_days" inputmode="numeric" value="<?= View::text($keepDays) ?>">
                </div>
                <div class="field">
                    <label for="expiry_action">At expiry</label>
                    <select class="select" id="expiry_action" name="expiry_action">
                        <option value="return"<?= $expiryAction === 'return' ? ' selected' : '' ?>>Return</option>
                        <option value="donate"<?= $expiryAction === 'donate' ? ' selected' : '' ?>>Donate</option>
                        <option value="buy"<?= $expiryAction === 'buy' ? ' selected' : '' ?>>Buy</option>
                    </select>
                </div>
                <div class="field">
                    <label class="row gap-2" for="tax_exempt">
                        <input id="tax_exempt" name="tax_exempt" type="checkbox" value="1"<?= $taxExempt ? ' checked' : '' ?>>
                        Tax exempt
                    </label>
                    <p class="muted text-sm">When checked, a certificate or resale ID is required.</p>
                </div>
                <div class="field">
                    <label for="tax_exempt_number">Tax exempt / resale number</label>
                    <input class="input" id="tax_exempt_number" name="tax_exempt_number" maxlength="100" value="<?= View::text($taxExemptNumber) ?>">
                </div>
            </div>
        </section>

        <section class="card record-span" aria-labelledby="vendor-notes">
            <div class="card-head"><div class="h3" id="vendor-notes">Notes</div></div>
            <div class="card-body">
                <div class="field">
                    <label class="sr-only" for="notes">Notes</label>
                    <textarea class="textarea" id="notes" name="notes" maxlength="2000"><?= View::text($notes) ?></textarea>
                </div>
            </div>
        </section>

        <section class="card record-span" aria-labelledby="vendor-payout">
            <div class="card-head"><div class="h3" id="vendor-payout">How they get paid</div></div>
            <div class="card-body">
                <div class="field">
                    <label class="sr-only" for="payout_mode">How they get paid</label>
                    <select class="select" id="payout_mode" name="payout_mode">
                        <option value="pay"<?= $payoutMode === 'pay' ? ' selected' : '' ?>>Check</option>
                        <option value="credit_only"<?= $payoutMode === 'credit_only' ? ' selected' : '' ?>>Store credit only</option>
                        <option value="hold"<?= $payoutMode === 'hold' ? ' selected' : '' ?>>Hold</option>
                    </select>
                    <p class="muted text-sm">Check pays them on the payout run. Store credit only and Hold stay off that run.</p>
                </div>
            </div>
        </section>
    </div>

    <div class="vendor-save">
        <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> <?= $editing ? 'Save changes' : 'Create ' . View::text($partyOne) ?></button>
        <?php if (!$editing && !$consignmentOnly): ?>
            <p class="muted text-sm">After you create them, assign a booth.</p>
        <?php endif; ?>
    </div>
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
</form>

<?php if ($editing): ?>
    <div class="card vendor-danger">
        <div class="card-head"><div class="h3">Standing</div></div>
        <div class="card-body stack">
            <p class="muted text-sm"><?= $consignmentOnly
                ? 'A consignor who is still owed cannot be made inactive. Delete is refused once this consignor has a posted line.'
                : 'A vendor who is still owed, or who still owes rent, cannot be made inactive. Delete is refused once this vendor has a booth assignment or a posted line.' ?></p>
            <div class="row gap-3 wrap">
                <form method="post" action="/<?= View::text($slug) ?>/vendors/<?= View::text($id) ?>/standing">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <input type="hidden" name="standing" value="<?= $active ? 'inactive' : 'active' ?>">
                    <button class="btn btn-sm" type="submit"><?= $active ? 'Make inactive' : 'Make active' ?></button>
                </form>
                <form method="post" action="/<?= View::text($slug) ?>/vendors/<?= View::text($id) ?>/delete">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <button class="btn btn-danger btn-sm" type="submit"><svg aria-hidden="true"><use href="#i-trash"></use></svg> Delete</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>
