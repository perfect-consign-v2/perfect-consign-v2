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
<script>
(function () {
    var dialog = document.getElementById('flash-dialog');
    var panel = document.getElementById('flash-panel');
    var message = document.getElementById('flash-message');
    var text = message ? message.querySelector('p') : null;
    var ok = document.getElementById('flash-ok');
    var timer = 0;
    if (!dialog || !panel || !text || !ok || typeof dialog.showModal !== 'function') {
        return;
    }
    var tones = { success: 'success', warning: 'warning', danger: 'danger' };
    var labels = { success: 'notice', warning: 'warning', danger: 'error' };
    function shut() {
        if (timer !== 0) {
            window.clearTimeout(timer);
            timer = 0;
        }
        if (dialog.open) {
            dialog.close();
        }
    }
    dialog.addEventListener('cancel', function (event) {
        if (dialog.getAttribute('data-kind') !== 'success') {
            event.preventDefault();
        }
    });
    dialog.addEventListener('close', function () {
        if (timer !== 0) {
            window.clearTimeout(timer);
            timer = 0;
        }
    });
    ok.addEventListener('click', shut);
    window.pcFlash = function (copy, kind) {
        var resolved = kind === 'success' || kind === 'warning' ? kind : 'danger';
        if (!copy) {
            return;
        }
        shut();
        panel.className = 'alert alert-' + tones[resolved] + ' flash-panel';
        text.className = labels[resolved];
        text.textContent = copy;
        dialog.setAttribute('data-kind', resolved);
        dialog.showModal();
        ok.focus();
        if (resolved === 'success') {
            timer = window.setTimeout(shut, 2000);
        }
    };
    window.pcFlashClose = shut;
    if ((dialog.getAttribute('data-message') || '') !== '') {
        dialog.showModal();
        ok.focus();
        if (dialog.getAttribute('data-kind') === 'success') {
            timer = window.setTimeout(shut, 2000);
        }
    }
})();
</script>
