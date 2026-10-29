<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Feature\Password\ResetNotice;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;

final class PasswordResetTest extends WebTestCase
{
    // __construct( open( stamp( matches( owner( staff( version( reset( assume( parse( hash( missingMall(
    // current( changed( replacement( find( shown( ownerEmail( staffEmail( email( table( issue(
    // available( lock( consume( expire( insert( rollback( allow( changeOwner( changeStaff( emailOwner(
    // emailStaff( writeOwner( writeStaff( storeOwnerEmail( storeStaffEmail( ownerHash( staffHash(
    // request( redeem( deliver( letter( replace( identity( clear( username( send( file( smtp(
    // settings( text( raw( port( unsafe( command( expect( read( write( payload( show( change(
    // saveEmail( account( render( form( submit( replaceCashier( credentialsMatch( mailFile( environment( clearMailbox( mailbox(
    // signInPath( signOut(
    public function testAWrongCurrentPasswordIsRefused(): void
    {
        $client = self::client();
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $page = $client->post('/' . ProbeMall::SLUG . '/password', [
            'current_password' => 'not-the-password',
            'password' => 'another-password',
            'confirm' => 'another-password',
        ]);

        self::assertSees('That password is wrong.', $page);
        self::assertSees('Change password', $page);
        self::assertSees('Changing your password signs you out.', $page);
    }

