<?php declare(strict_types=1);
namespace App\Domain;

use Ramsey\Uuid\UuidInterface;

interface AccountRepository
{
    public function get(UuidInterface $id): Account;
    public function save(Account $account) : void;
}
