<?php declare(strict_types=1);
namespace App\Domain;

use App\SharedKernel\BaseAggregateRoot;
use Money\Currency;
use Money\Money;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class Account extends BaseAggregateRoot
{
    /** @var Money */
    private $balance;
    /** @var AccountState */
    private $state;

    public static function create(UuidInterface $id, string $currency) : Account
    {
        $self = new self();
        $self->recordThat(AccountWasCreated::from($id, $currency));

        return $self;
    }

    protected function whenAccountWasCreated(AccountWasCreated $event) : void
    {
        $this->id = Uuid::fromString($event->aggregateId());
        $this->balance = new Money(0, new Currency($event->currency()));
        $this->state = AccountState::ACTIVE();
    }
}
