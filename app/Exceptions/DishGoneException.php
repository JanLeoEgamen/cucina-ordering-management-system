<?php

namespace App\Exceptions;

use RuntimeException;

// Thrown inside the import's transaction when a dish the file wants to update was deleted in the
// meantime (after the file was checked, before it was saved). Its own class on purpose: a database
// error (PDOException) is also a RuntimeException, and catching the parent would show SQL text.
class DishGoneException extends RuntimeException
{
}
