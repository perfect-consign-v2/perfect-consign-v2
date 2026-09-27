<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array<string, mixed>|null $vendor */

$id = is_array($vendor) ? ($vendor['id'] ?? null) : null;
$code = is_array($vendor) ? ($vendor['code'] ?? '') : '';
$firstName = is_array($vendor) ? ($vendor['first_name'] ?? '') : '';
$lastName = is_array($vendor) ? ($vendor['last_name'] ?? '') : '';
$phone = is_array($vendor) ? ($vendor['phone'] ?? '') : '';
$email = is_array($vendor) ? ($vendor['email'] ?? '') : '';
$address = is_array($vendor) ? ($vendor['address'] ?? '') : '';
$editing = is_string($id) && $id !== '';
$action = $editing ? '/' . $slug . '/vendors/' . $id : '/' . $slug . '/vendors';
$heading = $editing ? 'Edit vendor' : 'New vendor';
$crumb = $editing && is_string($code) && $code !== '' ? $code : 'New';
$codeText = is_string($code) ? $code : '';
$firstText = is_string($firstName) ? $firstName : '';
$lastText = is_string($lastName) ? $lastName : '';
$phoneText = is_string($phone) ? $phone : '';
$emailText = is_string($email) ? $email : '';
$addressText = is_string($address) ? $address : '';
$active = !is_array($vendor) || ($vendor['active'] ?? true) === true || ($vendor['active'] ?? null) === 't' || ($vendor['active'] ?? null) === '1' || ($vendor['active'] ?? null) === 'true';
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb">
            <a href="/<?= View::text($slug) ?>/parties">Vendors</a><span class="sep">/</span>
            <span><?= View::text($crumb) ?></span>
        </nav>
        <h1 class="h1"><?= View::text($heading) ?></h1>
    </div>
</div>

<form method="post" action="<?= View::text($action) ?>" class="card form-sheet">
    <div class="card-body stack">
        <div class="field">
            <label for="code">Code</label>
            <input class="input" id="code" name="code" value="<?= View::text($codeText) ?>" required>
            <p class="muted text-sm">The code is how this mall finds the vendor.</p>
        </div>
        <div class="field">
            <label for="first_name">First name</label>
            <input class="input" id="first_name" name="first_name" value="<?= View::text($firstText) ?>" required>
            <p class="muted text-sm">The first name is who gets paid.</p>
        </div>
        <div class="field">
            <label for="last_name">Last name</label>
            <input class="input" id="last_name" name="last_name" value="<?= View::text($lastText) ?>" required>
        </div>
        <div class="field">
            <label for="phone">Phone</label>
            <input class="input" id="phone" name="phone" value="<?= View::text($phoneText) ?>">
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input class="input" id="email" name="email" type="email" value="<?= View::text($emailText) ?>">
        </div>
        <div class="field">
            <label for="address">Address</label>
            <textarea class="textarea" id="address" name="address"><?= View::text($addressText) ?></textarea>
        </div>
        <div>
            <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> <?= $editing ? 'Save changes' : 'Create vendor' ?></button>
        </div>
        <?php if (!$editing): ?>
            <p class="muted text-sm">After you create them, assign a booth.</p>
        <?php endif; ?>
    </div>
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
</form>

<?php if ($editing): ?>
    <div class="card form-sheet mt-6">
        <div class="card-body stack">
            <p class="muted text-sm">A vendor who owes the store cannot be made inactive. Delete is refused once this vendor has a booth assignment or a posted line.</p>
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
<?php endif; ?>
