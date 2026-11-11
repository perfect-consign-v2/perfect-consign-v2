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
        <p class="muted">Parties with the customer role. A search matches the phone digits.</p>
    </div>
    <a class="btn btn-primary" href="/<?= View::text($slug) ?>/customers/new"><svg aria-hidden="true"><use href="#i-plus"></use></svg> New customer</a>
</div>
<form class="row gap-2 mt-6" method="get" action="/<?= View::text($slug) ?>/customers" role="search">
    <div class="field">
        <label for="phone">Phone</label>
        <input class="input" id="phone" name="phone" type="tel" inputmode="tel" data-phone maxlength="14" value="<?= View::text(View::phone($phone)) ?>" autocomplete="off">
    </div>
    <p><button class="btn" type="submit">Search</button></p>
</form>
<?php if ($customers === []): ?>
    <div class="card mt-6">
        <div class="empty">
            <p><?= $phone === '' ? 'No customers yet.' : 'No customer has that phone.' ?></p>
            <?php if ($phone === ''): ?>
                <p><a class="btn btn-primary" href="/<?= View::text($slug) ?>/customers/new">New customer</a></p>
            <?php endif; ?>
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
                    <td><a href="/<?= View::text($slug) ?>/customers/<?= View::text($customer['id']) ?>"><?= View::text($customer['name']) ?></a></td>
                    <td><?= View::text(View::phone($customer['phone'])) ?></td>
                    <td><?= View::text($customer['email']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php ListTable::assets(); ?>