    public function testAResetLinkChangesThePasswordAndTheOldSessionEnds(): void
    {
        $slug = ProbeMall::SLUG;
        $owner = self::client();
        $login = $owner->get('/');
        self::assertSees('Forgot password', $login);
        $owner->signIn($slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $username = 'c' . bin2hex(random_bytes(4));
        $email = $username . '@example.com';
        $added = $owner->post('/' . $slug . '/staff', [
            'username' => $username,
            'display_name' => 'Reset Cashier',
            'password' => 'cashier-pass',
            'email' => $email,
        ]);
        self::assertSees('Cashier added.', $added);
        $staffId = $this->match(
            $added->body,
            '#/staff/([0-9a-fA-F-]{36})/password" data-cashier="' . preg_quote($username, '#') . '#'
        );

        self::clearMailbox();
        self::flushMail();
        self::clearMailbox();
        $guest = self::client();
        $guest->get('/forgot');
        $unknown = $guest->post('/forgot', ['email' => 'missing-user@example.com']);
        self::assertSame(0, preg_match_all('#/reset/#', self::mailbox()));
        $known = $guest->post('/forgot', ['email' => $email]);
        self::assertSees(ResetNotice::SENT, $unknown);
        self::assertSees(ResetNotice::SENT, $known);
        self::assertStringNotContainsString($email, $unknown->body);
        self::assertStringNotContainsString($email, $known->body);
        self::assertSame(0, preg_match_all('#/reset/#', self::mailbox()));
        self::flushMail();
        $token = $this->match(self::mailbox(), '#/reset/([A-Za-z0-9_-]{43})#');
        self::assertSame(1, preg_match_all('#/reset/#', self::mailbox()));

        $reset = $guest->post('/' . $slug . '/reset/' . $token, [
            'password' => 'cashier-next',
            'confirm' => 'cashier-next',
        ]);
        self::assertSees('Password updated. Sign in.', $reset);
        $again = $guest->get('/' . $slug . '/reset/' . $token);
        self::assertSees(ResetNotice::EXPIRED, $again);
        $stale = self::client()->signIn($slug, $username, 'cashier-pass');
        self::assertSees('That email or password is wrong.', $stale);

        $cashier = self::client();
        $cashier->signIn($slug, $username, 'cashier-next');
        $cashier->get('/' . $slug . '/password');
        $staffEmail = $cashier->post('/' . $slug . '/password/email', [
            'current_password' => 'cashier-next',
            'email' => $username . '-new@example.com',
        ]);
        self::assertSees('Sign-in email saved. Sign in with your new email.', $staffEmail);
        self::assertSees('Sign in', $staffEmail);
        $cashier->signInByEmail($username . '-new@example.com', 'cashier-next');
        $refused = $cashier->post('/' . $slug . '/password', [
            'current_password' => 'not-the-password',
            'password' => 'cashier-other',
            'confirm' => 'cashier-other',
        ]);
        self::assertSees('That password is wrong.', $refused);
        $changed = $cashier->post('/' . $slug . '/password', [
            'current_password' => 'cashier-next',
            'password' => 'cashier-last',
            'confirm' => 'cashier-last',
        ]);
        self::assertSees('Password updated. Sign in with your new password.', $changed);
        self::assertSees('Sign in', $changed);
        $blocked = $cashier->post('/' . $slug . '/staff/' . $staffId . '/password', [
            'password' => 'cashier-nope',
            'email' => $email,
        ]);
        self::assertSees('Sign in', $blocked);

        $cashier->signInByEmail($username . '-new@example.com', 'cashier-last');
        $stillBlocked = $cashier->post('/' . $slug . '/staff/' . $staffId . '/password', [
            'password' => 'cashier-nope',
            'email' => $email,
        ]);
        self::assertSame(404, $stillBlocked->status);

        $owner->get('/' . $slug . '/staff');
        $saved = $owner->post('/' . $slug . '/staff/' . $staffId . '/password', [
            'password' => 'cashier-owner',
            'email' => $email,
        ]);
        self::assertSees('Cashier password saved.', $saved);
        $ended = $cashier->get('/' . $slug . '/pos');
        self::assertSees('Sign in', $ended);
        $home = self::client()->signIn($slug, $username, 'cashier-owner');
        self::assertSame(200, $home->status);
        self::assertStringNotContainsString('That username or password is wrong.', $home->body);
        self::assertStringNotContainsString('That email or password is wrong.', $home->body);
    }

    public function testASignedInOwnerChangesThePasswordAndTheSignInEmail(): void
    {
        $slug = ProbeMall::SLUG;
        $owner = self::client();
        $owner->signIn($slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $short = $owner->post('/' . $slug . '/password', [
            'current_password' => ProbeMall::PASSWORD,
            'password' => 'short',
            'confirm' => 'short',
        ]);
        self::assertSees('Use a password of at least 8 characters.', $short);
        $same = $owner->post('/' . $slug . '/password', [
            'current_password' => ProbeMall::PASSWORD,
            'password' => ProbeMall::PASSWORD,
            'confirm' => ProbeMall::PASSWORD,
        ]);
        self::assertSees('Choose a different password.', $same);
        $mismatch = $owner->post('/' . $slug . '/password/email', [
            'current_password' => 'not-the-password',
            'email' => 'owner@example.com',
        ]);
        self::assertSees('That password is wrong.', $mismatch);

        $emailPage = $owner->post('/' . $slug . '/password/email', [
            'current_password' => ProbeMall::PASSWORD,
            'email' => 'owner@example.com',
        ]);
        self::assertSees('Sign-in email saved. Sign in with your new email.', $emailPage);
        self::assertSees('Sign in', $emailPage);
        $blankLogin = $owner->post('/' . $slug . '/password/email', [
            'current_password' => ProbeMall::PASSWORD,
            'email' => 'not-an-email',
        ]);
        self::assertSees('Sign in', $blankLogin);

        $owner->signInByEmail('owner@example.com', ProbeMall::PASSWORD);
        $blank = $owner->post('/' . $slug . '/password/email', [
            'current_password' => ProbeMall::PASSWORD,
            'email' => 'not-an-email',
        ]);
        self::assertSees('Enter an email.', $blank);

        $temporary = 'web-probe-temp';
        $changed = $owner->post('/' . $slug . '/password', [
            'current_password' => ProbeMall::PASSWORD,
            'password' => $temporary,
            'confirm' => $temporary,
        ]);
        self::assertSees('Password updated. Sign in with your new password.', $changed);
        self::assertSees('Sign in', $changed);
        $blocked = $owner->get('/' . $slug . '/password');
        self::assertSees('Sign in', $blocked);

        $owner->signInByEmail('owner@example.com', $temporary);
        try {
            $restoredEmail = $owner->post('/' . $slug . '/password/email', [
                'current_password' => $temporary,
                'email' => ProbeMall::EMAIL,
            ]);
            self::assertSees('Sign-in email saved. Sign in with your new email.', $restoredEmail);
            $owner->signInByEmail(ProbeMall::EMAIL, $temporary);
            $restored = $owner->post('/' . $slug . '/password', [
                'current_password' => $temporary,
                'password' => ProbeMall::PASSWORD,
                'confirm' => ProbeMall::PASSWORD,
            ]);
            self::assertSees('Password updated. Sign in with your new password.', $restored);
        } catch (\Throwable $error) {
            $fix = self::client();
            $fix->signInByEmail('owner@example.com', $temporary);
            $fix->post('/' . $slug . '/password/email', [
                'current_password' => $temporary,
                'email' => ProbeMall::EMAIL,
            ]);
            $fix->signInByEmail(ProbeMall::EMAIL, $temporary);
            $fix->post('/' . $slug . '/password', [
                'current_password' => $temporary,
                'password' => ProbeMall::PASSWORD,
                'confirm' => ProbeMall::PASSWORD,
            ]);
            throw $error;
        }

        $home = self::client()->signIn($slug, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSame(200, $home->status);
        self::assertStringNotContainsString('That username or password is wrong.', $home->body);
        self::assertStringNotContainsString('That email or password is wrong.', $home->body);
    }

    private function match(string $html, string $pattern): string
    {
        $found = preg_match($pattern, $html, $matched);
        if ($found !== 1 || !isset($matched[1])) {
            self::fail('the page did not contain ' . $pattern);
        }

        return $matched[1];
    }
}
