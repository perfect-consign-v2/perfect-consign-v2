<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array{id: string, label: string, kind: string, who: string, active: bool, used: bool}> $reasons */
/** @var string $waitDays */
/** @var string|null $error */

$kinds = ['leaving' => 'It left the store', 'correction' => 'A correction'];
$whoChoices = ['both' => 'Vendors and staff', 'staff' => 'Staff only'];
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Settings</h1>
        <p class="muted">Why an item count goes down, and what happens to pieces left behind when a booth ends.</p>
    </div>
</div>
<?php $settingsPart = 'counts'; require dirname(__DIR__) . '/Mall/settings-tabs.php'; ?>

<?php if ($error !== null): ?>
    <div class="alert alert-danger mt-4" role="alert"><?= View::text($error) ?></div>
<?php endif; ?>

<section class="card mt-6" aria-labelledby="count-reasons">
    <div class="card-head"><div class="h3" id="count-reasons">Why are there fewer?</div></div>
    <div class="card-body stack">
        <p class="muted text-sm">When someone lowers a count, they choose one of these. Keep at least one switched on. A reason already in an item's history can be switched off but not deleted.</p>
        <table class="table">
            <thead><tr><th>Reason</th><th>What it means</th><th>Who may use it</th><th>On</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($reasons as $reason): ?>
                <?php $form = 'reason-' . $reason['id']; ?>
                <tr>
                    <td>
                        <label class="sr-only" for="<?= View::text($form) ?>-label">Reason</label>
                        <input class="input" id="<?= View::text($form) ?>-label" name="label" form="<?= View::text($form) ?>" value="<?= View::text($reason['label']) ?>" maxlength="60" required>
                    </td>
                    <td>
                        <label class="sr-only" for="<?= View::text($form) ?>-kind">What it means</label>
                        <select class="input" id="<?= View::text($form) ?>-kind" name="kind" form="<?= View::text($form) ?>">
                            <?php foreach ($kinds as $value => $label): ?>
                                <option value="<?= View::text($value) ?>"<?= $reason['kind'] === $value ? ' selected' : '' ?>><?= View::text($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <label class="sr-only" for="<?= View::text($form) ?>-who">Who may use it</label>
                        <select class="input" id="<?= View::text($form) ?>-who" name="who" form="<?= View::text($form) ?>">
                            <?php foreach ($whoChoices as $value => $label): ?>
                                <option value="<?= View::text($value) ?>"<?= $reason['who'] === $value ? ' selected' : '' ?>><?= View::text($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><?= $reason['active'] ? 'On' : 'Off' ?></td>
                    <td class="row gap-2">
                        <form id="<?= View::text($form) ?>" method="post" action="/<?= View::text($slug) ?>/settings/counts/reasons/<?= View::text($reason['id']) ?>">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <button class="btn btn-sm" type="submit">Save</button>
                        </form>
                        <form method="post" action="/<?= View::text($slug) ?>/settings/counts/reasons/<?= View::text($reason['id']) ?>/active">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <input type="hidden" name="active" value="<?= $reason['active'] ? '0' : '1' ?>">
                            <button class="btn btn-sm" type="submit"><?= $reason['active'] ? 'Switch off' : 'Switch on' ?></button>
                        </form>
                        <?php if (!$reason['used']): ?>
                            <form method="post" action="/<?= View::text($slug) ?>/settings/counts/reasons/<?= View::text($reason['id']) ?>/delete">
                                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                <button class="btn btn-sm" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <form class="card-foot stack" method="post" action="/<?= View::text($slug) ?>/settings/counts/reasons">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <div class="h4">Add a reason</div>
        <div class="field">
            <label for="new-reason-label">Reason</label>
            <input class="input" id="new-reason-label" name="label" maxlength="60" required>
        </div>
        <div class="field">
            <label for="new-reason-kind">What it means</label>
            <select class="input" id="new-reason-kind" name="kind">
                <?php foreach ($kinds as $value => $label): ?>
                    <option value="<?= View::text($value) ?>"><?= View::text($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="new-reason-who">Who may use it</label>
            <select class="input" id="new-reason-who" name="who">
                <?php foreach ($whoChoices as $value => $label): ?>
                    <option value="<?= View::text($value) ?>"><?= View::text($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <p><button class="btn btn-primary" type="submit">Add reason</button></p>
    </form>
</section>

<form class="card mt-6" method="post" action="/<?= View::text($slug) ?>/settings/counts/wait">
    <div class="card-head"><div class="h3">Items left behind</div></div>
    <div class="card-body stack">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <p class="muted text-sm">Only if your vendor agreement says so: after a booth ends, pieces still in the store can become store stock once this many days have passed. Leave it empty and the store never keeps them.</p>
        <div class="field">
            <label for="abandon_wait_days">Days to wait</label>
            <input class="input" id="abandon_wait_days" name="abandon_wait_days" inputmode="numeric" value="<?= View::text($waitDays) ?>" maxlength="3">
        </div>
    </div>
    <div class="card-foot"><button class="btn btn-primary" type="submit">Save</button></div>
</form>
