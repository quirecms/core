<?php

declare(strict_types=1);

namespace Quire\Formatter\Internal;

use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;
use Quire\Formatter\FormatContext;

/**
 * Finds shortcodes in Markdown. As an inline parser, it skips code spans and code blocks, so
 * `` `[[gallery]]` `` shows the shortcode instead of running it.
 *
 * @internal
 */
final class ShortcodeInlineParser implements InlineParserInterface
{
    private ?FormatContext $context = null;

    /**
     * The context of the text being converted. The parser belongs to an environment that's reused.
     */
    public function using(?FormatContext $context): void
    {
        $this->context = $context;
    }

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex(ShortcodeSyntax::PATTERN);
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $source = $inlineContext->getFullMatch();
        $placeholder = $this->context?->placeholder(ShortcodeSyntax::parse($source));

        if (null === $placeholder) {
            return false;
        }

        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
        $inlineContext->getContainer()->appendChild(new Text($placeholder));

        return true;
    }
}
