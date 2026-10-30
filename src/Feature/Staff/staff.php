<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array{id: string, username: string, display_name: string, role: string, email: string}> $cashiers */
/** @var list<array{id: string, username: string, display_name: string, role: string, email: string}> $managers */
$managers = is_array($managers ?? null) ? $managers : [];
/** @var list<array{id: string, username: string, display_name: string, role: string, email: string}> $readers */
$readers = is_array($readers ?? null) ? $readers : [];
/** @var string|null $error */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">People</h1>
        <p class="muted">Cashiers sell. Managers run the floor. Books logins read sales and money. Vendor portal sign-in stays on each <a href="/<?= View::text($slug) ?>/parties">vendor</a> page.</p>
    </div>
</div>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Add a cashier</div></div>
    <form class="card-body stack" method="post" action="/<?= View::text($slug) ?>/staff">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <p class="muted text-sm">A cashier can sell, take rent, and close their drawer.</p>
        <div class="field">
            <label for="display_name">Name</label>
            <input class="input" id="display_name" name="display_name" required maxlength="80">
        </div>
        <div class="field">
            <label for="username">Sign-in name</label>
            <input class="input" id="username" name="username" required maxlength="63" autocapitalize="none" spellcheck="false">
            <p class="muted text-sm">Letters, numbers, and hyphens. This is what they type to sign in.</p>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <?php View::requiredPasswordField(id: 'password', name: 'password', minlength: 8, autocomplete: 'new-password'); ?>
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input class="input" id="email" name="email" type="email" required maxlength="200" autocomplete="email">
        </div>
        <p><button class="btn btn-primary" type="submit">Add cashier</button></p>
    </form>
</div>
<div class="card mt-6">
    <div class="card-body">
        <?php if ($cashiers === []): ?>
            <p class="muted">No cashiers yet. Add a cashier above.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($cashiers as $cashier): ?>
                    <li>
                        <p><?= View::text($cashier['display_name']) ?> (<?= View::text($cashier['username']) ?>)</p>
                        <form class="stack" method="post" action="/<?= View::text($slug) ?>/staff/<?= View::text($cashier['id']) ?>/password" data-cashier="<?= View::text($cashier['username']) ?>">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <div class="field">
                                <label for="email-<?= View::text($cashier['id']) ?>">Email</label>
                                <input class="input" id="email-<?= View::text($cashier['id']) ?>" name="email" type="email" required maxlength="200" value="<?= View::text($cashier['email']) ?>" autocomplete="email">
                            </div>
                            <div class="field">
                                <label for="password-<?= View::text($cashier['id']) ?>">New password</label>
                                <?php View::requiredPasswordField(id: 'password-' . (string) $cashier['id'], name: 'password', minlength: 8, autocomplete: 'new-password'); ?>
                            </div>
                            <p><button class="btn" type="submit">Save cashier sign-in</button></p>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Add a manager</div></div>
    <form class="card-body stack" method="post" action="/<?= View::text($slug) ?>/staff/managers">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <p class="muted text-sm">A manager can sell, run the floor, and read this month, payouts, and reports. They cannot change Settings or People, and they cannot pay vendors.</p>
        <div class="field">
            <label for="manager_display_name">Name</label>
            <input class="input" id="manager_display_name" name="display_name" required maxlength="80">
        </div>
        <div class="field">
            <label for="manager_username">Sign-in name</label>
            <input class="input" id="manager_username" name="username" required maxlength="63" autocapitalize="none" spellcheck="false">
        </div>
        <div class="field">
            <label for="manager_password">Password</label>
            <?php View::requiredPasswordField(id: 'manager_password', name: 'password', minlength: 8, autocomplete: 'new-password'); ?>
        </div>
        <div class="field">
            <label for="manager_email">Email</label>
            <input class="input" id="manager_email" name="email" type="email" required maxlength="200" autocomplete="email">
        </div>
        <p><button class="btn btn-primary" type="submit">Add manager</button></p>
    </form>
</div>
<div class="card mt-6">
    <div class="card-body">
        <?php if ($managers === []): ?>
            <p class="muted">No managers yet. Add a manager above.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($managers as $manager): ?>
                    <li>
                        <p><?= View::text($manager['display_name']) ?> (<?= View::text($manager['username']) ?>)</p>
                        <form class="stack" method="post" action="/<?= View::text($slug) ?>/staff/<?= View::text($manager['id']) ?>/manager-password">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <div class="field">
                                <label for="manager-email-<?= View::text($manager['id']) ?>">Email</label>
                                <input class="input" id="manager-email-<?= View::text($manager['id']) ?>" name="email" type="email" required maxlength="200" value="<?= View::text($manager['email']) ?>" autocomplete="email">
                            </div>
                            <div class="field">
                                <label for="manager-password-<?= View::text($manager['id']) ?>">New password</label>
                                <?php View::requiredPasswordField(id: 'manager-password-' . (string) $manager['id'], name: 'password', minlength: 8, autocomplete: 'new-password'); ?>
                            </div>
                            <p><button class="btn" type="submit">Save manager sign-in</button></p>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Add a books login</div></div>
    <form class="card-body stack" method="post" action="/<?= View::text($slug) ?>/staff/books">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <p class="muted text-sm">A books login can read sales, this month, rent, payouts, and the money record. They cannot sell, change tax, pay vendors, or download the mall.</p>
        <div class="field">
            <label for="books_display_name">Name</label>
            <input class="input" id="books_display_name" name="display_name" required maxlength="80">
        </div>
        <div class="field">
            <label for="books_username">Sign-in name</label>
            <input class="input" id="books_username" name="username" required maxlength="63" autocapitalize="none" spellcheck="false">
        </div>
        <div class="field">
            <label for="books_password">Password</label>
            <?php View::requiredPasswordField(id: 'books_password', name: 'password', minlength: 8, autocomplete: 'new-password'); ?>
        </div>
        <div class="field">
            <label for="books_email">Email</label>
            <input class="input" id="books_email" name="email" type="email" required maxlength="200" autocomplete="email">
        </div>
        <p><button class="btn btn-primary" type="submit">Add books login</button></p>
    </form>
</div>
<div class="card mt-6">
    <div class="card-body">
        <?php if ($readers === []): ?>
            <p class="muted">No books login yet. Add one above.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($readers as $reader): ?>
                    <li>
                        <p><?= View::text($reader['display_name']) ?> (<?= View::text($reader['username']) ?>)</p>
                        <form class="stack" method="post" action="/<?= View::text($slug) ?>/staff/<?= View::text($reader['id']) ?>/books-password">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <div class="field">
                                <label for="books-email-<?= View::text($reader['id']) ?>">Email</label>
                                <input class="input" id="books-email-<?= View::text($reader['id']) ?>" name="email" type="email" required maxlength="200" value="<?= View::text($reader['email']) ?>" autocomplete="email">
                            </div>
                            <div class="field">
                                <label for="books-password-<?= View::text($reader['id']) ?>">New password</label>
                                <?php View::requiredPasswordField(id: 'books-password-' . (string) $reader['id'], name: 'password', minlength: 8, autocomplete: 'new-password'); ?>
                            </div>
                            <p><button class="btn" type="submit">Save books sign-in</button></p>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
