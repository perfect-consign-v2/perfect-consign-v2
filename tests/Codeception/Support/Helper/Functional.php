<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Codeception\Support\Helper;

use Codeception\Module;
use Codeception\Module\PhpBrowser;
use PerfectConsign\Tests\Codeception\ProbeHarness;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\ProbeSurfaceSeed;
use RuntimeException;

final class Functional extends Module
{
    public function loginAsOwner(): void
    {
        $browser = $this->phpBrowser();
        $browser->client?->restart();
        $browser->amOnPage('/');
        $browser->submitForm('form[action="/"]', [
            'email' => ProbeMall::EMAIL,
            'password' => ProbeMall::PASSWORD,
        ]);
        $browser->see("Today's sales");
    }

    public function loginAs(string $email, string $password): void
    {
        $browser = $this->phpBrowser();
        $browser->client?->restart();
        $browser->amOnPage('/');
        $browser->submitForm('form[action="/"]', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    private function phpBrowser(): PhpBrowser
    {
        $browser = $this->getModule('PhpBrowser');
        if (!$browser instanceof PhpBrowser) {
            throw new RuntimeException('PhpBrowser module is required');
        }

        return $browser;
    }

    public function mallSlug(): string
    {
        return ProbeMall::SLUG;
    }

    public function probeClient(): FormClient
    {
        return ProbeHarness::require()->client();
    }

    public function probeSeed(): ProbeSurfaceSeed
    {
        return ProbeHarness::require()->seed();
    }
}
