<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var list<array<string, mixed>> $accounts */
/** @var list<array<string, mixed>> $periods */
?>
<h1>Accounts</h1>
<h2>Periods</h2>
<div class="list-dt-wrap">
<?php
ListTable::open(['id' => 'book-periods', 'export' => 'book-periods']);
ListTable::head([
    ['label' => 'From'],
    ['label' => 'To'],
    ['label' => 'Status'],
]);
?>
    <tbody>
    <?php foreach ($periods as $period): ?>
        <tr>
            <td><?= View::text($period['starts_on']) ?></td>
            <td><?= View::text($period['ends_on']) ?></td>
            <td><?= View::text($period['status']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<h2>Accounts</h2>
<div class="list-dt-wrap">
<?php
ListTable::open(['id' => 'book-accounts', 'export' => 'book-accounts']);
ListTable::head([
    ['label' => 'Code'],
    ['label' => 'Name'],
    ['label' => 'Type'],
    ['label' => 'Debits', 'sort' => 'num'],
    ['label' => 'Credits', 'sort' => 'num'],
]);
?>
    <tbody>
    <?php foreach ($accounts as $account): ?>
        <tr>
            <td><?= View::text($account['code']) ?></td>
            <td><?= View::text($account['name']) ?></td>
            <td><?= View::text($account['account_type']) ?></td>
            <td><?= View::text(View::money(View::minor($account['debit_minor']))) ?></td>
            <td><?= View::text(View::money(View::minor($account['credit_minor']))) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php ListTable::assets(); ?>
