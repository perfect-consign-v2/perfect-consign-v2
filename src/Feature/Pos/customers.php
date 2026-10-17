<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $phone */
/** @var list<array{id: string, name: string, phone: string, email: string}> $customers */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Customers</h1>
        <p class="muted">People already stored on a sale. A search matches the phone exactly.</p>
    </div>
    <a class="btn btn-primary" href="/<?= View::text($slug) ?>/pos">Sell</a>
</div>
<form class="row gap-2 mt-6" method="get" action="/<?= View::text($slug) ?>/customers" role="search">
    <div class="field">
        <label for="phone">Phone</label>
        <input class="input" id="phone" name="phone" value="<?= View::text($phone) ?>" autocomplete="off">
    </div>
    <p><button class="btn" type="submit">Search</button></p>
</form>
<?php if ($customers === []): ?>
    <div class="card mt-6">
        <div class="empty">
            <p><?= $phone === '' ? 'No customers yet. A customer is saved when a sale names them.' : 'No customer has that phone.' ?></p>
            <p><a class="btn btn-primary" href="/<?= View::text($slug) ?>/pos">Sell</a></p>
        </div>
    </div>
<?php else: ?>
    <div class="card table-wrap list-dt-wrap mt-6">
        <?php
        ListTable::open(['id' => 'customers', 'export' => 'customers']);
        ListTable::head([
            ['label' => 'Name'],
            ['label' => 'Phone'],
            ['label' => 'Email'],
        ]);
        ?>
            <tbody>
            <?php foreach ($customers as $customer): ?>
                <tr>
                    <td><?= View::text($customer['name']) ?></td>
                    <td><?= View::text($customer['phone']) ?></td>
                    <td><?= View::text($customer['email']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php ListTable::assets(); ?>
