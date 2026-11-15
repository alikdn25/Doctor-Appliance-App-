<?php

namespace App\Services\AI\Assistant;

use RuntimeException;

/** A tool could not do what the AI asked (bad id, wrong date …); the message goes back to the AI. */
class AssistantError extends RuntimeException {}
