<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Mall\MallDay;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Party\AssignmentBook;
use PerfectConsign\Feature\Party\AssignmentController;
use PerfectConsign\Feature\Party\AssignmentOffer;
use PerfectConsign\Feature\Party\BoothController;
use PerfectConsign\Feature\Party\BoothFields;
use PerfectConsign\Feature\Party\BoothListFilter;
use PerfectConsign\Feature\Party\BoothListStore;
use PerfectConsign\Feature\Party\BoothMeasures;
use PerfectConsign\Feature\Party\BoothRoster;
use PerfectConsign\Feature\Party\BoothStayLookup;
use PerfectConsign\Feature\Party\LetterController;
use PerfectConsign\Feature\Party\LetterDesk;
use PerfectConsign\Feature\Party\LetterDraft;
use PerfectConsign\Feature\Party\MapController;
use PerfectConsign\Feature\Party\MapStore;
use PerfectConsign\Feature\Party\PartyContactBook;
use PerfectConsign\Feature\Party\PartyContactNormalizer;
use PerfectConsign\Feature\Party\PartyController;
use PerfectConsign\Feature\Party\PartyIdentity;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Party\SellerPaths;
use PerfectConsign\Feature\Party\VendorDraft;
use PerfectConsign\Feature\Party\VendorDraftPlaces;
use PerfectConsign\Feature\Party\VendorForm;
use PerfectConsign\Feature\Party\VendorFormScalars;
use PerfectConsign\Feature\Party\VendorPage;
use PerfectConsign\Feature\Party\VendorPlacement;
use PerfectConsign\Feature\Party\VendorRoster;
use PerfectConsign\Feature\Party\VendorSignInController;
use PerfectConsign\Feature\Party\VendorYearPaid;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Feature\Party\VendorBalances;
use PerfectConsign\Feature\Staff\StaffStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Jobs\JobQueue;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PerfectConsign\Tests\Feature\Coverage\RoutedStatements;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class PartyMissCoverageTest extends TestCase
{
    use CapturesPage;

    private const BOOTH = '01900000-0000-7000-8000-0000000000b1';

    private const STAY = '01900000-0000-7000-8000-0000000000b2';

    private const FLOOR = '01900000-0000-7000-8000-0000000000b3';

    public function testPartyPagesStandingAndRemoval(): void
    {
        $door = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/parties/' . MallDoor::PARTY, [], []));
        $html = $this->capture(function () use ($door): void {
            $this->controller($door)->party(MallDoor::SLUG, MallDoor::PARTY);
        });
        self::assertStringContainsString('Pat', $html);

        $list = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/parties', [], []));
        $empty = $this->capture(function () use ($list): void {
            $this->controller($list)->show(MallDoor::SLUG);
        });
        self::assertStringContainsString('No parties yet', $empty);

        $standing = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/vendors/' . MallDoor::VENDOR . '/standing', [
            'standing' => 'active',
        ]));
        $standing->statements->affectedRefuses = true;
        $gone = $this->capture(function () use ($standing): void {
            $this->controller($standing)->setStanding(MallDoor::SLUG, MallDoor::VENDOR);
        });
        self::assertStringContainsString('Not found', $gone);

        $refused = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/vendors/' . MallDoor::VENDOR . '/delete'));
        $refused->statements->affectedRefuses = true;
        $page = $this->capture(function () use ($refused): void {
            $this->controller($refused)->remove(MallDoor::SLUG, MallDoor::VENDOR);
        });
        self::assertStringContainsString('refused', $page);

        $unknown = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/vendors/' . MallDoor::VENDOR . '/delete'));
        $unknown->statements->affectedCount = 0;
        $missingVendor = $this->capture(function () use ($unknown): void {
            $this->controller($unknown)->remove(MallDoor::SLUG, MallDoor::VENDOR);
        });
        self::assertStringContainsString('that vendor is not in this mall', $missingVendor);

        $removed = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/vendors/' . MallDoor::VENDOR . '/delete'));
        $removed->statements->affectedCount = 1;
        $this->capture(function () use ($removed): void {
            $this->controller($removed)->remove(MallDoor::SLUG, MallDoor::VENDOR);
        });
        self::assertSame('Vendor deleted.', $removed->bag['notice'] ?? null);
    }

    public function testAssignmentsOffersAndBoothRefusals(): void
    {
        $book = new AssignmentBook($this->routed());
        try {
            $book->vendorRowsFor('nope', '2026-01-01');
            self::fail('a bad vendor listed stays');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a vendor id is required', $error->getMessage());
        }
        try {
            $book->changeRent('nope', '2026-01-01', 100);
            self::fail('a bad stay changed rent');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose a stay.', $error->getMessage());
        }
        try {
            $book->changeRent(self::STAY, 'yesterday', 100);
            self::fail('a bad date changed rent');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Enter an effective date.', $error->getMessage());
        }
        try {
            $book->changeRent(self::STAY, '2026-01-01', 0);
            self::fail('a zero rent was saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Rent must be greater than zero.', $error->getMessage());
        }
        try {
            $book->changeRent(self::STAY, '2026-01-01', 100);
            self::fail('a missing stay changed rent');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose a stay.', $error->getMessage());
        }
        $dated = $this->routed();
        $dated->boundRoutes[] = ['match' => 'booth_assignments', 'rows' => [['starts_on' => '2026-01-01', 'ends_on' => '2026-01-02']]];
        try {
            (new AssignmentBook($dated))->changeRent(self::STAY, '2026-02-01', 100);
            self::fail('a late rent was saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('The effective date cannot be after the stay ends.', $error->getMessage());
        }
        try {
            $book->vendorRows('yesterday');
            self::fail('a bad day listed vendors');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a vendor day is YYYY-MM-DD', $error->getMessage());
        }

        $parties = $this->createStub(PartyStore::class);
        $parties->method('booths')->willReturn([['id' => self::BOOTH, 'code' => 'A1']]);
        $offer = new AssignmentOffer($parties, new AssignmentBook($this->routed()));
        $offer->limitStaySearch(0);
        try {
            $offer->booths('2026-01-01');
            self::fail('a closed search offered a booth');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a booth stay does not end', $error->getMessage());
        }

        $controller = (new ReflectionClass(AssignmentController::class))->newInstanceWithoutConstructor();
        $paths = new ReflectionClass($controller);
        $property = $paths->getProperty('sellerPaths');
        $property->setValue($controller, new SellerPaths(MallDoor::open($this, new Request('GET', '/', [], []))->mall));
        $path = new ReflectionMethod(AssignmentController::class, 'vendorPath');
        self::assertSame('/' . MallDoor::SLUG . '/vendors', $path->invoke($controller, MallDoor::SLUG, 'nope'));

        $lookup = new BoothStayLookup();
        $lookup->hideHolder(true);
        try {
            (new BoothRoster(new BoothMeasures(), $lookup))->assemble(
                [$this->boothRow()],
                [$this->stayRow('2026-06-01')],
                [],
                [],
                [],
                '2026-01-15',
                10,
                10,
            );
            self::fail('a hidden holder was listed');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a booth holder is missing', $error->getMessage());
        }
        $openEnd = new BoothStayLookup();
        $openEnd->overrideHolderEnd('');
        $rows = (new BoothRoster(new BoothMeasures(), $openEnd))->assemble(
            [$this->boothRow()],
            [$this->stayRow('2026-06-01')],
            [],
            [],
            [],
            '2026-01-15',
            10,
            10,
        );
        self::assertSame('Ada', $rows[0]['status_tip'] ?? null);
        $badDay = new BoothStayLookup();
        $badDay->overrideHolderEnd('nope');
        try {
            (new BoothRoster(new BoothMeasures(), $badDay))->assemble(
                [$this->boothRow()],
                [$this->stayRow('2026-06-01')],
                [],
                [],
                [],
                '2026-01-15',
                10,
                10,
            );
            self::fail('a bad end date was shown');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a booth date is YYYY-MM-DD', $error->getMessage());
        }

        $boothStatements = $this->routed();
        $boothStatements->affectedError = new DatabaseRefusal('the books refused');
        $boothDoor = MallDoor::open($this, new Request('GET', '/', [], []));
        $booths = new PartyStore($boothStatements, new JournalAudit($boothDoor->actor, new AuditLog($boothStatements)), new AssignmentBook($boothStatements));
        $door = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/booths/' . self::BOOTH . '/delete'));
        $rent = $this->withRentals($door);
        $removed = $this->capture(function () use ($door, $booths, $rent): void {
            $this->booths($door, $booths, null, $rent['app'], $rent['mall'])->remove(MallDoor::SLUG, self::BOOTH);
        });
        self::assertStringContainsString('the books refused', $removed);

        $asking = new AssignmentBook($this->routed());
        $statements = $this->routed();
        $statements->changeError = new DatabaseRefusal('the books refused');
        $asking = new AssignmentBook($statements);
        $found = $this->createStub(PartyStore::class);
        $found->method('findBooth')->willReturn(['id' => self::BOOTH, 'code' => 'A1', 'name' => 'Front', 'amount' => '10.00']);
        $rate = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/rates', [
            'booth_id' => self::BOOTH,
            'amount' => '10.00',
        ]));
        $rateRent = $this->withRentals($rate);
        $form = $this->capture(function () use ($rate, $found, $asking, $rateRent): void {
            $this->booths($rate, $found, $asking, $rateRent['app'], $rateRent['mall'])->addRate(MallDoor::SLUG);
        });
        self::assertStringContainsString('the books refused', $form);
    }

    public function testLettersMapsAndVendorSignIn(): void
    {
        $mailbox = $this->createStub(LetterMailbox::class);
        $mailbox->method('ready')->willReturn(true);
        $blank = $this->routed();
        $door = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/letters', [
            'subject' => '',
            'message' => 'Hello',
        ]));
        $html = $this->capture(function () use ($door, $blank, $mailbox): void {
            $this->letters($door, $blank, $mailbox)->file(MallDoor::SLUG);
        });
        self::assertStringContainsString('Enter a subject.', $html);

        $filed = $this->routed();
        $filed->rowRoutes[] = ['match' => 'IS NOT NULL', 'rows' => [['id' => MallDoor::VENDOR, 'email' => 'a@b.co']]];
        $filed->rowRoutes[] = ['match' => 'uuidv7', 'rows' => [['id' => self::STAY]]];
        $filed->transactionError = new DatabaseRefusal('the books refused');
        $again = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/letters', [
            'subject' => 'Hello',
            'message' => 'Body',
            'everyone' => '1',
        ]));
        $refused = $this->capture(function () use ($again, $filed, $mailbox): void {
            $this->letters($again, $filed, $mailbox)->file(MallDoor::SLUG);
        });
        self::assertStringContainsString('the books refused', $refused);

        $missing = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/letters/' . self::STAY, [], []));
        $this->capture(function () use ($missing, $mailbox): void {
            $this->letters($missing, $this->routed(), $mailbox)->read(MallDoor::SLUG, self::STAY);
        });

        $parties = $this->createStub(PartyStore::class);
        $parties->method('booths')->willReturn([]);
        $broken = $this->routed();
        $broken->transactionError = new DatabaseRefusal('the books refused');
        $layout = json_encode([[
            'id' => self::BOOTH,
            'floorId' => self::FLOOR,
            'x' => 1,
            'z' => 1,
            'width' => 10,
            'depth' => 10,
        ]]);
        self::assertIsString($layout);
        $save = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/map/edit', ['layout' => $layout]));
        $saveRent = $this->withRentals($save);
        $saved = $this->capture(function () use ($save, $broken, $parties, $saveRent): void {
            $this->maps($save, $broken, $parties, $saveRent['app'], $saveRent['mall'])->save(MallDoor::SLUG);
        });
        self::assertStringContainsString('the books refused', $saved);

        $missingLayout = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/map/edit', [
            'layout' => '[{}]',
        ]));
        $missingRent = $this->withRentals($missingLayout);
        $badLayout = $this->capture(function () use ($missingLayout, $parties, $missingRent): void {
            $this->maps($missingLayout, $this->routed(), $parties, $missingRent['app'], $missingRent['mall'])->save(MallDoor::SLUG);
        });
        self::assertStringContainsString('map layout is missing', $badLayout);

        $adding = $this->routed();
        $adding->rowRoutes[] = ['match' => 'count(*)', 'rows' => [['total' => 0]]];
        $adding->boundError = new DatabaseRefusal('the books refused');
        $floor = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/map/floors', ['name' => 'Main']));
        $floorRent = $this->withRentals($floor);
        $added = $this->capture(function () use ($floor, $adding, $parties, $floorRent): void {
            $this->maps($floor, $adding, $parties, $floorRent['app'], $floorRent['mall'])->addFloor(MallDoor::SLUG);
        });
        self::assertStringContainsString('the books refused', $added);

        $renaming = $this->routed();
        $renaming->affectedError = new DatabaseRefusal('the books refused');
        $rename = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/map/floors/' . self::FLOOR, ['name' => 'Main']));
        $renameRent = $this->withRentals($rename);
        $renamed = $this->capture(function () use ($rename, $renaming, $parties, $renameRent): void {
            $this->maps($rename, $renaming, $parties, $renameRent['app'], $renameRent['mall'])->renameFloor(MallDoor::SLUG, self::FLOOR);
        });
        self::assertStringContainsString('the books refused', $renamed);

        $deleting = $this->routed();
        $deleting->affectedError = new DatabaseRefusal('the books refused');
        $drop = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/map/floors/' . self::FLOOR . '/delete'));
        $dropRent = $this->withRentals($drop);
        $dropped = $this->capture(function () use ($drop, $deleting, $parties, $dropRent): void {
            $this->maps($drop, $deleting, $parties, $dropRent['app'], $dropRent['mall'])->removeFloor(MallDoor::SLUG, self::FLOOR);
        });
        self::assertStringContainsString('the books refused', $dropped);

        $gone = $this->routed();
        $gone->affectedCount = 1;
        $success = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/map/floors/' . self::FLOOR . '/delete'));
        $successRent = $this->withRentals($success);
        $this->capture(function () use ($success, $gone, $parties, $successRent): void {
            $this->maps($success, $gone, $parties, $successRent['app'], $successRent['mall'])->removeFloor(MallDoor::SLUG, self::FLOOR);
        });
        self::assertSame('Floor removed.', $success->bag['notice'] ?? null);

        $absent = $this->routed();
        $absent->affectedCount = 0;
        $ask = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/map/floors/' . self::FLOOR . '/delete'));
        $askRent = $this->withRentals($ask);
        $notThere = $this->capture(function () use ($ask, $absent, $parties, $askRent): void {
            $this->maps($ask, $absent, $parties, $askRent['app'], $askRent['mall'])->removeFloor(MallDoor::SLUG, self::FLOOR);
        });
        self::assertStringContainsString('that floor is not in this mall', $notThere);

        $staffStatements = $this->routed();
        $staffStatements->changeError = new DatabaseRefusal('the books refused');
        $sign = MallDoor::open($this, $this->post('/' . MallDoor::SLUG . '/vendors/' . MallDoor::VENDOR . '/sign-in', [
            'username' => 'ada',
            'display_name' => 'Ada',
            'password' => 'password1',
            'email' => 'ada@example.com',
        ]));
        $this->capture(function () use ($sign, $staffStatements): void {
            $this->signIn($sign, $staffStatements)->add(MallDoor::SLUG, MallDoor::VENDOR);
        });
    }

    public function testContactsDraftsAndABlankVendorId(): void
    {
        $book = new PartyContactBook($this->routed());
        $routed = $this->routed();
        $routed->boundRoutes[] = ['match' => 'party_contacts', 'rows' => [['value' => '555']]];
        $contacts = new PartyContactBook($routed);
        self::assertSame('555', $contacts->primaryPhone(MallDoor::PARTY));
        self::assertSame('555', $contacts->primaryEmail(MallDoor::PARTY));
        try {
            $book->address(MallDoor::PARTY, 'nope');
            self::fail('a bad address kind was read');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('address kind is physical or mailing', $error->getMessage());
        }
        try {
            $book->replaceAddresses(MallDoor::PARTY, ['street' => str_repeat('a', 201)], null);
            self::fail('a long street was saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('that street is too long', $error->getMessage());
        }

        $normal = new PartyContactNormalizer();
        try {
            $normal->normalize([['method' => 'fax', 'value' => '1']]);
            self::fail('a fax was stored');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('contact method is phone or email', $error->getMessage());
        }
        try {
            $normal->normalize([['method' => 'phone', 'label' => 'pager', 'value' => '1']]);
            self::fail('a pager label was stored');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('that contact label is not used', $error->getMessage());
        }
        try {
            $normal->normalize([
                ['method' => 'phone', 'label' => 'primary', 'value' => '1', 'is_primary' => true],
                ['method' => 'phone', 'label' => 'other', 'value' => '2', 'is_primary' => true],
            ]);
            self::fail('two primary phones were stored');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('only one primary phone is allowed', $error->getMessage());
        }
        $normal->standInStoredPhone('');
        try {
            $normal->normalize([['method' => 'phone', 'value' => '1']]);
            self::fail('a blank stored phone was kept');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('that phone number is not valid', $error->getMessage());
        }
        try {
            $normal->normalize([['method' => 'phone', 'value' => str_repeat('a', 41)]]);
            self::fail('a long phone was kept');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('that phone number is too long', $error->getMessage());
        }
        try {
            $normal->normalize([['method' => 'email', 'value' => 'not-an-email']]);
            self::fail('a bad email was kept');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('that email is not valid', $error->getMessage());
        }

        $identity = new PartyIdentity($this->routed());
        $identityStatements = $this->routed();
        $identityStatements->affectedCount = 0;
        $identity = new PartyIdentity($identityStatements);
        try {
            $identity->savePerson(MallDoor::PARTY, 'Ada', 'Lovelace', null, null, null, \PerfectConsign\Feature\Party\PersonPlace::empty());
            self::fail('a missing person was saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('that person is not in this mall', $error->getMessage());
        }
        try {
            $identity->savePersonNames(MallDoor::PARTY, 'Ada', 'Lovelace', null);
            self::fail('missing names were saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('that person is not in this mall', $error->getMessage());
        }

        $vendorStatements = $this->routed();
        $vendorStatements->rowRoutes[] = ['match' => 'INSERT INTO parties', 'rows' => [['id' => MallDoor::PARTY]]];
        $vendorStatements->boundRoutes[] = ['match' => 'INSERT INTO vendors', 'rows' => [['id' => 4]]];
        $store = new PartyStore($vendorStatements, new JournalAudit(
            MallDoor::open($this, new Request('GET', '/', [], []))->actor,
            new AuditLog($vendorStatements),
        ), new AssignmentBook($vendorStatements));
        try {
            $store->addVendor($this->draft());
            self::fail('a blank vendor id was saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('the vendor was not saved', $error->getMessage());
        }

        self::assertNull($this->draft()->email());
        try {
            $this->draft('nope')->vendorValues();
            self::fail('a bad discount was saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('who may discount is staff or owner', $error->getMessage());
        }
        try {
            $this->draft('', 'abc')->vendorValues();
            self::fail('letters were kept as days');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('how long goods stay is a number of days', $error->getMessage());
        }
        try {
            $this->draft('', '0')->vendorValues();
            self::fail('zero days were kept');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('how long goods stay is a number of days', $error->getMessage());
        }

        $places = new VendorDraftPlaces();
        $same = $places->mailing(['mailing_same' => '1', 'address' => '1 Main', 'city' => 'Bend', 'state' => 'OR', 'postal' => '97701']);
        self::assertSame('1 Main', $same['street'] ?? null);
        $listed = $places->contacts([
            'phone' => '555',
            'phones' => [4, '  ', '5415550100'],
            'email' => '',
            'emails' => [],
        ]);
        self::assertNotSame([], $listed);
        try {
            $places->physical(['address' => '', 'city' => str_repeat('c', 81), 'state' => '', 'postal' => '']);
            self::fail('a long city was stored');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('that city is too long', $error->getMessage());
        }

        $scalars = new VendorFormScalars();
        self::assertSame(12, $scalars->points('12'));
        self::assertSame(['Ada'], $scalars->stringList(['Ada', '']));
        self::assertSame('/' . MallDoor::SLUG . '/vendors/' . MallDoor::VENDOR . '/edit', (new SellerPaths(
            MallDoor::open($this, new Request('GET', '/', [], []))->mall
        ))->edit(MallDoor::SLUG, MallDoor::VENDOR));

        $formDoor = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/vendors', [], [
            'csrf' => 'csrf-token',
            'phones' => [' 5415550100 ', '', 4],
            'emails' => ['ada@example.com'],
        ]));
        $values = (new VendorForm($formDoor->app, $formDoor->mall, new SellerPaths($formDoor->mall)))->postedFormValues();
        self::assertSame(['5415550100'], $values['phones']);
    }

    public function testPartyTemplatesRenderTheUncoveredBranches(): void
    {
        $view = new View(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src');
        $this->render($view, 'Feature/Party/party', [
            'slug' => MallDoor::SLUG,
            'party' => ['id' => MallDoor::PARTY, 'name' => '', 'kind' => 'person', 'roles' => []],
            'rolesLabel' => '',
            'emptyRolesHint' => 'none',
        ]);
        $this->render($view, 'Feature/Party/party-list', [
            'slug' => MallDoor::SLUG,
            'sellerList' => '/' . MallDoor::SLUG . '/vendors',
            'sellerLabel' => 'Vendors',
            'parties' => [],
        ]);
        $this->render($view, 'Feature/Party/parties', [
            'slug' => MallDoor::SLUG,
            'consignmentOnly' => true,
            'stats' => ['vendors' => 0, 'active' => 0, 'inactive' => 0, 'ending' => 0, 'incoming' => 0],
            'vendors' => [],
            'status' => '',
            'query' => '',
        ]);
        $this->render($view, 'Feature/Party/letters', [
            'slug' => MallDoor::SLUG,
            'csrf' => 'csrf-token',
            'error' => 'Enter a subject.',
            'selected' => '',
            'party' => 'vendor',
            'partyLabel' => 'Vendors',
            'people' => [],
            'letters' => [],
        ]);
        $this->render($view, 'Feature/Party/booths', [
            'slug' => MallDoor::SLUG,
            'csrf' => 'csrf-token',
            'query' => '',
            'standing' => '',
            'counts' => ['available' => 0, 'occupied' => 1, 'ending' => 0, 'incoming' => 0],
            'confirm' => '',
            'total' => 1,
            'booths' => [[
                'id' => self::BOOTH,
                'code' => 'A1',
                'name' => 'Front',
                'width' => 10,
                'depth' => 10,
                'status' => 'occupied',
                'status_label' => 'Occupied',
                'vendor_id' => MallDoor::VENDOR,
                'vendor_name' => 'Ada',
                'status_tip' => 'Ada',
                'incoming_vendor_id' => MallDoor::PARTY,
                'incoming_vendor_code' => 'B2',
                'incoming_tip' => 'Bea',
                'rent_minor' => null,
                'open_minor' => null,
                'placed' => false,
                'deletable' => true,
            ], [
                'id' => self::BOOTH,
                'code' => 'B1',
                'name' => 'Back',
                'width' => 8,
                'depth' => 8,
                'status' => 'incoming',
                'status_label' => 'Incoming',
                'vendor_id' => MallDoor::VENDOR,
                'vendor_name' => 'Ada',
                'status_tip' => '',
                'incoming_vendor_id' => MallDoor::PARTY,
                'incoming_vendor_code' => 'C2',
                'incoming_tip' => '',
                'rent_minor' => null,
                'open_minor' => null,
                'placed' => true,
                'deletable' => false,
            ]],
        ]);
        $this->render($view, 'Feature/Party/vendor-form', [
            'slug' => MallDoor::SLUG,
            'csrf' => 'csrf-token',
            'storeTake' => '',
            'defaultStoreTake' => '10',
            'overridden' => true,
            'consignmentOnly' => true,
            'vendor' => [
                'id' => MallDoor::VENDOR,
                'code' => 'A1',
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'phones' => ['', '555'],
                'emails' => ['', 'ada@example.com'],
                'active' => true,
            ],
        ]);
        $stay = [
            'id' => self::STAY,
            'booth_id' => self::BOOTH,
            'booth_code' => 'A1',
            'starts_on' => '2025-01-01',
            'ends_on' => '2025-06-01',
            'rent_minor' => null,
        ];
        $vendor = [
            'id' => MallDoor::VENDOR,
            'code' => 'A1',
            'name' => 'Ada',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'phone' => '',
            'email' => '',
            'email_bounced' => false,
            'address' => '',
            'status_label' => 'Active',
            'status_badge' => 'badge-success',
            'store_take' => '10',
            'default_store_take' => '10',
            'store_take_overridden' => false,
            'agreement_starts_on' => '',
            'agreement_ends_on' => '',
            'tax_id' => '',
            'notes' => '',
            'tax_exempt' => false,
            'tax_exempt_number' => '',
            'payable_minor' => 0,
            'rent_minor' => 0,
            'check_minor' => 0,
            'owes_minor' => 0,
            'settled_minor' => 0,
            'clawback_minor' => 0,
            'rent_from_sales' => false,
            'current' => [],
            'upcoming' => [],
            'past' => [],
        ];
        $this->render($view, 'Feature/Party/vendor', [
            'slug' => MallDoor::SLUG,
            'csrf' => 'csrf-token',
            'today' => '2026-01-01',
            'consignmentOnly' => true,
            'vendor' => $vendor,
            'booths' => [],
            'signIn' => null,
            'rentHistories' => [],
        ]);
        $paying = $vendor;
        $paying['check_minor'] = 100;
        $paying['rent_from_sales'] = true;
        $paying['settled_minor'] = 50;
        $paying['clawback_minor'] = 25;
        $paying['past'] = [$stay];
        $paying['upcoming'] = [$stay];
        $paying['current'] = [$stay];
        $this->render($view, 'Feature/Party/vendor', [
            'slug' => MallDoor::SLUG,
            'csrf' => 'csrf-token',
            'today' => '2026-01-01',
            'sellerPrefix' => 'vendors',
            'sellerBase' => '/' . MallDoor::SLUG . '/vendors',
            'vendor' => $paying,
            'booths' => [],
            'signIn' => null,
            'rentHistories' => [],
        ]);
        $owing = $vendor;
        $owing['rent_from_sales'] = true;
        $owing['owes_minor'] = 100;
        $this->render($view, 'Feature/Party/vendor', [
            'slug' => MallDoor::SLUG,
            'csrf' => 'csrf-token',
            'today' => '2026-01-01',
            'sellerPrefix' => 'vendors',
            'sellerBase' => '/' . MallDoor::SLUG . '/vendors',
            'vendor' => $owing,
            'booths' => [],
            'signIn' => null,
            'rentHistories' => [],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(View $view, string $template, array $data): void
    {
        ob_start();
        $view->render($template, $data);
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertNotSame('', $html);
    }

    /**
     * @param array<string, string> $post
     */
    private function post(string $path, array $post = []): Request
    {
        return new Request('POST', $path, [], ['csrf' => 'csrf-token'] + $post);
    }

    private function controller(MallDoor $door): PartyController
    {
        $audit = new JournalAudit($door->actor, new AuditLog($door->statements));
        $stays = new AssignmentBook($door->statements);
        $parties = new PartyStore($door->statements, $audit, $stays);
        $roster = new VendorRoster(new VendorPlacement());
        $runs = new PayoutRunStore($door->statements, new OccupiedPack($door->statements));
        $balances = new VendorBalances(
            new PayoutStore($door->pdo, $door->statements, $runs, new PayoutWindow()),
            new VendorClawback($door->ledger, $door->statements),
            new VendorYearPaid($door->statements),
        );
        $forms = new VendorForm($door->app, $door->mall, new SellerPaths($door->mall));
        $staff = new StaffStore($door->statements, new RecoveryAddress(), new PasswordChoice());

        return new PartyController(
            $door->app,
            $parties,
            $stays,
            $door->mall,
            $roster,
            $balances,
            $forms,
            new VendorPage($door->app, $parties, $stays, $door->mall, $roster, $balances, new AssignmentOffer($parties, $stays), $forms, $staff),
        );
    }

    private function booths(MallDoor $door, PartyStore $parties, ?AssignmentBook $asking = null, ?MallApp $app = null, ?MallSettings $mall = null): BoothController
    {
        return new BoothController(
            $app ?? $door->app,
            $parties,
            $asking ?? new AssignmentBook($this->routed()),
            $mall ?? $door->mall,
            new BoothRoster(new BoothMeasures()),
            new BoothListFilter(),
            new BoothListStore($this->routed()),
            new BoothFields($door->app),
        );
    }

    private function letters(MallDoor $door, RoutedStatements $statements, LetterMailbox $mailbox): LetterController
    {
        return new LetterController(
            $door->app,
            new LetterDesk($statements, $door->pdo, new JobQueue(), $mailbox, new LetterDraft()),
            $door->mall,
        );
    }

    private function maps(MallDoor $door, RoutedStatements $statements, PartyStore $parties, ?MallApp $app = null, ?MallSettings $mall = null): MapController
    {
        return new MapController($app ?? $door->app, $parties, new MapStore($statements), $mall ?? $door->mall);
    }

    private function signIn(MallDoor $door, RoutedStatements $statements): VendorSignInController
    {
        $stays = new AssignmentBook($this->routed());
        $parties = new PartyStore($this->routed(), new JournalAudit($door->actor, new AuditLog($statements)), $stays);
        $roster = new VendorRoster(new VendorPlacement());
        $forms = new VendorForm($door->app, $door->mall, new SellerPaths($door->mall));
        $page = (new ReflectionClass(VendorPage::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass($page);
        foreach ([
            'app' => $door->app,
            'parties' => $parties,
            'stays' => $stays,
            'mall' => $door->mall,
            'roster' => $roster,
        ] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setValue($page, $value);
        }

        return new VendorSignInController(
            $door->app,
            new StaffStore($statements, new RecoveryAddress(), new PasswordChoice()),
            $page,
            new SellerPaths($door->mall),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function boothRow(): array
    {
        return ['id' => self::BOOTH, 'code' => 'A1', 'name' => 'Front', 'width_ft' => 10, 'depth_ft' => 10];
    }

    /**
     * @return array<string, mixed>
     */
    private function stayRow(string $ends): array
    {
        return [
            'booth_id' => self::BOOTH,
            'vendor_id' => MallDoor::VENDOR,
            'vendor_name' => 'Ada',
            'vendor_code' => 'A1',
            'starts_on' => '2026-01-01',
            'ends_on' => $ends,
        ];
    }

    private function draft(string $discount = '', string $keep = ''): VendorDraft
    {
        $fields = [
            'code' => 'A1',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'phone' => '',
            'email' => '',
            'address' => '',
            'city' => '',
            'state' => '',
            'postal' => '',
            'agreement_starts_on' => '',
            'agreement_ends_on' => '',
            'tax_id' => '',
            'notes' => '',
            'tax_exempt' => '',
            'tax_exempt_number' => '',
            'business_name' => '',
        ];
        if ($discount !== '') {
            $fields['discount_by'] = $discount;
        }
        if ($keep !== '') {
            $fields['keep_days'] = $keep;
        }

        return new VendorDraft($fields, null);
    }

    /**
     * @return array{app: MallApp, mall: MallSettings}
     */
    private function withRentals(MallDoor $door): array
    {
        $statements = $this->routed();
        $statements->rowRoutes[] = ['match' => 'booth_width_ft', 'rows' => [[
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => 10,
            'booth_depth_ft' => 10,
            'floor_width_ft' => 40,
            'floor_depth_ft' => 40,
            'commission_bps' => 1000,
            'floor_days' => null,
            'cash_round_minor' => 0,
            'tax_bps' => 0,
            'use_color' => false,
            'use_category' => false,
            'use_size' => false,
            'use_condition' => false,
            'use_department' => false,
            'use_brand' => false,
            'label_stock' => 'avery_8160',
            'booth_rentals_enabled' => true,
            'rent_cycle_day' => 1,
            'consignment_enabled' => false,
            'store_stock_enabled' => false,
            'receipt_footer' => '',
            'operating_mode' => 'both',
        ]]];
        $statements->rowRoutes[] = ['match' => 'SELECT time_zone FROM mall', 'rows' => [['time_zone' => 'America/Los_Angeles']]];
        $pdo = (new ReflectionProperty(MallApp::class, 'pdo'))->getValue($door->app);
        $view = (new ReflectionProperty(MallApp::class, 'view'))->getValue($door->app);
        $request = (new ReflectionProperty(MallApp::class, 'request'))->getValue($door->app);
        $access = (new ReflectionProperty(MallApp::class, 'access'))->getValue($door->app);
        $tenants = (new ReflectionProperty(MallApp::class, 'tenants'))->getValue($door->app);
        $front = (new ReflectionProperty(MallApp::class, 'door'))->getValue($door->app);
        if (!$pdo instanceof \PDO || !$view instanceof View || !$request instanceof Request || !$access instanceof \PerfectConsign\Shared\Auth\OwnerAccess || !$tenants instanceof \PerfectConsign\Bootstrap\TenantLookup || !$front instanceof \PerfectConsign\Bootstrap\FrontDoor) {
            self::fail('the mall app could not be rebuilt');
        }
        $mall = new MallSettings(
            $statements,
            new CompanyStore($statements, new DatabaseRole($pdo), $pdo, new CompanyLines()),
            new MallDay($statements),
        );
        $app = new MallApp(
            $pdo,
            $door->session,
            $view,
            $request,
            $access,
            $mall,
            new ActiveRegister($pdo, $door->session, $mall, new MallClock($mall)),
            $tenants,
            $front,
        );

        return ['app' => $app, 'mall' => $mall];
    }

    private function routed(): RoutedStatements
    {
        return new RoutedStatements($this->createStub(\PDO::class));
    }
}
