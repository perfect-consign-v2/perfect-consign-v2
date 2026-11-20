<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var \PerfectConsign\Feature\Admin\AdminPath $adminPath */
/** @var array{
 *     password_configured: bool,
 *     last_seen_at: string,
 *     last_worker: string,
 *     worker_stale: bool,
 *     last_jobs_finished: int,
 *     last_jobs_failed: int,
 *     waiting_mail: int,
 *     overdue_jobs: int,
 *     auto_prune_days: int,
 *     site_smtp: bool,
 *     platform_gmail: bool
 * } $status */
/** @var list<array{
 *     id: string,
 *     tenant: string,
 *     kind: string,
 *     run_at: string,
 *     finished_at: string,
 *     attempts: int,
 *     error: string,
 *     subject: string,
 *     recipients: string,
 *     transport: string,
 *     due: bool
 * }> $queue */
/** @var list<array{
 *     id: string,
 *     tenant: string,
 *     kind: string,
 *     run_at: string,
 *     finished_at: string,
 *     attempts: int,
 *     error: string,
 *     subject: string,
 *     recipients: string,
 *     transport: string,
 *     due: bool
 * }> $givenUp */
/** @var list<array{
 *     id: string,
 *     worker: string,
 *     started_at: string,
 *     finished_at: string,
 *     jobs_finished: int,
 *     jobs_failed: int
 * }> $runs */
$queue = is_array($queue ?? null) ? $queue : [];
$givenUp = is_array($givenUp ?? null) ? $givenUp : [];
?>
<h1>Mail worker</h1>
<p class="muted">
    Store digests, sale notices, vendor letters, and other queued mail leave through
    <code>php bin/work.php</code> (or <code>--forever</code>). Mall password resets, platform admin resets, and receipt emails send in the web request.
    Idle passes only refresh the heartbeat; the run log records passes that finished or failed at least one job.
    The worker auto-prunes the run log after <?= View::text((string) $status['auto_prune_days']) ?> days.
</p>
<div class="card mt-4">
    <div class="card-head"><div class="h3">Setup</div></div>
    <div class="card-body stack">
        <p>
            Worker password in <code>.env</code>:
            <strong><?= $status['password_configured'] ? 'set' : 'missing' ?></strong>
            (<code>STALL_WORKER_PASSWORD</code>).
        </p>
        <p>
            Last heartbeat:
            <strong><?= View::text($status['last_seen_at'] !== '' ? $status['last_seen_at'] : 'never') ?></strong>
            <?php if ($status['last_worker'] !== ''): ?>
                as <strong><?= View::text($status['last_worker']) ?></strong>
            <?php endif; ?>
            (last pass finished <?= View::text((string) $status['last_jobs_finished']) ?>,
            failed <?= View::text((string) $status['last_jobs_failed']) ?>)
        </p>
        <p>
            Site SMTP:
            <strong><?= $status['site_smtp'] ? 'set' : 'missing' ?></strong>
            (<code>STALL_SMTP_HOST</code>, <code>STALL_SMTP_USERNAME</code>, <code>STALL_SMTP_PASSWORD</code>, <code>STALL_SMTP_FROM</code>).
        </p>
        <p>
            Platform Gmail:
            <strong><?= $status['platform_gmail'] ? 'set' : 'missing' ?></strong>
            (<code>STALL_GMAIL_ADDRESS</code>, <code>STALL_GMAIL_APP_PASSWORD</code>).
        </p>
        <p>
            Waiting mail in outbox: <strong><?= View::text((string) $status['waiting_mail']) ?></strong>
        </p>
        <p>
            Overdue jobs: <strong><?= View::text((string) $status['overdue_jobs']) ?></strong>
        </p>
        <?php if (!$status['password_configured']): ?>
            <p class="warning">Set <code>STALL_WORKER_PASSWORD</code> and run <code>php bin/work.php</code> (or schedule <code>--forever</code>).</p>
        <?php elseif ($status['last_seen_at'] === ''): ?>
            <p class="warning">Worker password is set, but no heartbeat has been recorded yet. Start <code>php bin/work.php</code>.</p>
        <?php elseif ($status['worker_stale']): ?>
            <p class="warning">The worker last ran at <?= View::text($status['last_seen_at']) ?> as <?= View::text($status['last_worker']) ?>. Start <code>php bin/work.php --forever</code>.</p>
        <?php endif; ?>
    </div>
