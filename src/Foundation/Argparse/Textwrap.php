<?php

namespace Foundation\Argparse;

/**
 * Text wrapping and filling (Python `textwrap`, as used by argparse's HelpFormatter).
 *
 * ponytail: `wrap` and `fill` with the default TextWrapper options only (break_long_words,
 * break_on_hyphens and drop_whitespace on; no max_lines, placeholder, fix_sentence_endings,
 * no tab expansion: argparse has already collapsed the whitespace of its text).
 * Lengths are in characters like Python's len().
 */
final class Textwrap
{
    // Hardcode the recognized whitespace characters to the US-ASCII
    // whitespace characters.  The main reason for doing this is that
    // some Unicode spaces (like  ) are non-breaking whitespaces.
    private const _whitespace = " \t\n\x0b\x0c\r";

    // This funky little regex is just the trick for splitting
    // text up into word-wrappable chunks.  E.g.
    //   "Hello there -- you goof-ball, use the -b option!"
    // splits into
    //   Hello/ /there/ /--/ /you/ /goof-/ball,/ /use/ /the/ /-b/ /option!
    // (after stripping out empty strings).
    private const wordsep_re = '/(
          [\t\n\x0b\x0c\r\x20]+
        |
          (?<=[\w!"\'&.,?]) -{2,} (?=\w)
        |
          [^\t\n\x0b\x0c\r\x20]+? (?:
              -(?: (?<=[^\d\W]{2}-) | (?<=[^\d\W]-[^\d\W]-))
              (?= [^\d\W] -? [^\d\W])
            |
              (?=[\t\n\x0b\x0c\r\x20]|\z)
            |
              (?<=[\w!"\'&.,?]) (?=-{2,}\w)
            )
        )/xu';

    /**
     * Reformat the single paragraph in 'text' so it fits in lines of
     * no more than 'width' columns, and return a list of wrapped
     * lines.  All whitespace characters (including newline) are
     * converted to space.
     *
     * @return list<string>
     */
    public static function wrap(string $text, int $width = 70, string $initial_indent = '', string $subsequent_indent = ''): array
    {
        $chunks = self::_split_chunks($text);
        return self::_wrap_chunks($chunks, $width, $initial_indent, $subsequent_indent);
    }

    /**
     * Reformat the single paragraph in 'text' to fit in lines of no
     * more than 'width' columns, and return a new string
     * containing the entire wrapped paragraph.
     */
    public static function fill(string $text, int $width = 70, string $initial_indent = '', string $subsequent_indent = ''): string
    {
        return implode("\n", self::wrap($text, $width, $initial_indent, $subsequent_indent));
    }

    /** @return list<string> */
    private static function _split_chunks(string $text): array
    {
        // _munge_whitespace: convert all whitespace characters to spaces
        $text = strtr($text, ["\t" => ' ', "\n" => ' ', "\x0b" => ' ', "\x0c" => ' ', "\r" => ' ']);
        // _split
        $chunks = preg_split(self::wordsep_re, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        return $chunks === false ? [$text] : $chunks;
    }

    private static function _is_blank(string $chunk): bool
    {
        return trim($chunk, self::_whitespace) === '';
    }

    /**
     * Handle a chunk of text (most likely a word, not whitespace) that
     * is too long to fit in any line.
     *
     * @param list<string> $reversed_chunks
     * @param list<string> $cur_line
     */
    private static function _handle_long_word(array &$reversed_chunks, array &$cur_line, int $cur_len, int $width): void
    {
        // Figure out when indent is larger than the specified width, and make
        // sure at least one character is stripped off on every pass
        if ($width < 1) {
            $space_left = 1;
        } else {
            $space_left = $width - $cur_len;
        }

        // If we're allowed to break long words, then do so: put as much
        // of the next chunk onto the current line as will fit.
        if ($space_left > 0) {
            $end = $space_left;
            $chunk = $reversed_chunks[count($reversed_chunks) - 1];
            if (mb_strlen($chunk) > $space_left) {
                // break after last hyphen, but only if there are
                // non-hyphens before it
                $hyphen = mb_strrpos(mb_substr($chunk, 0, $space_left), '-');
                if ($hyphen !== false && $hyphen > 0 && trim(mb_substr($chunk, 0, $hyphen), '-') !== '') {
                    $end = $hyphen + 1;
                }
            }
            $cur_line[] = mb_substr($chunk, 0, $end);
            $reversed_chunks[count($reversed_chunks) - 1] = mb_substr($chunk, $end);
        }

        // Otherwise, we have to preserve the long word intact.  Only add
        // it to the current line if there's nothing already there --
        // that minimizes how much we violate the width constraint.
        elseif (!$cur_line) {
            $cur_line[] = array_pop($reversed_chunks);
        }
    }

    /**
     * Wrap a sequence of text chunks and return a list of lines of
     * length 'width' or less.
     *
     * @param list<string> $chunks
     * @return list<string>
     */
    private static function _wrap_chunks(array $chunks, int $width_, string $initial_indent, string $subsequent_indent): array
    {
        $lines = [];
        if ($width_ <= 0) {
            throw new \ValueError("invalid width {$width_} (must be > 0)");
        }

        // Arrange in reverse order so items can be efficiently popped
        // from a stack of chucks.
        $chunks = array_reverse($chunks);

        while ($chunks) {
            // Start the list of chunks that will make up the current line.
            // cur_len is just the length of all the chunks in cur_line.
            $cur_line = [];
            $cur_len = 0;

            // Figure out which static string will prefix this line.
            if ($lines) {
                $indent = $subsequent_indent;
            } else {
                $indent = $initial_indent;
            }

            // Maximum width for this line.
            $width = $width_ - mb_strlen($indent);

            // First chunk on line is whitespace -- drop it, unless this
            // is the very beginning of the text (ie. no lines started yet).
            if (self::_is_blank($chunks[count($chunks) - 1]) && $lines) {
                array_pop($chunks);
            }

            while ($chunks) {
                $l = mb_strlen($chunks[count($chunks) - 1]);

                // Can at least squeeze this chunk onto the current line.
                if ($cur_len + $l <= $width) {
                    $cur_line[] = array_pop($chunks);
                    $cur_len += $l;
                }

                // Nope, this line is full.
                else {
                    break;
                }
            }

            // The current line is full, and the next chunk is too big to
            // fit on *any* line (not just this one).
            if ($chunks && mb_strlen($chunks[count($chunks) - 1]) > $width) {
                self::_handle_long_word($chunks, $cur_line, $cur_len, $width);
                $cur_len = array_sum(array_map('mb_strlen', $cur_line));
            }

            // If the last chunk on this line is all whitespace, drop it.
            if ($cur_line && self::_is_blank($cur_line[count($cur_line) - 1])) {
                $cur_len -= mb_strlen($cur_line[count($cur_line) - 1]);
                array_pop($cur_line);
            }

            if ($cur_line) {
                // Convert current line back to a string and store it in
                // list of all lines (return value).
                $lines[] = $indent . implode('', $cur_line);
            }
        }

        return $lines;
    }
}
