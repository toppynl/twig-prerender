<?php

declare(strict_types=1);

namespace Toppy\TwigPrerender\Node;

use Toppy\TwigPrerender\PrerenderExtension;
use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Node;
use Twig\Node\NodeOutputInterface;

/**
 * Compiles defer(true) includes to streaming slot placeholder output.
 */
#[YieldReady]
final class DeferIncludeNode extends Node implements NodeOutputInterface
{
    public function __construct(
        AbstractExpression $expr,
        ?AbstractExpression $variables,
        bool $only,
        bool $ignoreMissing,
        ?AbstractExpression $skeleton,
        ?AbstractExpression $fallback,
        ?AbstractExpression $customId,
        int $lineno,
    ) {
        $nodes = ['expr' => $expr];
        if ($variables !== null) {
            $nodes['variables'] = $variables;
        }
        if ($skeleton !== null) {
            $nodes['skeleton'] = $skeleton;
        }
        if ($fallback !== null) {
            $nodes['fallback'] = $fallback;
        }
        if ($customId !== null) {
            $nodes['customId'] = $customId;
        }

        parent::__construct(
            $nodes,
            [
                'only' => $only,
                'ignore_missing' => $ignoreMissing,
            ],
            $lineno,
        );
    }

    public function compile(Compiler $compiler): void
    {
        $compiler->addDebugInfo($this);

        $compiler
            ->write('yield $this->extensions[')
            ->repr(PrerenderExtension::class)
            ->raw(']->renderDeferredPlaceholder(')
            ->raw('$this->env, ');

        // Template path
        $compiler->subcompile($this->getNode('expr'));
        $compiler->raw(', ');

        // Skeleton path
        if ($this->hasNode('skeleton')) {
            $compiler->subcompile($this->getNode('skeleton'));
        } else {
            $compiler->raw('null');
        }
        $compiler->raw(', ');

        // Fallback (template path or inline string)
        if ($this->hasNode('fallback')) {
            $compiler->subcompile($this->getNode('fallback'));
        } else {
            $compiler->raw('null');
        }
        $compiler->raw(', ');

        // Custom ID
        if ($this->hasNode('customId')) {
            $compiler->subcompile($this->getNode('customId'));
        } else {
            $compiler->raw('null');
        }
        $compiler->raw(', ');

        // Context
        $this->compileContext($compiler);

        $compiler->raw(");\n");
    }

    private function compileContext(Compiler $compiler): void
    {
        if (!$this->hasNode('variables')) {
            $compiler->raw($this->getAttribute('only') ? '[]' : '$context');
        } elseif (!$this->getAttribute('only')) {
            $compiler
                ->raw('\Twig\Extension\CoreExtension::merge($context, ')
                ->subcompile($this->getNode('variables'))
                ->raw(')');
        } else {
            $compiler
                ->raw('\Twig\Extension\CoreExtension::toArray(')
                ->subcompile($this->getNode('variables'))
                ->raw(')');
        }
    }
}
