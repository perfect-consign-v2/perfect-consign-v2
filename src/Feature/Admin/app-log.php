<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var int $tailKb */
/** @var array{
 *     path: string,
 *     exists: bool,
 *     bytes: int,
 *     modified_at: string,
 *     truncated: bool,
 *     body: string
 * } $log */
?>
<h1>App log</h1>
<p class="muted">
    Platform and store errors write to <code><?= View::text($log['path']) ?></code>
    (for example failed store signup). This page shows the newest
    <?= View::text((string) $tailKb) ?> KB.
    Clearing empties the file on this host; it does not delete Admin → Audit rows.
</p>
<div class="card mt-4">
    <div class="card-head"><div class="h3">File</div></div>
    <div class="card-body stack">
        <p>
            Path: <code><?= View::text($log['path']) ?></code>
        </p>
        <p>
            Size: <strong><?= View::text((string) $log['bytes']) ?></strong> byte(s)
            <?php if ($log['modified_at'] !== ''): ?>
                · last write <strong><?= View::text($log['modified_at']) ?></strong>
            <?php endif; ?>
        </p>
        <?php if (!$log['exists']): ?>
            <p class="muted">No log file yet. It appears after the first logged event.</p>
        <?php elseif ($log['truncated']): ?>
            <p class="warning">Showing the end of the file only. Older lines were omitted.</p>
        <?php endif; ?>
    </div>
</div>
<div class="row gap-3 mt-4">
    <form method="post" action="/admin/log/clear">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <button class="btn" type="submit">Clear application log</button>
    </form>
</div>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Contents</div></div>
    <div class="card-body" data-style-prop="overflow:auto">
        <?php if ($log['body'] === ''): ?>
            <p class="muted">Log is empty.</p>
        <?php else: ?>
            <pre class="mono text-sm" data-style-prop="white-space:pre-wrap; margin:0"><?= View::text($log['body']) ?></pre>
        <?php endif; ?>
    </div>
</div>
