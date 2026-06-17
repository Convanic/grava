<?php
declare(strict_types=1);

namespace App\Support;

use Ramsey\Uuid\Uuid as RUuid;

final class Uuid
{
    public static function v4(): string
    {
        return RUuid::uuid4()->toString();
    }
}
