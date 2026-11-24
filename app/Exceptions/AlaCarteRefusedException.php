<?php

namespace App\Exceptions;

use RuntimeException;

// A plate "without rice" cannot be sold right now (the switch is off, the dish no longer comes with
// rice, the rice price moved since the cart was drawn, or the customer asked for more plates without
// rice than they ordered). A dedicated type, like DiscountRefusedException: the message is safe to
// show the customer, and nothing else is ever caught as this.
class AlaCarteRefusedException extends RuntimeException
{
}
