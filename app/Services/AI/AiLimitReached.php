<?php

namespace App\Services\AI;

/** The company used up its AI requests for today (AI_DAILY_LIMIT_PER_COMPANY). Nothing was sent. */
class AiLimitReached extends AiUnavailable {}
