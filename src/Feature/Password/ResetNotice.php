<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

final class ResetNotice
{
    public const SENT = 'If that sign-in can receive a reset link, it is on its way. The link works for 30 minutes.';

    public const EXPIRED = 'This reset link has expired. Request a new one.';
}