</div>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Open jobs</div></div>
    <div class="card-body" data-style-prop="overflow:auto">
        <table class="table">
            <thead>
            <tr>
                <th>Store</th>
                <th>Kind</th>
                <th>Due</th>
                <th>Attempts</th>
                <th>Subject</th>
                <th>To</th>
                <th>Transport</th>
                <th>Error</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($queue === []): ?>
                <tr><td colspan="8" class="muted">No open jobs.</td></tr>
            <?php endif; ?>
            <?php foreach ($queue as $job): ?>
                <tr>
                    <td><?= View::text($job['tenant']) ?></td>
                    <td><?= View::text($job['kind']) ?></td>
                    <td class="mono"><?= View::text($job['run_at']) ?><?= $job['due'] ? ' due' : '' ?></td>
                    <td><?= View::text((string) $job['attempts']) ?></td>
                    <td><?= View::text($job['subject']) ?></td>
                    <td><?= View::text($job['recipients']) ?></td>
                    <td><?= View::text($job['transport']) ?></td>
                    <td><?= View::text($job['error']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Given up</div></div>
    <div class="card-body" data-style-prop="overflow:auto">
        <table class="table">
            <thead>
            <tr>
                <th>Store</th>
                <th>Kind</th>
                <th>Stopped</th>
                <th>Attempts</th>
                <th>Subject</th>
                <th>To</th>
                <th>Transport</th>
                <th>Error</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($givenUp === []): ?>
                <tr><td colspan="8" class="muted">No jobs have been given up.</td></tr>
            <?php endif; ?>
            <?php foreach ($givenUp as $job): ?>
                <tr>
                    <td><?= View::text($job['tenant']) ?></td>
                    <td><?= View::text($job['kind']) ?></td>
                    <td class="mono"><?= View::text($job['finished_at']) ?></td>
                    <td><?= View::text((string) $job['attempts']) ?></td>
                    <td><?= View::text($job['subject']) ?></td>
                    <td><?= View::text($job['recipients']) ?></td>
                    <td><?= View::text($job['transport']) ?></td>
                    <td><?= View::text($job['error']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="row gap-3 mt-4">
    <form method="post" action="<?= View::text($adminPath->url('/worker/prune')) ?>">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <input type="hidden" name="days" value="30">
        <button class="btn" type="submit">Prune older than 30 days</button>
    </form>
    <form method="post" action="<?= View::text($adminPath->url('/worker/clear')) ?>">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <button class="btn" type="submit">Clear entire run log</button>
    </form>
</div>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Run log (jobs only)</div></div>
    <div class="card-body" data-style-prop="overflow:auto">
        <table class="table">
            <thead>
            <tr>
                <th>Started</th>
                <th>Finished</th>
                <th>Worker</th>
                <th>Finished jobs</th>
                <th>Failed</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($runs === []): ?>
                <tr><td colspan="5" class="muted">No job-bearing runs recorded yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($runs as $run): ?>
                <tr>
                    <td class="mono"><?= View::text($run['started_at']) ?></td>
                    <td class="mono"><?= View::text($run['finished_at'] !== '' ? $run['finished_at'] : 'running') ?></td>
                    <td><?= View::text($run['worker']) ?></td>
                    <td><?= View::text((string) $run['jobs_finished']) ?></td>
                    <td><?= View::text((string) $run['jobs_failed']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
