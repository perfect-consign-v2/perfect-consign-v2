<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string|null $error */
/** @var string $selected */
/** @var string $party */
/** @var string $partyLabel */
/** @var list<array{id: string, name: string, email: string, usable: bool, reason: string}> $people */
/** @var list<array{id: string, subject: string, filed_on: string, copies: int}> $letters */

$chosen = strtolower($selected);
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Email</h1>
        <p class="muted">One letter to every <?= View::text($party) ?> with an email, or to the ones you check.</p>
    </div>
</div>

<?php if ($error !== null && $error !== ''): ?>
    <p class="error"><?= View::text($error) ?></p>
<?php endif; ?>

<form method="post" action="/<?= View::text($slug) ?>/letters" class="card">
    <div class="card-body stack">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <div class="field">
            <label for="subject">Subject</label>
            <input class="input" id="subject" name="subject" maxlength="150" required value="">
        </div>
        <div class="field">
            <label for="message">Message</label>
            <textarea class="textarea" id="message" name="message" maxlength="4000" rows="8" required></textarea>
        </div>
        <div class="field">
            <label class="row gap-2" for="everyone">
                <input id="everyone" name="everyone" type="checkbox" value="1">
                Everyone with an email
            </label>
            <p class="muted text-sm">That sends to every active <?= View::text($party) ?> who has an address and whose sale mail has not bounced. The checks below are ignored.</p>
        </div>
        <fieldset class="field">
            <legend><?= View::text($partyLabel) ?></legend>
            <?php if ($people === []): ?>
                <p class="muted">No active <?= View::text($party) ?>s yet.</p>
            <?php else: ?>
                <ul class="stack">
                    <?php foreach ($people as $person): ?>
                        <li>
                            <label class="row gap-2" for="vendor-<?= View::text($person['id']) ?>">
                                <input id="vendor-<?= View::text($person['id']) ?>" name="vendor_id[]" type="checkbox" value="<?= View::text($person['id']) ?>"<?= !$person['usable'] ? ' disabled' : '' ?><?= $person['usable'] && $chosen === $person['id'] ? ' checked' : '' ?>>
                                <span><?= View::text($person['name']) ?></span>
                                <?php if (!$person['usable']): ?>
                                    <span class="muted"><?= View::text($person['reason']) ?></span>
                                <?php else: ?>
                                    <span class="muted"><?= View::text($person['email']) ?></span>
                                <?php endif; ?>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </fieldset>
    </div>
    <div class="card-foot">
        <button class="btn btn-primary" type="submit">Send letter</button>
    </div>
</form>

<?php if ($letters !== []): ?>
    <div class="card mt-6">
        <div class="card-head"><div class="h3">Filed letters</div></div>
        <div class="card-body">
            <ul class="stack">
                <?php foreach ($letters as $letter): ?>
                    <li>
                        <a class="strong" href="/<?= View::text($slug) ?>/letters/<?= View::text($letter['id']) ?>"><?= View::text($letter['subject']) ?></a>
                        <span class="muted"><?= View::date($letter['filed_on']) ?> · <?= View::text((string) $letter['copies']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>
