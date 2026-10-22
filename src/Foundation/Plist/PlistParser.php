<?php

namespace Foundation\Plist;

/**
 * Read an XML plist file (the `_PlistParser` of plistlib, on PHP's expat binding).
 *
 * ponytail: XML entity declarations are not rejected (plistlib refuses them to protect expat, ext/xml
 * resolves no external entity), and a syntax error is an InvalidFileException instead of an ExpatError.
 */
final class PlistParser
{
    /**
     * PHP arrays are values: an open container lives in its frame and is added to its parent when it ends
     * (Python appends to the shared list/dict at once). `key` is the key under which it goes in a dict parent.
     *
     * @var list<array{kind: string, value: array, key: ?string}>
     */
    private array $stack = [];
    private ?string $current_key = null;
    private mixed $root = null;
    /** @var list<string> */
    private array $data = [];
    private ?\XMLParser $parser = null;

    public function parse(string $fileobj): mixed
    {
        $this->parser = xml_parser_create();
        xml_parser_set_option($this->parser, XML_OPTION_CASE_FOLDING, false);
        xml_set_element_handler($this->parser, $this->handle_begin_element(...), $this->handle_end_element(...));
        xml_set_character_data_handler($this->parser, $this->handle_data(...));
        if (!xml_parse($this->parser, $fileobj, true)) {
            throw new InvalidFileException(
                sprintf('%s: line %d, column %d', xml_error_string(xml_get_error_code($this->parser)),
                    xml_get_current_line_number($this->parser), xml_get_current_column_number($this->parser))
            );
        }

        return $this->root;
    }

    private function handle_begin_element(\XMLParser $parser, string $element, array $attrs): void
    {
        $this->data = [];
        $handler = 'begin_'.$element;
        if (in_array($handler, ['begin_dict', 'begin_array'], true)) {
            $this->$handler($attrs);
        }
    }

    private function handle_end_element(\XMLParser $parser, string $element): void
    {
        $handler = 'end_'.$element;
        if (in_array($handler, ['end_dict', 'end_key', 'end_array', 'end_true', 'end_false', 'end_integer',
            'end_real', 'end_string', 'end_data', 'end_date'], true)) {
            $this->$handler();
        }
    }

    private function handle_data(\XMLParser $parser, string $data): void
    {
        $this->data[] = $data;
    }

    private function top_kind(): ?string
    {
        return $this->stack ? $this->stack[count($this->stack) - 1]['kind'] : null;
    }

    private function add_object(mixed $value): void
    {
        if ($this->current_key !== null) {
            if ($this->top_kind() !== 'dict') {
                throw new \ValueError(sprintf('unexpected element at line %d', xml_get_current_line_number($this->parser)));
            }
            $this->stack[count($this->stack) - 1]['value'][$this->current_key] = $value;
            $this->current_key = null;
        } elseif (!$this->stack) {
            // this is the root object
            $this->root = $value;
        } else {
            if ($this->top_kind() !== 'list') {
                throw new \ValueError(sprintf('unexpected element at line %d', xml_get_current_line_number($this->parser)));
            }
            $this->stack[count($this->stack) - 1]['value'][] = $value;
        }
    }

    private function begin_container(string $kind): void
    {
        $this->stack[] = ['kind' => $kind, 'value' => [], 'key' => $this->current_key];
        $this->current_key = null;
    }

    private function end_container(): void
    {
        $frame = array_pop($this->stack);
        $this->current_key = $frame['key'];
        $this->add_object($frame['value']);
    }

    private function get_data(): string
    {
        $data = implode('', $this->data);
        $this->data = [];

        return $data;
    }

    // element handlers

    private function begin_dict(array $attrs): void
    {
        $this->begin_container('dict');
    }

    private function end_dict(): void
    {
        if ($this->current_key !== null && $this->current_key !== '') {
            throw new \ValueError(sprintf("missing value for key '%s' at line %d", $this->current_key,
                xml_get_current_line_number($this->parser)));
        }
        $this->end_container();
    }

    private function end_key(): void
    {
        if (($this->current_key !== null && $this->current_key !== '') || $this->top_kind() !== 'dict') {
            throw new \ValueError(sprintf('unexpected key at line %d', xml_get_current_line_number($this->parser)));
        }
        $this->current_key = $this->get_data();
    }

    private function begin_array(array $attrs): void
    {
        $this->begin_container('list');
    }

    private function end_array(): void
    {
        $this->end_container();
    }

    private function end_true(): void
    {
        $this->add_object(true);
    }

    private function end_false(): void
    {
        $this->add_object(false);
    }

    private function end_integer(): void
    {
        $raw = $this->get_data();
        $t = trim($raw);
        if (str_starts_with($raw, '0x') || str_starts_with($raw, '0X')) {
            $v = gmp_init(substr($t, 2), 16);
        } elseif (preg_match('/^[+-]?\d+$/', $t)) {
            $v = gmp_init(ltrim($t, '+'), 10);
        } else {
            throw new \ValueError(sprintf("invalid literal for int() with base 10: '%s'", $raw));
        }
        $this->add_object(gmp_cmp($v, PHP_INT_MIN) >= 0 && gmp_cmp($v, PHP_INT_MAX) <= 0 ? gmp_intval($v) : $v);
    }

    private function end_real(): void
    {
        $raw = trim($this->get_data());
        $lower = strtolower($raw);
        if (in_array(ltrim($lower, '+-'), ['inf', 'infinity'], true)) {
            $v = $lower[0] === '-' ? -INF : INF;
        } elseif (ltrim($lower, '+-') === 'nan') {
            $v = NAN;
        } elseif (is_numeric($raw)) {
            $v = (float) $raw;
        } else {
            throw new \ValueError(sprintf("could not convert string to float: '%s'", $raw));
        }
        $this->add_object($v);
    }

    private function end_string(): void
    {
        $this->add_object($this->get_data());
    }

    private function end_data(): void
    {
        $this->add_object(base64_decode($this->get_data()));
    }

    private function end_date(): void
    {
        $this->add_object(self::_date_from_string($this->get_data()));
    }

    /** plistlib._date_from_string: naive datetime, kept as UTC wall-clock */
    private static function _date_from_string(string $s): \DateTimeImmutable
    {
        $dateParser = '/(?P<year>\d\d\d\d)(?:-(?P<month>\d\d)(?:-(?P<day>\d\d)(?:T(?P<hour>\d\d)(?::(?P<minute>\d\d)(?::(?P<second>\d\d))?)?)?)?)?Z/A';
        if (!preg_match($dateParser, $s, $gd)) {
            throw new InvalidFileException();
        }
        $lst = [];
        foreach (['year', 'month', 'day', 'hour', 'minute', 'second'] as $key) {
            if (!isset($gd[$key]) || $gd[$key] === '') {
                break;
            }
            $lst[] = (int) $gd[$key];
        }
        // datetime(year, month, day, ...): month and day are required, hour... default to 0
        if (count($lst) < 3 || !checkdate($lst[1], $lst[2], $lst[0]) || $lst[0] < 1
            || ($lst[3] ?? 0) > 23 || ($lst[4] ?? 0) > 59 || ($lst[5] ?? 0) > 59) {
            throw new \ValueError('date value out of range');
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setDate($lst[0], $lst[1], $lst[2])->setTime($lst[3] ?? 0, $lst[4] ?? 0, $lst[5] ?? 0);
    }
}
