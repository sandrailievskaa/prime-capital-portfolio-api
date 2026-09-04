<?php

namespace App\Exceptions;

use App\Models\Client;
use App\Models\Instrument;

class InsufficientHoldingsException extends DomainRuleException
{
    public function __construct(
        public readonly Client $client,
        public readonly Instrument $instrument,
        public readonly int $requestedQuantity,
        public readonly int $heldQuantity,
    ) {
        parent::__construct(
            "Insufficient holdings for client {$client->id} in instrument {$instrument->ticker}: ".
            "requested {$requestedQuantity}, held {$heldQuantity}."
        );
    }

    public function errorCode(): string
    {
        return 'insufficient_holdings';
    }
}
