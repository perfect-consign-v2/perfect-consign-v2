<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string|null $error */
/** @var string|null $notice */
/** @var string|null $warning */
/** @var string|null $mallError */
/** @var string $modalError */
/** @var string $instantError */

$error = $error ?? null;
$notice = $notice ?? null;
$warning = $warning ?? null;
$mallError = $mallError ?? null;
$modalError = $modalError ?? null;
$instantError = $instantError ?? null;
$flashKind = '';
$flashText = '';
if (is_string($error) && $error !== '') {
    $flashKind = 'danger';
    $flashText = $error;
} elseif (is_string($mallError) && $mallError !== '') {
    $flashKind = 'danger';
    $flashText = $mallError;
} elseif (is_string($modalError) && $modalError !== '') {
    $flashKind = 'danger';
    $flashText = $modalError;
} elseif (is_string($instantError) && $instantError !== '') {
    $flashKind = 'danger';
    $flashText = $instantError;
} elseif (is_string($warning) && $warning !== '') {
    $flashKind = 'warning';
    $flashText = $warning;
} elseif (is_string($notice) && $notice !== '') {
    $flashKind = 'success';
    $flashText = $notice;
}
$panelClass = match ($flashKind) {
    'success' => 'alert alert-success flash-panel',
    'warning' => 'alert alert-warning flash-panel',
    'danger' => 'alert alert-danger flash-panel',
    default => 'flash-panel',
};
$textClass = match ($flashKind) {
    'success' => ' class="notice"',
    'warning' => ' class="warning"',
    'danger' => ' class="error"',
    default => '',
};
?>
<dialog class="flash-dialog" id="flash-dialog" data-kind="<?= View::text($flashKind) ?>" data-message="<?= View::text($flashText) ?>" aria-labelledby="flash-message">
    <div class="<?= View::text($panelClass) ?>" id="flash-panel">
        <div id="flash-message"><p<?= $textClass ?>><?= View::text($flashText) ?></p></div>
        <button class="btn btn-primary" type="button" id="flash-ok">OK</button>
    </div>
</dialog>
<script src="/assets/js/flash-dialog.js"></script>
