<?php declare(strict_types=1);
namespace App\SharedKernel;

use Prooph\EventSourcing\AggregateChanged;
use Prooph\EventSourcing\AggregateRoot;
use Ramsey\Uuid\Uuid;

class BaseAggregateRoot extends AggregateRoot
{
    /** @var Uuid */
    protected $id;

    protected function aggregateId() : string
    {
        return $this->id->toString();
    }

    protected function apply(AggregateChanged $event) : void
    {
        $methodName = 'when' . \str_replace(
                (new \ReflectionClass(static::class))->getNamespaceName() . '\\',
                '',
                $event->messageName()
            );
        $this->$methodName($event);
    }
}
