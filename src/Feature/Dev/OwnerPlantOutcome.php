<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Dev;

enum OwnerPlantOutcome: string
{
    case Unchanged = 'unchanged';
    case ProfileSaved = 'profile_saved';
    case Saved = 'saved';
}
