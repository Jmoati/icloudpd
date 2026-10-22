<?php

namespace Icloudpd\Counter;

/**
 * Atomic counter
 *
 * ponytail: a plain int, no lock and no shared memory (single thread, see PORTING.md); a class so that
 * it is shared by reference like upstream's object.
 */
class Counter
{
    public int $initial_value;
    public int $val;

    public function __construct(int $value = 0)
    {
        $this->initial_value = $value;
        $this->val = $value;
    }

    public function increment(): void
    {
        $this->val += 1;
    }

    public function reset(): void
    {
        $this->val = $this->initial_value;
    }

    public function value(): int
    {
        return $this->val;
    }
}
