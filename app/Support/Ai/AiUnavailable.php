<?php

namespace App\Support\Ai;

use RuntimeException;

/** The AI provider could not answer; callers fall back to the basic parser. */
class AiUnavailable extends RuntimeException {}
