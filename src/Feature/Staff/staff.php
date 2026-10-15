<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array{id: string, username: string, display_name: string, role: string, email: string}> $cashiers */
/** @var string|null $error */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Cashiers</h1>
        <p class="muted">A cashier can sell, take rent, and close their drawer.</p>
    </div>
</div>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Add a cashier</div></div>
    <form class="card-body stack" method="post" action="/<?= View::text($slug) ?>/staff">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
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
            <input class="input" id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
        </div>
        <div class="field">
            <label for="email">Recovery email</label>
            <input class="input" id="email" name="email" type="email" required maxlength="200" autocomplete="email">
        </div>
        <p><button class="btn btn-primary" type="submit">Add cashier</button></p>
    </form>
</div>
<div class="card mt-6">
    <div class="card-body">
        <?php if ($cashiers === []): ?>
            <p class="muted">No cashiers yet.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($cashiers as $cashier): ?>
                    <li>
                        <p><?= View::text($cashier['display_name']) ?> (<?= View::text($cashier['username']) ?>)</p>
                        <form class="stack" method="post" action="/<?= View::text($slug) ?>/staff/<?= View::text($cashier['id']) ?>/password" data-cashier="<?= View::text($cashier['username']) ?>">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <div class="field">
                                <label for="email-<?= View::text($cashier['id']) ?>">Recovery email</label>
                                <input class="input" id="email-<?= View::text($cashier['id']) ?>" name="email" type="email" required maxlength="200" value="<?= View::text($cashier['email']) ?>" autocomplete="email">
                            </div>
                            <div class="field">
                                <label for="password-<?= View::text($cashier['id']) ?>">New password</label>
                                <input class="input" id="password-<?= View::text($cashier['id']) ?>" name="password" type="password" required minlength="8" autocomplete="new-password">
                            </div>
                            <p><button class="btn" type="submit">Save cashier sign-in</button></p>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
