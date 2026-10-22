<?php

namespace Foundation;

/**
 * Test seam replacing the `mock.patch` of the upstream Python tests (see PORTING.md).
 *
 * A patchable function starts with `if ($p = Patch::get(__METHOD__)) return $p(...);`.
 * Patches are keyed by `Class::method` (what `__METHOD__` gives) and receive the same
 * arguments as the function (`$this` first for an instance method).
 */
final class Patch
{
    /** @var array<string, callable> */
    private static array $patches = [];

    public static function get(string $method): ?callable
    {
        return self::$patches[$method] ?? null;
    }

    public static function set(string $method, callable $replacement): void
    {
        self::$patches[$method] = $replacement;
    }

    public static function clear(?string $method = null): void
    {
        if ($method === null) {
            self::$patches = [];
        } else {
            unset(self::$patches[$method]);
        }
    }
}
