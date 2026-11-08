<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array{id: string, username: string, display_name: string, role: string, email: string, can_sign_in: bool}> $cashiers */
/** @var list<array{id: string, username: string, display_name: string, role: string, email: string, can_sign_in: bool}> $managers */
$managers = is_array($managers ?? null) ? $managers : [];
/** @var list<array{id: string, username: string, display_name: string, role: string, email: string, can_sign_in: bool}> $readers */
$readers = is_array($readers ?? null) ? $readers : [];
/** @var list<array{id: string, username: string, email: string, can_sign_in: bool}> $owners */
$owners = is_array($owners ?? null) ? $owners : [];
/** @var string $actorAccount */
$actorAccount = is_string($actorAccount ?? null) ? $actorAccount : '';
/** @var string|null $error */

$staffSections = [
    [
        'title' => 'Cashiers',
        'empty' => 'No cashiers yet.',
        'people' => $cashiers,
        'passwordAction' => 'password',
        'passwordLabel' => 'Save cashier sign-in',
        'dataAttr' => 'data-cashier',
    ],
    [
        'title' => 'Managers',
        'empty' => 'No managers yet.',
        'people' => $managers,
        'passwordAction' => 'manager-password',
        'passwordLabel' => 'Save manager sign-in',
        'dataAttr' => null,
    ],
    [
        'title' => 'Books',
        'empty' => 'No books login yet.',
        'people' => $readers,
        'passwordAction' => 'books-password',
        'passwordLabel' => 'Save books sign-in',
        'dataAttr' => null,
    ],
];
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">People</h1>
        <p class="muted">Add cashiers, managers, books logins, and co-owners. Suspend to block sign-in. Change role among cashier, manager, and books. Vendor portal sign-in stays on each <a href="/<?= View::text($slug) ?>/<?= ($consignmentOnly ?? false) === true ? 'consignors' : 'vendors' ?>">seller</a> page.</p>
    </div>
</div>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Add a person</div></div>
    <form class="card-body stack" method="post" action="/<?= View::text($slug) ?>/staff">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <div class="field">
            <label for="role">Role</label>
            <select class="input" id="role" name="role" required>
                <option value="cashier">Cashier</option>
                <option value="manager">Manager</option>
                <option value="books">Books</option>
                <option value="owner">Owner</option>
            </select>
            <p class="muted text-sm">Cashiers sell. Managers run the floor (no Settings, People, or vendor pay). Books read money. Owners have full mall access.</p>
        </div>
        <div class="field">
            <label for="display_name">Name</label>
            <input class="input" id="display_name" name="display_name" maxlength="80">
            <p class="muted text-sm">Required for cashiers, managers, and books. Not used for owners.</p>
        </div>
        <div class="field">
            <label for="username">Sign-in name</label>
            <input class="input" id="username" name="username" required maxlength="63" autocapitalize="none" spellcheck="false">
            <p class="muted text-sm">Letters, numbers, and hyphens.</p>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <?php View::requiredPasswordField(id: 'password', name: 'password', minlength: 8, autocomplete: 'new-password'); ?>
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input class="input" id="email" name="email" type="email" required maxlength="200" autocomplete="email">
        </div>
        <p><button class="btn btn-primary" type="submit">Add person</button></p>
    </form>
