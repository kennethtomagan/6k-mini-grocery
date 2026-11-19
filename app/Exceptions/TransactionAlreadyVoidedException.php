<?php

namespace App\Exceptions;

use RuntimeException;

class TransactionAlreadyVoidedException extends RuntimeException
{
    public function __construct(?string $referenceNumber)
    {
        parent::__construct("Sale {$referenceNumber} has already been voided.");
    }
}
