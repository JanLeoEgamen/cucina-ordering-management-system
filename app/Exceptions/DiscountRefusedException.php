<?php

namespace App\Exceptions;

use RuntimeException;

// The discount a customer picked cannot be used right now (switched off, expired, deleted, or its
// percent moved since the cart was drawn). A dedicated type, like StockRanOutException: the message
// is safe to show the customer, and nothing else is ever caught as this.
class DiscountRefusedException extends RuntimeException
{
}
