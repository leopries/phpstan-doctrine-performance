<?php declare(strict_types=1);

namespace Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\GuardedState;

use Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Customer;
use Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Membership;
use Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\MembershipState;
use Leopries\PHPStanDoctrinePerformance\Tests\Fixtures\Region;

final class History
{
    public Region $community;
    public Customer $user;
}

final class MembershipRepository
{
    /** @var list<History> */
    public array $histories = [];

    public function forceInvitation(Membership $membership): void
    {
        $membership
            ->setMembershipState(MembershipState::Invited)
            ->setInvitedAt(new \DateTimeImmutable());

        $this->addHistory($membership);
    }

    public function forceActivation(Membership $membership): void
    {
        $membership->setMembershipState(MembershipState::Active);
        $this->addHistory($membership);
    }

    public function keepState(Membership $membership): void
    {
        $this->addHistory($membership);
    }

    public function maybeActivate(Membership $membership, bool $activate): void
    {
        $membership->setMembershipState(MembershipState::Invited);
        if ($activate) {
            $membership->setMembershipState(MembershipState::Active);
        }
        $this->addHistory($membership);
    }

    public function declineByProperty(Membership $membership): void
    {
        $membership->state = MembershipState::Declined;
        $this->compareHistory($membership);
    }

    public function leave(Membership $membership): void
    {
        $membership->state = MembershipState::Left;
        $this->compareHistory($membership);
    }

    public function inviteThenChange(Membership $membership): void
    {
        $membership->setMembershipState(MembershipState::Invited);
        $this->changeAfterGuard($membership);
    }

    private function compareHistory(Membership $membership): void
    {
        if (\in_array($membership->getMembershipState(), [MembershipState::Invited, MembershipState::Declined], true)) {
            return;
        }
        if ($membership->state !== MembershipState::Left) {
            $this->histories[] = $this->history($membership->getCommunity(), $membership->getUser());
        }
    }

    private function changeAfterGuard(Membership $membership): void
    {
        if (MembershipState::Invited === $membership->getMembershipState()) {
            $membership->setMembershipState(MembershipState::Active);
        }
        if ($membership->getMembershipState() !== MembershipState::Active) {
            return;
        }
        $this->histories[] = $this->history($membership->getCommunity(), $membership->getUser());
    }

    private function history(Region $community, Customer $user): History
    {
        $history = new History();
        $history->community = $community;
        $history->user = $user;

        return $history;
    }

    private function addHistory(Membership $membership): void
    {
        $shouldPersistHistory = match ($membership->getMembershipState()) {
            MembershipState::Active,
            MembershipState::Left => true,
            MembershipState::Invited,
            MembershipState::Declined => false,
        };

        if (!$shouldPersistHistory) {
            return;
        }

        $history = new History();
        $history->community = $membership->getCommunity();
        $history->user = $membership->getUser();
        $this->histories[] = $history;
    }
}

final class SomeService
{
    public function __construct(private MembershipRepository $memberships)
    {
    }

    /** @param list<Membership> $memberships */
    public function processMany(array $memberships): void
    {
        foreach ($memberships as $membership) {
            $this->memberships->forceInvitation($membership);
        }
    }

    /** @param list<Membership> $memberships */
    public function variants(array $memberships, bool $activate): void
    {
        foreach ($this->load() as $membership) {
            $this->memberships->forceActivation($membership);
        }
        foreach ($this->load() as $membership) {
            $this->memberships->keepState($membership);
        }
        foreach ($this->load() as $membership) {
            $this->memberships->maybeActivate($membership, $activate);
        }
        foreach ($this->load() as $membership) {
            $this->memberships->declineByProperty($membership);
        }
        foreach ($this->load() as $membership) {
            $this->memberships->leave($membership);
        }
        foreach ($this->load() as $membership) {
            $this->memberships->inviteThenChange($membership);
        }
    }

    /** @param list<Membership> $memberships */
    public function directReads(array $memberships): void
    {
        foreach ($this->load() as $membership) {
            $membership->setMembershipState(MembershipState::Invited);
            if ($membership->getMembershipState() === MembershipState::Active) {
                $membership->getCommunity()->name;
            }
        }
        foreach ($this->load() as $membership) {
            $membership->setMembershipState(MembershipState::Active);
            if ($membership->getMembershipState() === MembershipState::Active) {
                $membership->getCommunity()->name;
            }
        }
        foreach ($this->load() as $membership) {
            $membership->setMembershipState(MembershipState::Invited);
            if ($membership->getMembershipState() !== MembershipState::Active) {
                continue;
            }
            $membership->getUser()->name;
        }
    }

    /** @return list<Membership> */
    private function load(): array
    {
        return [];
    }
}
