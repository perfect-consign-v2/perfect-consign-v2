<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $csrf */
/** @var string $query */
/** @var string $status */
/** @var array{open: int, waiting: int, contacted: int, placed: int, declined: int} $counts */
/** @var int $total */
/** @var string $confirm */
/** @var list<array{id: string, name: string, email: string, phone: string, size: string, status: string, status_label: string, status_badge: string, notes: string, waiting_since: string, waited: string}> $entries */

$list = '/' . $slug . '/wait-list';
$shows = [
    'all' => 'All',
    '' => 'Open (' . $counts['open'] . ')',
    'waiting' => 'Waiting (' . $counts['waiting'] . ')',
    'contacted' => 'Contacted (' . $counts['contacted'] . ')',
    'placed' => 'Placed (' . $counts['placed'] . ')',
    'declined' => 'Declined (' . $counts['declined'] . ')',
];
$empty = $status === '' && $query === '' ? 'No one is waiting.' : 'No one matches.';
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Wait list</h1>
        <p class="muted">People who want a booth when none are open.</p>
    </div>
    <a class="btn btn-primary" href="/<?= View::text($slug) ?>/wait-list/new"><svg aria-hidden="true"><use href="#i-plus"></use></svg> New person</a>
</div>

<?php if ($total === 0): ?>
    <div class="card">
        <div class="empty">
            <svg aria-hidden="true"><use href="#i-clock"></use></svg>
            <p>No one is waiting.</p>
        </div>
    </div>
<?php else: ?>
    <form class="row gap-2 mt-6" method="get" action="<?= View::text($list) ?>" role="search">
        <?php if ($status !== ''): ?>
            <input type="hidden" name="status" value="<?= View::text($status) ?>">
        <?php endif; ?>
        <input class="input" style="max-width:20rem" type="search" name="q" value="<?= View::text($query) ?>" placeholder="Search name, email, or phone" aria-label="Search the wait list">
        <button class="btn" type="submit">Search</button>
    </form>

    <div class="list-show">
        <p class="muted">Show</p>
        <div class="segmented" role="navigation" aria-label="Show">
            <?php foreach ($shows as $value => $label): ?>
                <?php
                $params = [];
                if ($value !== '') {
                    $params['status'] = $value;
                }
                if ($query !== '') {
                    $params['q'] = $query;
                }
                $href = $list;
                if ($params !== []) {
                    $href .= '?' . http_build_query($params);
                }
                $selected = $status === $value;
                ?>
                <a class="seg-btn<?= $selected ? ' is-selected' : '' ?>" href="<?= View::text($href) ?>"<?= $selected ? ' aria-current="page"' : '' ?>><?= View::text($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card table-wrap list-dt-wrap mt-4">
        <?php if ($entries === []): ?>
            <div class="empty"><p><?= View::text($empty) ?></p></div>
        <?php else: ?>
            <?php
            ListTable::open(['id' => 'wait-list', 'export' => 'wait-list']);
            ListTable::head([
                ['label' => 'Name'],
                ['label' => 'Email'],
                ['label' => 'Phone'],
                ['label' => 'Size'],
                ['label' => 'Status'],
                ['label' => 'Waiting since'],
                ['label' => '', 'actions' => true],
            ]);
            ?>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <?php
                    $keep = [];
                    if ($status !== '') {
                        $keep['status'] = $status;
                    }
                    if ($query !== '') {
                        $keep['q'] = $query;
                    }
                    $cancel = $list . ($keep === [] ? '' : '?' . http_build_query($keep));
                    $ask = $keep;
                    $ask['confirm'] = $entry['id'];
                    $delete = $list . '?' . http_build_query($ask);
                    $badge = $entry['status_badge'] === '' ? 'badge' : 'badge ' . $entry['status_badge'];
                    ?>
                    <tr id="wait-<?= View::text($entry['id']) ?>">
                        <td><a class="strong" href="/<?= View::text($slug) ?>/wait-list/<?= View::text($entry['id']) ?>"><?= View::text($entry['name']) ?></a></td>
                        <td><a class="mailto" href="mailto:<?= View::text($entry['email']) ?>"><?= View::text($entry['email']) ?></a></td>
                        <td><a href="tel:<?= View::text($entry['phone']) ?>"><?= View::text($entry['phone']) ?></a></td>
                        <td><?= View::text($entry['size']) ?></td>
                        <td><span class="<?= View::text($badge) ?>"><?= View::text($entry['status_label']) ?></span></td>
                        <td><?= View::date($entry['waiting_since']) ?> <span class="muted"><?= View::text($entry['waited']) ?></span></td>
                        <td class="row gap-2">
                            <a class="btn btn-sm btn-ghost" href="/<?= View::text($slug) ?>/wait-list/<?= View::text($entry['id']) ?>/edit"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Edit</a>
                            <?php if ($confirm === $entry['id']): ?>
                                <span>Remove this person?</span>
                                <a class="btn btn-sm btn-ghost" href="<?= View::text($cancel) ?>">Cancel</a>
                                <form method="post" action="/<?= View::text($slug) ?>/wait-list/<?= View::text($entry['id']) ?>/delete">
                                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                    <button class="btn btn-sm" type="submit"><svg aria-hidden="true"><use href="#i-trash"></use></svg> Delete</button>
                                </form>
                            <?php else: ?>
                                <a class="btn btn-sm" href="<?= View::text($delete) ?>"><svg aria-hidden="true"><use href="#i-trash"></use></svg> Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php ListTable::assets(); ?>
<?php endif; ?>
