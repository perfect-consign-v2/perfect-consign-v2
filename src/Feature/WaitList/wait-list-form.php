<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array<string, mixed>|null $entry */

$id = is_array($entry) ? ($entry['id'] ?? null) : null;
$editing = is_string($id) && $id !== '';
$action = $editing ? '/' . $slug . '/wait-list/' . $id : '/' . $slug . '/wait-list';
$heading = $editing ? 'Edit wait list' : 'New person';
$first = is_array($entry) && is_string($entry['first_name'] ?? null) ? $entry['first_name'] : '';
$last = is_array($entry) && is_string($entry['last_name'] ?? null) ? $entry['last_name'] : '';
$email = is_array($entry) && is_string($entry['email'] ?? null) ? $entry['email'] : '';
$phone = is_array($entry) && is_string($entry['phone'] ?? null) ? $entry['phone'] : '';
$notes = is_array($entry) && is_string($entry['notes'] ?? null) ? $entry['notes'] : '';
$width = is_array($entry) ? ($entry['width_ft'] ?? '') : '';
$depth = is_array($entry) ? ($entry['depth_ft'] ?? '') : '';
$status = is_array($entry) && is_string($entry['status'] ?? null) ? $entry['status'] : 'waiting';
$crumb = $editing && $first !== '' ? trim($first . ' ' . $last) : 'New';
$statuses = [
    'waiting' => 'Waiting',
    'contacted' => 'Contacted',
    'placed' => 'Placed',
    'declined' => 'Declined',
];
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb">
            <a href="/<?= View::text($slug) ?>/wait-list">Wait list</a><span class="sep">/</span>
            <span><?= View::text($crumb) ?></span>
        </nav>
        <h1 class="h1"><?= View::text($heading) ?></h1>
    </div>
    <a class="btn" href="/<?= View::text($slug) ?>/wait-list"><svg aria-hidden="true"><use href="#i-arrow-left"></use></svg> Back</a>
</div>

<form method="post" action="<?= View::text($action) ?>" class="grid form-split">
    <div class="card">
        <div class="card-head"><div class="h3">Person</div></div>
        <div class="card-body grid cols-2">
            <div class="field">
                <label for="first_name">First name</label>
                <input class="input" id="first_name" name="first_name" value="<?= View::text($first) ?>" required>
            </div>
            <div class="field">
                <label for="last_name">Last name</label>
                <input class="input" id="last_name" name="last_name" value="<?= View::text($last) ?>" required>
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input class="input" id="email" name="email" type="email" value="<?= View::text($email) ?>" required>
            </div>
            <div class="field">
                <label for="phone">Phone</label>
                <input class="input" id="phone" name="phone" value="<?= View::text($phone) ?>" required>
            </div>
            <div class="field">
                <label for="width_ft">Width (ft)</label>
                <input class="input" id="width_ft" name="width_ft" inputmode="numeric" value="<?= View::text($width) ?>">
            </div>
            <div class="field">
                <label for="depth_ft">Depth (ft)</label>
                <input class="input" id="depth_ft" name="depth_ft" inputmode="numeric" value="<?= View::text($depth) ?>">
            </div>
            <?php if ($editing): ?>
                <div class="field" style="grid-column: 1 / -1">
                    <label for="status">Status</label>
                    <select class="select" id="status" name="status">
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= View::text($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= View::text($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="field" style="grid-column: 1 / -1">
                <label for="notes">Notes</label>
                <textarea class="textarea" id="notes" name="notes" maxlength="2000"><?= View::text($notes) ?></textarea>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><div class="h3">Booth</div></div>
        <div class="card-body">
            <p class="muted text-sm">Leave the size empty for any booth. Enter both width and depth in whole feet when they want a size. A new person starts as Waiting. Placed and Declined leave the open queue, and that email can be added again.</p>
        </div>
        <div class="card-foot row between">
            <a class="btn btn-ghost" href="/<?= View::text($slug) ?>/wait-list">Cancel</a>
            <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> <?= $editing ? 'Save changes' : 'Add to wait list' ?></button>
        </div>
    </div>
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
</form>
