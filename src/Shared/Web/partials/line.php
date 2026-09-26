<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $name */
/** @var int $slot */
/** @var list<array<string, mixed>> $accounts */
/** @var bool $subledger */
?>
<fieldset>
    <legend>Line <?= View::text($slot) ?></legend>
    <label for="line_account_<?= View::text($slot) ?>">Account</label>
    <select id="line_account_<?= View::text($slot) ?>" name="line_account_<?= View::text($slot) ?>">
        <option value=""></option>
        <?php foreach ($accounts as $account): ?>
            <option value="<?= View::text($account['id']) ?>"><?= View::text($account['code']) ?> <?= View::text($account['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <label for="line_side_<?= View::text($slot) ?>">Side</label>
    <select id="line_side_<?= View::text($slot) ?>" name="line_side_<?= View::text($slot) ?>">
        <option value="debit">Debit</option>
        <option value="credit">Credit</option>
    </select>
    <label for="line_amount_<?= View::text($slot) ?>">Amount</label>
    <input id="line_amount_<?= View::text($slot) ?>" name="line_amount_<?= View::text($slot) ?>" inputmode="decimal">
    <?php if ($subledger): ?>
        <label for="line_subledger_type_<?= View::text($slot) ?>">Subledger</label>
        <select id="line_subledger_type_<?= View::text($slot) ?>" name="line_subledger_type_<?= View::text($slot) ?>">
            <option value=""></option>
            <option value="vendor">Vendor</option>
            <option value="booth">Booth</option>
        </select>
        <label for="line_subledger_ref_<?= View::text($slot) ?>">Subledger id</label>
        <input id="line_subledger_ref_<?= View::text($slot) ?>" name="line_subledger_ref_<?= View::text($slot) ?>">
    <?php endif; ?>
</fieldset>
