<?php declare(strict_types=1);
namespace App\Domain;

use Esky\Enum\Enum;

/**
 * @method static AccountState ACTIVE
 * @method static AccountState BLOCKED
 */
class AccountState extends Enum
{
    const ACTIVE = 1;
    const BLOCKED = 2;
}
