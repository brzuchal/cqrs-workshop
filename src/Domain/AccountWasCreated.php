<?php declare(strict_types=1);
namespace App\Domain;

use Prooph\EventSourcing\AggregateChanged;
use Ramsey\Uuid\UuidInterface;

class AccountWasCreated extends AggregateChanged
{
    public static function from(UuidInterface $id, string $currency)
    {
        return self::occur($id->toString(), [
            'currency' => $currency,
        ]);
    }

    public function currency() : string
    {
        return $this->payload['currency'];
    }
}
