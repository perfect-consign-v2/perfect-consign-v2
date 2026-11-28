<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectApp\Routing\Route;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\Money;

/**
 * A vendor's booths: assigning one takes its first rent at the desk, ending
 * one sets its last day, and a reservation that has not started can be
 * cancelled. Only the owner reaches these routes (RoleGate). The database
 * decides every amount and date rule.
 */
final class AssignmentController
{
    public function __construct(
        private readonly MallApp $app,
        private readonly AssignmentOffer $offers,
        private readonly BoothStays $boothStays,
        private readonly BoothBooking $booking,
        private readonly AssignmentOutcome $outcome,
        private readonly ActiveRegister $register,
        private readonly MallSettings $mall,
    ) {
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/assignments', ['POST'])]
    public function addAssignment(string $slug): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $request = $this->app->request();
        $vendorId = $request->field('vendor_id');
        $today = $this->mall->today();
        $stay = [
            'booth' => $request->field('booth_id'),
            'vendor' => $vendorId,
            'starts_on' => $request->field('starts_on'),
            'ends_on' => $request->field('ends_on'),
            'rent' => null,
        ];
        try {
            $this->chosen($stay);
            $stay['rent'] = $this->offers->contract($stay['booth'], $request->field('rent'));
            $due = $this->booking->due($stay['booth'], $stay['starts_on'], $stay['ends_on'], $stay['rent'], $today);
            if ($due === null) {
                throw new DatabaseRefusal('Set the rent for this booth first.');
            }
            if ($request->field('pay_with') === '') {
                $this->outcome->finish($slug, $vendorId, '', null, $stay + $due + ['rent_typed' => $request->field('rent')]);

                return;
            }
            $this->booking->assign($stay, $this->payment($slug), $today);
        } catch (DatabaseRefusal $refusal) {
            $this->outcome->finish($slug, $vendorId, '', $this->app->catchRefusal($refusal));

            return;
        } catch (\InvalidArgumentException $error) {
            $this->outcome->finish($slug, $vendorId, '', $error->getMessage());

            return;
        }
        $this->outcome->finish($slug, $vendorId, 'Booth assigned.', null);
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/assignments/([0-9a-fA-F-]{36})/end', ['POST'])]
    public function endAssignment(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $vendorId = $this->outcome->vendorOf($id);
        $this->attempt($slug, $vendorId, 'Booth ended.', function () use ($id): void {
            $this->booking->end($id, $this->app->request()->field('ends_on'));
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/assignments/([0-9a-fA-F-]{36})/cancel', ['POST'])]
    public function cancelReservation(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $vendorId = $this->outcome->vendorOf($id);
        $request = $this->app->request();
        $outcome = $request->field('outcome');
        $this->attempt($slug, $vendorId, $outcome === 'keep' ? 'Reservation cancelled. The store kept the payment.' : 'Reservation cancelled and refunded.', function () use ($slug, $id, $outcome, $request): void {
            $this->booking->cancel(
                $id,
                $outcome,
                $outcome === 'refund' ? $this->payment($slug) : ['way' => '', 'check' => '', 'session' => ''],
                $request->field('reason'),
                $this->mall->today(),
            );
        });
    }

    #[Route('/([a-z][a-z0-9-]{0,62})/assignments/([0-9a-fA-F-]{36})/rent', ['POST'])]
    public function changeRent(string $slug, string $id): void
    {
        $this->app->checkCsrf();
        $this->app->open($slug, true);
        $vendorId = $this->outcome->vendorOf($id);
        $this->attempt($slug, $vendorId, 'Rent rate saved.', function () use ($id): void {
            $this->boothStays->setStayRent(
                $id,
                $this->app->request()->field('starts_on'),
                Money::parseMinor($this->app->request()->field('rent')),
            );
        });
    }

    /**
     * @param array{booth: string, vendor: string, starts_on: string, ends_on: string, rent: ?int} $stay
     */
    private function chosen(array $stay): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $stay['vendor']) !== 1) {
            throw new DatabaseRefusal('Choose a vendor.');
        }
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $stay['booth']) !== 1) {
            throw new DatabaseRefusal('Choose a booth.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $stay['starts_on']) !== 1) {
            throw new DatabaseRefusal('Choose the day the booth starts.');
        }
        if ($stay['ends_on'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $stay['ends_on']) !== 1) {
            throw new DatabaseRefusal('Choose the day the booth ends, or leave it empty.');
        }
    }

    /**
     * @return array{way: string, check: string, session: string}
     */
    private function payment(string $slug): array
    {
        $request = $this->app->request();

        return [
            'way' => $request->field('pay_with'),
            'check' => $request->field('check_number'),
            'session' => $this->register->current($slug, true),
        ];
    }

    private function attempt(string $slug, string $vendorId, string $notice, callable $action): void
    {
        $error = null;
        try {
            $action();
        } catch (DatabaseRefusal $refusal) {
            $error = $this->app->catchRefusal($refusal);
        } catch (\InvalidArgumentException $errorValue) {
            $error = $errorValue->getMessage();
        }
        $this->outcome->finish($slug, $vendorId, $notice, $error);
    }
}
