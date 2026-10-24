<?php

declare(strict_types=1);

namespace Quire\Content\Check\Internal;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use Quire\Content\Content;

/**
 * The Markdown body of an item as CommonMark reads it, for checks: so images and links inside code are
 * left alone, as they're shown as code.
 *
 * @internal
 */
final class Markdown
{
    private static ?MarkdownParser $parser = null;

    /**
     * @return Document|null Null when the body isn't Markdown
     */
    public static function document(Content $item): ?Document
    {
        if ('markdown' !== $item->bodyFormat) {
            return null;
        }

        self::$parser ??= new MarkdownParser(new Environment()->addExtension(new CommonMarkCoreExtension()));

        return self::$parser->parse($item->body);
    }

    /**
     * The line a node starts on: that of the block it's in.
     */
    public static function line(Node $node): ?int
    {
        for ($current = $node; $current instanceof Node; $current = $current->parent()) {
            if ($current instanceof AbstractBlock && null !== $current->getStartLine()) {
                return $current->getStartLine();
            }
        }

        return null;
    }

    /**
     * The text of a node and its children, e.g. an image's description.
     */
    public static function text(Node $node): string
    {
        $text = '';

        foreach ($node->iterator() as $child) {
            if ($child instanceof \League\CommonMark\Node\Inline\Text || $child instanceof \League\CommonMark\Extension\CommonMark\Node\Inline\Code) {
                $text .= $child->getLiteral();
            }
        }

        return trim($text);
    }
}
