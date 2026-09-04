<?php

namespace App\Exceptions;

use App\Models\Client;
use Brick\Money\Money;

class InsufficientFundsException extends DomainRuleException
{
    public function __construct(
        public readonly Client $client,
        public readonly Money $requested,
        public readonly Money $available,
    ) {
        parent::__construct(
            "Insufficient funds for client {$client->id}: requested {$requested}, available {$available}."
        );
    }

    public function errorCode(): string
    {
        return 'insufficient_funds';
    }
}
