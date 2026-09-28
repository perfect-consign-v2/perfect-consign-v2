<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var string $mall */
/** @var string|null $mallError */

$mall = $mall ?? '';
$mallError = $mallError ?? null;
?>
<div class="login-wrap">
    <div class="panel">
        <div class="row gap-3" style="justify-content:center; margin-bottom:var(--space-6)">
            <span class="brand-mark" style="width:40px;height:40px;font-size:var(--text-lg)">P</span>
            <div>
                <div class="h2">Perfect Consign</div>
                <div class="text-sm muted">Mall register</div>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h1>Open your mall</h1></div>
            <form method="post" action="/">
                <div class="card-body grid" style="gap:var(--space-4)">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <?php if ($mallError !== null && $mallError !== ''): ?>
                        <div class="alert alert-danger" role="alert">
                            <p class="error"><?= View::text($mallError) ?></p>
                        </div>
                    <?php endif; ?>
                    <div class="field">
                        <label for="mall">Mall</label>
                        <input class="input" id="mall" name="mall" value="<?= View::text($mall) ?>" placeholder="mall" autocomplete="off" autocapitalize="none" spellcheck="false" required autofocus>
                    </div>
                </div>
                <div class="card-foot row between">
                    <span></span>
                    <button class="btn btn-primary" type="submit">Continue</button>
                </div>
            </form>
        </div>
    </div>
</div>
