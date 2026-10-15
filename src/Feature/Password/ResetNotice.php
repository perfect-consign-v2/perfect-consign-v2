<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Password;

final class ResetNotice
{
    public const SENT = 'If that sign-in has a recovery email, a reset link is on its way. The link works for 30 minutes.';

    public const EXPIRED = 'This reset link has expired. Request a new one.';
}
