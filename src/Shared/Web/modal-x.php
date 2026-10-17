<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $closeModal */
?>
<button class="modal-x" type="button" data-close-modal="<?= View::text($closeModal) ?>" aria-label="Close">
    <svg aria-hidden="true"><use href="#i-x"></use></svg>
</button>
