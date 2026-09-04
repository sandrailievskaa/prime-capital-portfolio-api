<?php

namespace App\Exceptions;

use RuntimeException;

/** bootstrap/app.php renders every subclass via one callback keyed off this base class. */
abstract class DomainRuleException extends RuntimeException
{
    abstract public function errorCode(): string;
}
