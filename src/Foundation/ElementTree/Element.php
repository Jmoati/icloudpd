<?php

namespace Foundation\ElementTree;

/**
 * `xml.etree.ElementTree.Element`: a tag, its attributes (insertion order is the output order), a text and
 * its children (`len(elem)` is `count($elem)`, `for e in elem` is `foreach ($elem as $e)`).
 *
 * ponytail: no `tail`, no comments or processing instructions, no `{uri}tag` qualified names (the tags and
 * attributes are plain strings, `x:tag` is just a name).
 *
 * @implements \IteratorAggregate<int, Element>
 */
final class Element implements \Countable, \IteratorAggregate
{
    public ?string $text = null;
    /** @var list<Element> */
    private array $_children = [];

    /** @param array<string, string> $attrib */
    public function __construct(public string $tag, public array $attrib = [])
    {
    }

    public function append(Element $subelement): void
    {
        $this->_children[] = $subelement;
    }

    public function count(): int
    {
        return count($this->_children);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->_children);
    }
}
