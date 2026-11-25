<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var int $tailKb */
/** @var list<array{address: string, banned_at: string, banned_until: string, active: bool, strikes: int, reason: string, path: string}> $bans */
/** @var \PerfectConsign\Feature\Admin\AdminPath $adminPath */
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
    <form method="post" action="<?= View::text($adminPath->url('/log/clear')) ?>">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <button class="btn" type="submit">Clear application log</button>
    </form>
</div>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Banned addresses</div></div>
    <div class="card-body">
        <p class="muted text-sm">Every ban ends on its own. A ban for probe requests lasts fifteen minutes. A scanning tool's ban that comes back within a week lasts longer. Lift ban ends it now and forgets the earlier ones.</p>
        <?php if ($bans === []): ?>
            <p class="muted">No addresses are banned.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Address</th>
                        <th>When</th>
                        <th>Ends</th>
                        <th>Times</th>
                        <th>Reason</th>
                        <th>Path</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bans as $ban): ?>
                        <tr>
                            <td><?= View::text($ban['address']) ?></td>
                            <td><?= View::text($ban['banned_at']) ?></td>
                            <td><?= $ban['active'] ? View::text($ban['banned_until']) : 'Ended' ?></td>
                            <td class="num"><?= View::text((string) $ban['strikes']) ?></td>
                            <td><?= View::text($ban['reason']) ?></td>
                            <td><?= View::text($ban['path']) ?></td>
                            <td>
                                <form method="post" action="<?= View::text($adminPath->url('/log/lift')) ?>">
                                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                    <input type="hidden" name="address" value="<?= View::text($ban['address']) ?>">
                                    <button class="btn btn-sm" type="submit"><?= $ban['active'] ? 'Lift ban' : 'Forget' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
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
