<?php

namespace App\Payments;

use RuntimeException;

/**
 * A provider call failed in a way the user should hear about (message is translated, safe to show).
 */
class PaymentProviderException extends RuntimeException {}
