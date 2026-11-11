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

    /** This line, flagged `negative_net` when its net is below 0, for every type: the net is shown as computed, never clipped. */
    public function withNegativeNetFlag(): self
    {
        return bccomp($this->components[Component::Net->value], '0', 6) < 0
            ? new self($this->components, $this->days, [...$this->flags, 'negative_net'])
            : $this;
    }
}
