<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Base for business-rule rejections (rule 8/9 territory — insufficient
 * funds, insufficient holdings, and any future addition of the same
 * shape). Each subclass supplies a fixed, machine-readable error_code;
 * bootstrap/app.php registers exactly ONE render callback against this
 * base class, so a new subclass needs zero changes there — it's picked up
 * automatically the moment it extends this class and implements
 * errorCode().
 */
abstract class DomainRuleException extends RuntimeException
{
    abstract public function errorCode(): string;
}
