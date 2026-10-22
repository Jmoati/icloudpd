<?php

namespace Foundation\Argparse\HelpFormatter;

use Foundation\Argparse;
use Foundation\Argparse\HelpFormatter;

class _Section
{
    public ?string $heading;
    /** @var list<array{callable, array}> */
    public array $items = [];

    public function __construct(public HelpFormatter $formatter, public ?_Section $parent, ?string $heading = null)
    {
        $this->heading = $heading;
    }

    public function format_help(): string
    {
        // format the indented section
        if ($this->parent !== null) {
            $this->formatter->_indent();
        }
        $item_help = $this->formatter->_join_parts(array_map(fn($item) => $item[0](...$item[1]), $this->items));
        if ($this->parent !== null) {
            $this->formatter->_dedent();
        }

        // return nothing if the section was empty
        if ($item_help === '') {
            return '';
        }

        // add the heading if the section was non-empty
        if ($this->heading !== Argparse::SUPPRESS && $this->heading !== null) {
            $current_indent = $this->formatter->_current_indent;
            $heading_text = "{$this->heading}:";
            $heading = str_repeat(' ', $current_indent) . $heading_text . "\n";
        } else {
            $heading = '';
        }

        // join the section-initial newline, the heading and the help
        return $this->formatter->_join_parts(["\n", $heading, $item_help, "\n"]);
    }
}
