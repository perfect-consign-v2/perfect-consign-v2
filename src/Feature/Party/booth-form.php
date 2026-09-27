<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array<string, mixed>|null $booth */
/** @var int $defaultWidth */
/** @var int $defaultDepth */
/** @var list<array<string, mixed>> $rates */

$id = is_array($booth) ? ($booth['id'] ?? null) : null;
$code = is_array($booth) ? ($booth['code'] ?? null) : null;
$editing = is_string($id) && $id !== '';
$action = $editing ? '/' . $slug . '/booths/' . $id : '/' . $slug . '/booths';
$heading = $editing ? 'Edit booth' : 'New booth';
$crumb = $editing && is_string($code) && $code !== '' ? $code : 'New';
$width = is_array($booth) ? ($booth['width_ft'] ?? '') : '';
$depth = is_array($booth) ? ($booth['depth_ft'] ?? '') : '';
$x = is_array($booth) ? ($booth['x_ft'] ?? '') : '';
$z = is_array($booth) ? ($booth['z_ft'] ?? '') : '';
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb">
            <a href="/<?= View::text($slug) ?>/booths">Booths</a><span class="sep">/</span>
            <span><?= View::text($crumb) ?></span>
        </nav>
        <h1 class="h1"><?= View::text($heading) ?></h1>
    </div>
    <a class="btn" href="/<?= View::text($slug) ?>/booths"><svg aria-hidden="true"><use href="#i-arrow-left"></use></svg> Back</a>
</div>

<form method="post" action="<?= View::text($action) ?>" class="grid form-split" style="grid-template-columns: 2fr 1fr">
    <div class="card">
        <div class="card-head"><div class="h3">Booth details</div></div>
        <div class="card-body grid cols-2">
            <div class="field">
                <label for="code">Code</label>
                <input class="input" id="code" name="code" value="<?= View::text(is_array($booth) ? ($booth['code'] ?? '') : '') ?>" required>
            </div>
            <div class="field">
                <label for="name">Name</label>
                <input class="input" id="name" name="name" value="<?= View::text(is_array($booth) ? ($booth['name'] ?? '') : '') ?>" required>
            </div>
            <div class="field">
                <label for="width_ft">Width (ft)</label>
                <input class="input" id="width_ft" name="width_ft" inputmode="numeric" value="<?= View::text($width) ?>" placeholder="<?= View::text($defaultWidth) ?>">
            </div>
            <div class="field">
                <label for="depth_ft">Depth (ft)</label>
                <input class="input" id="depth_ft" name="depth_ft" inputmode="numeric" value="<?= View::text($depth) ?>" placeholder="<?= View::text($defaultDepth) ?>">
            </div>
            <div class="field">
                <label for="x_ft">X (ft)</label>
                <input class="input" id="x_ft" name="x_ft" inputmode="numeric" value="<?= View::text($x) ?>">
            </div>
            <div class="field">
                <label for="z_ft">Z (ft)</label>
                <input class="input" id="z_ft" name="z_ft" inputmode="numeric" value="<?= View::text($z) ?>">
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><div class="h3">Notes</div></div>
        <div class="card-body">
            <p class="muted text-sm">Leave the size empty to use the mall default, <?= View::text($defaultWidth) ?> by <?= View::text($defaultDepth) ?> feet. Leave X and Z empty to keep the booth off the floor, or set both to place it. Delete a booth only while no assignment, rate, or posted rent uses it.</p>
        </div>
        <div class="card-foot row between">
            <a class="btn btn-ghost" href="/<?= View::text($slug) ?>/booths">Cancel</a>
            <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> <?= $editing ? 'Save changes' : 'Create booth' ?></button>
        </div>
    </div>
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
</form>

<?php if ($editing): ?>
    <div class="card mt-6">
        <div class="card-head"><div class="h3">Monthly rent</div></div>
        <div class="card-body">
            <?php if ($rates === []): ?>
                <p class="muted">No rate yet.</p>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Month</th><th>Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($rates as $rate): ?>
                        <tr>
                            <td><?= View::text($rate['month_start']) ?></td>
                            <td class="num"><?= View::text(View::money(View::minor($rate['amount_minor']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <form method="post" action="/<?= View::text($slug) ?>/rates">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <input type="hidden" name="booth_id" value="<?= View::text($id) ?>">
            <div class="card-body grid cols-2">
                <div class="field">
                    <label for="month_start">Month</label>
                    <input class="input" id="month_start" name="month_start" type="date" required>
                </div>
                <div class="field">
                    <label for="amount">Amount</label>
                    <input class="input" id="amount" name="amount" inputmode="decimal" required>
                </div>
            </div>
            <div class="card-foot">
                <button class="btn btn-primary" type="submit">Add rate</button>
            </div>
        </form>
    </div>
<?php endif; ?>
