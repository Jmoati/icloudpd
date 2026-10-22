<?php

namespace Foundation;

use Foundation\ElementTree\Element;
use Foundation\ElementTree\ParseError;

/**
 * The part of Python's `xml.etree.ElementTree` that icloudpd uses: `SubElement`, `tostring` and `parse`, with the
 * same output text (no indentation, ` />` for an empty element, `&#09;` for a tab in an attribute...).
 */
final class ElementTree
{
    /** `ElementTree.SubElement(parent, tag, attrib)` */
    public static function SubElement(Element $parent, string $tag, array $attrib = []): Element
    {
        $element = new Element($tag, $attrib);
        $parent->append($element);

        return $element;
    }

    /**
     * `ElementTree.tostring(element, encoding, xml_declaration=...)`
     *
     * ponytail: only the `utf-8` encoding (what icloudpd asks for); `us-ascii` needs `&#NNN;` references and
     * `unicode` returns a str, add them when something asks.
     */
    public static function tostring(Element $element, string $encoding = 'us-ascii', ?bool $xml_declaration = null): string
    {
        if (strtolower($encoding) !== 'utf-8') {
            throw new \ValueError("unsupported encoding $encoding");
        }
        $out = '';
        if ($xml_declaration) {
            $out .= "<?xml version='1.0' encoding='$encoding'?>\n";
        }
        self::_serialize_xml($out, $element);

        return $out;
    }

    private static function _serialize_xml(string &$write, Element $elem): void
    {
        $tag = $elem->tag;
        $text = $elem->text;
        $write .= '<'.$tag;
        foreach ($elem->attrib as $k => $v) {
            $write .= ' '.$k.'="'.self::_escape_attrib($v).'"';
        }
        if (($text !== null && $text !== '') || count($elem)) {
            $write .= '>';
            if ($text !== null && $text !== '') {
                $write .= self::_escape_cdata($text);
            }
            foreach ($elem as $e) {
                self::_serialize_xml($write, $e);
            }
            $write .= '</'.$tag.'>';
        } else {
            $write .= ' />';
        }
    }

    private static function _escape_cdata(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    private static function _escape_attrib(string $text): string
    {
        return str_replace(['&', '<', '>', '"', "\r", "\n", "\t"], ['&amp;', '&lt;', '&gt;', '&quot;', '&#13;', '&#10;', '&#09;'], $text);
    }

    /**
     * `ElementTree.parse(source).getroot()`: the root element, with its attributes named `{uri}name` when
     * they are in a namespace.
     *
     * ponytail: only the root element is built (that is all icloudpd reads), the rest of the document is only
     * checked for well-formedness; the wording and the column of a ParseError come from ext/xml, not from expat.
     */
    public static function parse(string $source): Element
    {
        $data = file_get_contents($source);
        if ($data === false) {
            throw new OSError("Cannot read $source");
        }
        $parser = xml_parser_create_ns(null, '|');
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
        $root = null;
        xml_set_element_handler(
            $parser,
            function (\XMLParser $parser, string $name, array $attrs) use (&$root): void {
                if ($root !== null) {
                    return;
                }
                $attrib = [];
                foreach ($attrs as $k => $v) {
                    $attrib[preg_replace('/^(.*)\|/', '{$1}', $k)] = $v;
                }
                $root = new Element(preg_replace('/^(.*)\|/', '{$1}', $name), $attrib);
            },
            fn () => null
        );
        if (!xml_parse($parser, $data, true)) {
            throw new ParseError(sprintf(
                '%s: line %d, column %d',
                lcfirst(xml_error_string(xml_get_error_code($parser))),
                xml_get_current_line_number($parser),
                xml_get_current_column_number($parser)
            ));
        }

        return $root;
    }
}
