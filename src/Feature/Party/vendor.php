<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $today */
/** @var array{id: string, code: string, name: string, phone: string, email: string, address: string, status_label: string, status_badge: string, current: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>, upcoming: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>, past: list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string}>} $vendor */
/** @var list<array<string, mixed>> $booths */

$sections = [
    'Current' => $vendor['current'],
    'Upcoming' => $vendor['upcoming'],
    'Past' => $vendor['past'],
];
$placed = $vendor['current'] !== [] || $vendor['upcoming'] !== [] || $vendor['past'] !== [];
?>
<article id="vendor-<?= View::text($vendor['id']) ?>">
    <div class="page-head">
        <div class="titles">
            <nav class="breadcrumb">
                <a href="/<?= View::text($slug) ?>/parties">Vendors</a><span class="sep">/</span>
                <span><?= View::text($vendor['code']) ?></span>
            </nav>
            <h1 class="h1"><?= View::text($vendor['name']) ?></h1>
        </div>
        <a class="btn" href="/<?= View::text($slug) ?>/vendors/<?= View::text($vendor['id']) ?>/edit"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Edit</a>
    </div>
    <p class="row gap-2">
        <span class="badge badge-accent"><?= View::text($vendor['code']) ?></span>
        <span class="badge <?= View::text($vendor['status_badge']) ?>"><?= View::text($vendor['status_label']) ?></span>
    </p>
    <?php if ($vendor['phone'] !== '' || $vendor['email'] !== '' || $vendor['address'] !== ''): ?>
        <p class="muted mt-4">
            <?php if ($vendor['phone'] !== ''): ?><?= View::text($vendor['phone']) ?><br><?php endif; ?>
            <?php if ($vendor['email'] !== ''): ?><a class="mailto" href="mailto:<?= View::text($vendor['email']) ?>"><?= View::text($vendor['email']) ?></a><br><?php endif; ?>
            <?php if ($vendor['address'] !== ''): ?><?= View::text($vendor['address']) ?><?php endif; ?>
        </p>
    <?php endif; ?>
</article>

<?php if (!$placed): ?>
    <p class="muted mt-6">No booth yet.</p>
<?php endif; ?>

<?php foreach ($sections as $heading => $assignments): ?>
    <?php if ($assignments === []) {
        continue;
    } ?>
    <div class="card table-wrap mt-6">
        <div class="card-head"><div class="h3"><?= View::text($heading) ?></div></div>
        <table class="table">
            <thead>
                <tr>
                    <th>Booth</th>
                    <th>Start</th>
                    <th>End</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($assignments as $assignment): ?>
                <tr>
                    <td><a href="/<?= View::text($slug) ?>/booths#booth-<?= View::text($assignment['booth_id']) ?>"><?= View::text($assignment['booth_code']) ?></a></td>
                    <td><?= View::text($assignment['starts_on']) ?></td>
                    <td>
                        <?php if ($assignment['ends_on'] === ''): ?>
                            <form class="row-form" method="post" action="/<?= View::text($slug) ?>/assignments/<?= View::text($assignment['id']) ?>/end">
                                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                <label class="sr-only" for="ends-<?= View::text($assignment['id']) ?>">End date</label>
                                <input class="input" id="ends-<?= View::text($assignment['id']) ?>" name="ends_on" type="date" required min="<?= View::text($assignment['starts_on']) ?>">
                                <button class="btn btn-sm" type="submit">End</button>
                            </form>
                        <?php else: ?>
                            <?= View::text($assignment['ends_on']) ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endforeach; ?>

<div class="card mt-6">
    <div class="card-head"><div class="h3">Assign a booth</div></div>
    <form method="post" action="/<?= View::text($slug) ?>/assignments">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <input type="hidden" name="vendor_id" value="<?= View::text($vendor['id']) ?>">
        <div class="card-body grid cols-2">
            <div class="field">
                <label for="assign_booth">Booth</label>
                <select class="select" id="assign_booth" name="booth_id" required>
                    <?php foreach ($booths as $booth): ?>
                        <option value="<?= View::text($booth['id']) ?>"><?= View::text($booth['code']) ?></option>
                    <?php endforeach; ?>
                </select>
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
</div>
