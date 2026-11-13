<?php

namespace App\Exceptions;

use RuntimeException;

// Thrown inside the place-order transaction when a dish could not be reserved
// because someone else took the last plates first. It is its own class on
// purpose: a database error (PDOException) is also a RuntimeException, and
// catching the parent would show SQL text to the customer.
class StockRanOutException extends RuntimeException
{
}
