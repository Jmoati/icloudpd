<?php

namespace Foundation\Http;

/**
 * A case-insensitive ``dict``-like object (requests.structures.CaseInsensitiveDict).
 *
 * Implements all methods and operations of ``MutableMapping`` as well as dict's ``copy``.
 * Also provides ``lower_items``.
 *
 * All keys are expected to be strings. The structure remembers the case of the last key to be set,
 * and ``items()``/iteration will contain case-sensitive keys. However, querying and contains
 * testing is case insensitive:
 *
 *     $cid = new CaseInsensitiveDict();
 *     $cid['Accept'] = 'application/json';
 *     $cid['aCCEPT'] === 'application/json'; // true
 *     array_keys($cid->items()) === ['Accept']; // true
 *
 * A missing key raises \OutOfBoundsException (Python: KeyError) with ``[]``, ``get()`` returns the default.
 *
 * @implements \IteratorAggregate<string, mixed>
 */
final class CaseInsensitiveDict implements \ArrayAccess, \IteratorAggregate, \Countable
{
    /** @var array<string, array{0: string, 1: mixed}> lower-cased key => [original key, value] */
    private array $_store = [];

    public function __construct(?iterable $data = null, mixed ...$kwargs)
    {
        $this->update($data ?? [], ...$kwargs);
    }

    public function update(iterable $other = [], mixed ...$kwargs): void
    {
        foreach ($other as $key => $value) {
            $this[$key] = $value;
        }
        foreach ($kwargs as $key => $value) {
            $this[$key] = $value;
        }
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        // Use the lowercased key for lookups, but store the actual key alongside the value.
        $key = (string) $key;
        $this->_store[strtolower($key)] = [$key, $value];
    }

    public function offsetGet(mixed $key): mixed
    {
        $lower = strtolower((string) $key);
        if (!isset($this->_store[$lower])) {
            throw new \OutOfBoundsException((string) $key);
        }

        return $this->_store[$lower][1];
    }

    public function offsetUnset(mixed $key): void
    {
        unset($this->_store[strtolower((string) $key)]);
    }

    public function offsetExists(mixed $key): bool
    {
        return isset($this->_store[strtolower((string) $key)]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->_store[strtolower($key)][1] ?? $default;
    }

    public function pop(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        unset($this[$key]);

        return $value;
    }

    public function setdefault(string $key, mixed $default = null): mixed
    {
        if (!isset($this[$key])) {
            $this[$key] = $default;
        }

        return $this[$key];
    }

    /** @return \Generator<string, mixed> original-cased key => value */
    public function getIterator(): \Generator
    {
        foreach ($this->_store as [$key, $value]) {
            yield $key => $value;
        }
    }

    public function count(): int
    {
        return count($this->_store);
    }

    /**
     * Like ``dict.items()``: original-cased key => value.
     *
     * @return array<string, mixed>
     */
    public function items(): array
    {
        $items = [];
        foreach ($this->_store as [$key, $value]) {
            $items[$key] = $value;
        }

        return $items;
    }

    /**
     * Like iteritems(), but with all lowercase keys.
     *
     * @return array<string, mixed>
     */
    public function lower_items(): array
    {
        $items = [];
        foreach ($this->_store as $lower => [, $value]) {
            $items[$lower] = $value;
        }

        return $items;
    }

    public function copy(): self
    {
        return new self($this->items());
    }

    public function __toString(): string
    {
        return json_encode($this->items(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
