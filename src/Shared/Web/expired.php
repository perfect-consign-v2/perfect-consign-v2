<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

$retry = is_string($path ?? null) ? $path : '';
if (preg_match('#\A/(?!/)[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*\z#', $retry) !== 1) {
    $retry = '/';
}
$home = is_string($slug ?? null) && preg_match('/\A[a-z][a-z0-9-]{0,62}\z/', $slug) === 1 ? $slug : '';
?>
<p>The form expired.</p>
<p><a href="<?= View::text($retry) ?>">Try again</a></p>
<p><a href="/">Sign in</a></p>
<?php if ($home !== ''): ?>
    <p><a href="/<?= View::text($home) ?>/">Open your store</a></p>
<?php endif; ?>
