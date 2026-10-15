<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array{name: string, street: string, street2: string, city: string, state: string, postal: string, phone: string, email: string} $company */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Company</h1>
        <p class="muted">The store name and address printed at the top of receipts.</p>
    </div>
</div>

<form method="post" action="/<?= View::text($slug) ?>/company" class="card mt-6">
    <div class="card-body stack">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <div class="field">
            <label for="store_name">Store name</label>
            <input class="input" id="store_name" name="store_name" required maxlength="120" value="<?= View::text($company['name']) ?>">
        </div>
        <div class="field">
            <label for="street">Street</label>
            <input class="input" id="street" name="street" maxlength="200" value="<?= View::text($company['street']) ?>">
        </div>
        <div class="field">
            <label for="street2">Street line 2</label>
            <input class="input" id="street2" name="street2" maxlength="200" value="<?= View::text($company['street2']) ?>">
        </div>
        <div class="grid cols-2">
            <div class="field">
                <label for="city">City</label>
                <input class="input" id="city" name="city" maxlength="80" value="<?= View::text($company['city']) ?>">
            </div>
            <div class="field">
                <label for="state">State</label>
                <input class="input" id="state" name="state" maxlength="40" value="<?= View::text($company['state']) ?>">
            </div>
            <div class="field">
                <label for="postal">ZIP</label>
                <input class="input" id="postal" name="postal" maxlength="20" value="<?= View::text($company['postal']) ?>">
            </div>
            <div class="field">
                <label for="phone">Phone</label>
                <input class="input" id="phone" name="phone" maxlength="40" value="<?= View::text($company['phone']) ?>">
            </div>
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input class="input" id="email" name="email" maxlength="200" value="<?= View::text($company['email']) ?>">
        </div>
        <p><button class="btn btn-primary" type="submit">Save</button></p>
    </div>
</form>
