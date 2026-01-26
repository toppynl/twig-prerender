<?php

declare(strict_types=1);

namespace Toppy\TwigPrerender\TokenParser;

use Toppy\TwigPrerender\Node\DeferIncludeNode;
use Toppy\TwigPrerender\Node\PrerenderIncludeNode;
use Twig\Error\SyntaxError;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\IncludeNode;
use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;
use Twig\TokenStream;

/**
 * Extends Twig's include to support prerender(false) and defer(true) syntax.
 *
 * Usage:
 *   {% include 'template.html.twig' %}
 *   {% include 'template.html.twig' prerender(false) skeleton('loading.html.twig') %}
 *   {% include 'template.html.twig' defer(true) skeleton('loading.html.twig') %}
 *   {% include 'template.html.twig' defer(true) skeleton('s.twig') fallback('error.twig') %}
 *   {% include 'template.html.twig' defer(true) skeleton('s.twig') id('custom-id') %}
 */
// @mago-ignore analysis:mixed-assignment,mixed-operand - Twig Node::getAttribute() returns mixed; vendor limitation
final class IncludeTokenParser extends AbstractTokenParser
{
    // @mago-ignore lint:sensitive-parameter - Token is a Twig lexer token, not a security token
    #[\Override]
    public function parse(Token $token): Node
    {
        $expr = $this->parser->parseExpression();
        $stream = $this->parser->getStream();

        // Parse custom modifiers: prerender(...), defer(...), skeleton(...), fallback(...), id(...)
        $prerender = true;
        $defer = false;
        $skeleton = null;
        $fallback = null;
        $customId = null;

        while ($this->isModifierFunction($stream)) {
            $funcExpr = $this->parser->parseExpression();

            if (!$funcExpr instanceof FunctionExpression) {
                continue;
            }

            $funcName = $funcExpr->getAttribute('name');
            $args = $funcExpr->getNode('arguments');

            // Get first argument from the arguments node
            $firstArg = $args->hasNode('0') ? $args->getNode('0') : null;

            if ($funcName === 'prerender' && $firstArg instanceof ConstantExpression) {
                $value = $firstArg->getAttribute('value');
                $prerender = (bool) $value;
            } elseif ($funcName === 'defer' && $firstArg instanceof ConstantExpression) {
                $value = $firstArg->getAttribute('value');
                $defer = (bool) $value;
            } elseif ($funcName === 'skeleton' && $firstArg instanceof AbstractExpression) {
                $skeleton = $firstArg;
            } elseif ($funcName === 'fallback' && $firstArg instanceof AbstractExpression) {
                $fallback = $firstArg;
            } elseif ($funcName === 'id' && $firstArg instanceof AbstractExpression) {
                $customId = $firstArg;
            }
        }

        // Validate mutual exclusivity
        if ($defer && !$prerender) {
            throw new SyntaxError(
                'defer(true) and prerender(false) cannot be used together',
                $token->getLine(),
                $stream->getSourceContext(),
            );
        }

        // Parse standard include arguments
        [$variables, $only, $ignoreMissing] = $this->parseStandardArguments();

        // defer(true): use DeferIncludeNode for streaming slots
        if ($defer) {
            return new DeferIncludeNode(
                $expr,
                $variables,
                $only,
                $ignoreMissing,
                $skeleton,
                $fallback,
                $customId,
                $token->getLine(),
            );
        }

        // prerender(false): use PrerenderIncludeNode for htmx
        if (!$prerender) {
            return new PrerenderIncludeNode($expr, $variables, $only, $ignoreMissing, $skeleton, $token->getLine());
        }

        // Default: standard IncludeNode
        return new IncludeNode($expr, $variables, $only, $ignoreMissing, $token->getLine());
    }

    private function isModifierFunction(TokenStream $stream): bool
    {
        if (!$stream->test(Token::NAME_TYPE)) {
            return false;
        }

        $name = $stream->getCurrent()->getValue();

        return is_string($name) && in_array($name, ['prerender', 'defer', 'skeleton', 'fallback', 'id'], strict: true);
    }

    /**
     * @return array{0: ?AbstractExpression, 1: bool, 2: bool}
     */
    private function parseStandardArguments(): array
    {
        $stream = $this->parser->getStream();

        $ignoreMissing = false;
        if ($stream->nextIf(Token::NAME_TYPE, 'ignore')) {
            $stream->expect(Token::NAME_TYPE, 'missing');
            $ignoreMissing = true;
        }

        $variables = null;
        if ($stream->nextIf(Token::NAME_TYPE, 'with')) {
            $variables = $this->parser->parseExpression();
        }

        $only = false;
        if ($stream->nextIf(Token::NAME_TYPE, 'only')) {
            $only = true;
        }

        $stream->expect(Token::BLOCK_END_TYPE);

        return [$variables, $only, $ignoreMissing];
    }

    #[\Override]
    public function getTag(): string
    {
        return 'include';
    }
}
