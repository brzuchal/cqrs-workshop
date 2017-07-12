<?php declare(strict_types=1);
namespace App\Command;

use Ramsey\Uuid\UuidInterface;

class CreateAccount
{
    /** @var UuidInterface */
    private $id;
    /** @var string */
    private $currency;

    public function __construct(UuidInterface $id, string $currency)
    {
        $this->id = $id;
        $this->currency = $currency;
    }

    public function id() : UuidInterface
    {
        return $this->id;
    }

    public function currency() : string
    {
        return $this->currency;
    }
}
