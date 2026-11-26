<?php

declare(strict_types=1);

use PerfectConsign\Feature\Admin\SignInAttempts;
use PerfectConsign\Shared\Http\View;

/** @var \PerfectConsign\Feature\Admin\AdminPath $adminPath */
/** @var string $email */
/** @var string $address */
/** @var string $outcome */
/** @var list<array{at: string, door: string, email: string, store: string, username: string, address: string, agent: string, outcome: string, trusted: bool}> $attempts */
$filtered = $email !== '' || $address !== '' || $outcome !== '';
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Sign-ins</h1>
        <p class="muted">Every try at a store or admin sign-in, newest first. Showing up to <?= View::text((string) SignInAttempts::MOST_LISTED) ?>.</p>
    </div>
</div>
<form class="activity-filters" method="get" action="<?= View::text($adminPath->url('/sign-ins')) ?>">
    <div class="field">
        <label for="sign-ins-email">Email</label>
        <input id="sign-ins-email" type="email" name="email" value="<?= View::text($email) ?>">
    </div>
    <div class="field">
        <label for="sign-ins-address">Address</label>
        <input id="sign-ins-address" type="text" name="address" value="<?= View::text($address) ?>">
    </div>
    <div class="field">
        <label for="sign-ins-outcome">What happened</label>
        <select id="sign-ins-outcome" name="outcome">
            <option value="">Everything</option>
            <?php foreach (SignInAttempts::OUTCOMES as $code => $words): ?>
                <option value="<?= View::text($code) ?>"<?= $outcome === $code ? ' selected' : '' ?>><?= View::text($words) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn btn-primary" type="submit">Show</button>
    <?php if ($filtered): ?>
        <a class="btn" href="<?= View::text($adminPath->url('/sign-ins')) ?>">All sign-ins</a>
    <?php endif; ?>
</form>
<div class="card mt-6">
    <div class="card-body">
        <?php if ($attempts === []): ?>
            <p class="muted">No sign-ins<?= $filtered ? ' for that filter' : '' ?>.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>When</th>
                        <th>What happened</th>
                        <th>Email</th>
                        <th>Store</th>
                        <th>Address</th>
                        <th>Browser</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($attempts as $attempt): ?>
                        <tr>
                            <td><?= View::text($attempt['at']) ?></td>
                            <td>
                                <?= View::text(SignInAttempts::words($attempt['outcome'])) ?>
                                <?php if ($attempt['trusted']): ?>
                                    <br><span class="muted text-sm">from a browser that signed in before</span>
                                <?php endif; ?>
                            </td>
                            <td><?= View::text($attempt['email']) ?><?= $attempt['door'] === 'admin' ? ' <span class="muted text-sm">(admin)</span>' : '' ?></td>
                            <td>
                                <?= View::text($attempt['store']) ?>
                                <?php if ($attempt['username'] !== ''): ?>
                                    <br><span class="muted text-sm"><?= View::text($attempt['username']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><a href="<?= View::text($adminPath->url('/sign-ins') . '?address=' . rawurlencode($attempt['address'])) ?>"><?= View::text($attempt['address']) ?></a></td>
                            <td class="text-sm muted"><?= View::text($attempt['agent']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
