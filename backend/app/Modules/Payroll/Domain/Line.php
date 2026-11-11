<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/** One employee's computed payroll line for a period: components (before any override), the days, and the flags. */
final readonly class Line
{
    /**
     * @param  array<string, numeric-string>  $components  Component value → computed amount
     * @param  list<DayLine>  $days
     * @param  list<string>  $flags
     */
    public function __construct(
        public array $components,
        public array $days,
        public array $flags,
    ) {}

    /**
     * An engine's line: each amount rounded to 6 decimals, flagged with each day flag's name (the part before `:`) once, in
     * first-seen order, and `negative_net` when the net is below 0.
     *
     * @param  array<string, numeric-string>  $components  Component value → unrounded amount
     * @param  list<DayLine>  $days
     */
    public static function computed(array $components, array $days): self
    {
        $flags = array_map(fn (string $f) => explode(':', $f)[0], array_merge(...array_column($days, 'flags')));

        return (new self(array_map(Money::round6(...), $components), $days, array_values(array_unique($flags))))->withNegativeNetFlag();
    }

    /**
     * This line flagged `negative_net` exactly when its net is below 0, for every type: the net is shown as computed, never
     * clipped. A stale flag is dropped, so the flag can be checked again after the net changes (an override).
     */
    public function withNegativeNetFlag(): self
    {
        $flags = array_values(array_diff($this->flags, ['negative_net']));
        if (bccomp($this->components[Component::Net->value], '0', 6) < 0) {
            $flags[] = 'negative_net';
        }

        return new self($this->components, $this->days, $flags);
    }
}
