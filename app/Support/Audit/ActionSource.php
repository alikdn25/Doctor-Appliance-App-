<?php

namespace App\Support\Audit;

/**
 * Who is acting in this request or command, for the change log: a person in the app (web), the website booking
 * bot (bot), a customer page or a payment provider webhook (public), or a server command or queued job (console).
 */
class ActionSource
{
    private ?string $override = null;

    public function current(): string
    {
        if ($this->override !== null) {
            return $this->override;
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return 'console';
        }

        // Customer pages (estimate approval, payment) and provider webhooks act without a signed-in person.
        return auth()->check() ? 'web' : 'public';
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function as(string $source, callable $callback): mixed
    {
        $previous = $this->override;
        $this->override = $source;

        try {
            return $callback();
        } finally {
            $this->override = $previous;
        }
    }

    public function set(?string $source): void
    {
        $this->override = $source;
    }
}
