<?php declare(strict_types=1);
namespace App\Infrastructure;

use App\Domain\Account;
use Prooph\EventSourcing\Aggregate\AggregateRepository;
use Ramsey\Uuid\UuidInterface;

class AccountRepository implements \App\Domain\AccountRepository
{
    /**
     * @var AggregateRepository
     */
    private $aggregateRepository;

    public function __construct(AggregateRepository $aggregateRepository)
    {
        $this->aggregateRepository = $aggregateRepository;
    }

    public function get(UuidInterface $id): Account
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $this->aggregateRepository->getAggregateRoot($id->toString());
    }

    public function save(Account $account): void
    {
        $this->aggregateRepository->saveAggregateRoot($account);
    }
}
