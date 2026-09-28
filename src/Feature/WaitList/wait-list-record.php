<?php

declare(strict_types=1);

use PerfectConsign\Feature\Party\PartyRecord;
use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var array{id: string, name: string, email: string, phone: string, size: string, status: string, status_label: string, status_badge: string, notes: string, waiting_since: string, waited: string} $entry */

$badge = $entry['status_badge'] === '' ? 'badge' : 'badge ' . $entry['status_badge'];
$blank = '—';
?>
<article id="wait-<?= View::text($entry['id']) ?>">
    <div class="page-head">
        <div class="titles">
            <nav class="breadcrumb">
                <a href="/<?= View::text($slug) ?>/wait-list">Wait list</a><span class="sep">/</span>
                <span><?= View::text($entry['name']) ?></span>
            </nav>
            <h1 class="h1"><?= View::text($entry['name']) ?></h1>
            <ul class="vendor-pills">
                <li><span class="<?= View::text($badge) ?>"><?= View::text($entry['status_label']) ?></span></li>
                <li><span class="badge"><?= View::text($entry['size']) ?></span></li>
            </ul>
        </div>
        <a class="btn" href="/<?= View::text($slug) ?>/wait-list/<?= View::text($entry['id']) ?>/edit"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Edit</a>
    </div>

    <div class="grid cols-2 mt-4">
        <section class="card" aria-labelledby="wait-contact">
            <div class="card-head"><div class="h3" id="wait-contact">Contact</div></div>
            <div class="card-body">
                <?php PartyRecord::list([
                    ['label' => 'Email', 'value' => $entry['email'], 'href' => 'mailto:' . $entry['email']],
                    ['label' => 'Phone', 'value' => $entry['phone'], 'href' => 'tel:' . $entry['phone']],
                    ['label' => 'Size', 'value' => $entry['size'], 'href' => ''],
                    ['label' => 'Waiting since', 'value' => $entry['waiting_since'] . ' (' . $entry['waited'] . ')', 'href' => ''],
                ], $blank); ?>
            </div>
        </section>
        <section class="card" aria-labelledby="wait-notes">
            <div class="card-head"><div class="h3" id="wait-notes">Notes</div></div>
            <div class="card-body">
                <?php if ($entry['notes'] === ''): ?>
                    <p class="muted">No notes.</p>
                <?php else: ?>
                    <p class="keep-lines"><?= View::text($entry['notes']) ?></p>
                <?php endif; ?>
            </div>
        </section>
    </div>
</article>
