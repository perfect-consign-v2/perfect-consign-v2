<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

/**
 * What a door found when it checked the password: no such account, the
 * wrong password, or the right one.
 */
enum SignInCheck
{
    case NoAccount;
    case Wrong;
    case Right;
}