</div>
<?php foreach ($staffSections as $section): ?>
<div class="card mt-6">
    <div class="card-head"><div class="h3"><?= View::text($section['title']) ?></div></div>
    <div class="card-body">
        <?php if ($section['people'] === []): ?>
            <p class="muted"><?= View::text($section['empty']) ?></p>
        <?php else: ?>
            <ul>
                <?php foreach ($section['people'] as $person): ?>
                    <?php
                    $canSignIn = ($person['can_sign_in'] ?? true) === true;
                    $personId = (string) $person['id'];
                    $passwordPath = '/' . $slug . '/staff/' . $personId . '/' . $section['passwordAction'];
                    ?>
                    <li>
                        <p>
                            <?= View::text($person['display_name']) ?> (<?= View::text($person['username']) ?>)
                            <?php if (!$canSignIn): ?>
                                <span class="muted"> · Suspended</span>
                            <?php endif; ?>
                        </p>
                        <form class="stack" method="post" action="<?= View::text($passwordPath) ?>"<?php if (is_string($section['dataAttr'])): ?> <?= View::text($section['dataAttr']) ?>="<?= View::text($person['username']) ?>"<?php endif; ?>>
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <div class="field">
                                <label for="email-<?= View::text($personId) ?>">Email</label>
                                <input class="input" id="email-<?= View::text($personId) ?>" name="email" type="email" required maxlength="200" value="<?= View::text($person['email']) ?>" autocomplete="email">
                            </div>
                            <div class="field">
                                <label for="password-<?= View::text($personId) ?>">New password</label>
                                <?php View::requiredPasswordField(id: 'password-' . $personId, name: 'password', minlength: 8, autocomplete: 'new-password'); ?>
                            </div>
                            <p><button class="btn" type="submit"><?= View::text($section['passwordLabel']) ?></button></p>
                        </form>
                        <form class="stack mt-4" method="post" action="/<?= View::text($slug) ?>/staff/<?= View::text($personId) ?>/role">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <div class="field">
                                <label for="role-<?= View::text($personId) ?>">Role</label>
                                <select class="input" id="role-<?= View::text($personId) ?>" name="role" required>
                                    <option value="cashier"<?= $person['role'] === 'cashier' ? ' selected' : '' ?>>Cashier</option>
                                    <option value="manager"<?= $person['role'] === 'manager' ? ' selected' : '' ?>>Manager</option>
                                    <option value="books"<?= $person['role'] === 'books' ? ' selected' : '' ?>>Books</option>
                                </select>
                            </div>
                            <p><button class="btn" type="submit">Save role</button></p>
                        </form>
                        <?php if ($canSignIn): ?>
                            <form class="stack mt-4" method="post" action="/<?= View::text($slug) ?>/staff/<?= View::text($personId) ?>/suspend">
                                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                <p><button class="btn" type="submit">Suspend</button></p>
                            </form>
                        <?php else: ?>
                            <form class="stack mt-4" method="post" action="/<?= View::text($slug) ?>/staff/<?= View::text($personId) ?>/restore">
                                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                <p><button class="btn" type="submit">Restore</button></p>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Owners</div></div>
    <div class="card-body">
        <?php if ($owners === []): ?>
            <p class="muted">No owners on this mall.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($owners as $owner): ?>
                    <?php $ownerCanSignIn = ($owner['can_sign_in'] ?? true) === true; ?>
                    <li>
                        <p>
                            <?= View::text($owner['username']) ?><?php if ($owner['email'] !== ''): ?> · <?= View::text($owner['email']) ?><?php endif; ?>
                            <?php if (!$ownerCanSignIn): ?>
                                <span class="muted"> · Suspended</span>
                            <?php endif; ?>
                        </p>
                        <form class="stack" method="post" action="/<?= View::text($slug) ?>/staff/owners/<?= View::text($owner['id']) ?>/password">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <div class="field">
                                <label for="owner-email-<?= View::text($owner['id']) ?>">Email</label>
                                <input class="input" id="owner-email-<?= View::text($owner['id']) ?>" name="email" type="email" required maxlength="200" value="<?= View::text($owner['email']) ?>" autocomplete="email">
                            </div>
                            <div class="field">
                                <label for="owner-password-<?= View::text($owner['id']) ?>">New password</label>
                                <?php View::requiredPasswordField(id: 'owner-password-' . (string) $owner['id'], name: 'password', minlength: 8, autocomplete: 'new-password'); ?>
                            </div>
                            <p><button class="btn" type="submit">Save owner sign-in</button></p>
                        </form>
                        <?php if ($ownerCanSignIn): ?>
                            <form class="stack mt-4" method="post" action="/<?= View::text($slug) ?>/staff/owners/<?= View::text($owner['id']) ?>/suspend">
                                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                <p><button class="btn" type="submit">Suspend</button></p>
                            </form>
                        <?php else: ?>
                            <form class="stack mt-4" method="post" action="/<?= View::text($slug) ?>/staff/owners/<?= View::text($owner['id']) ?>/restore">
                                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                <p><button class="btn" type="submit">Restore</button></p>
                            </form>
                        <?php endif; ?>
                        <?php if ($owner['id'] !== $actorAccount): ?>
                            <form class="stack mt-4" method="post" action="/<?= View::text($slug) ?>/staff/owners/<?= View::text($owner['id']) ?>/remove">
                                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                <p><button class="btn btn-danger" type="submit">Remove owner</button></p>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
