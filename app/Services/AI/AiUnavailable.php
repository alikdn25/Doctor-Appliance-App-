<?php

namespace App\Services\AI;

use RuntimeException;

/** The AI could not answer: no key, network, an API error, a refusal or an answer that cannot be read. */
class AiUnavailable extends RuntimeException {}
