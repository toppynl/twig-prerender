<?php

declare(strict_types=1);

namespace Toppy\TwigPrerender\Tests\Unit\TokenParser;

use PHPUnit\Framework\TestCase;
use Toppy\AsyncViewModel\Context\ContextResolverInterface;
use Toppy\TwigPrerender\Node\DeferIncludeNode;
use Toppy\TwigPrerender\Node\PrerenderIncludeNode;
use Toppy\TwigPrerender\PrerenderExtension;
use Toppy\TwigPrerender\Service\ContextEncryptor;
use Toppy\TwigStreaming\Slot\SlotRegistry;
use Toppy\TwigStreaming\Slot\SlotRenderer;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Node\IncludeNode;
use Twig\Source;

/** Tests for IncludeTokenParser */
final class IncludeTokenParserTest extends TestCase
{
    private function createTwig(): Environment
    {
        // Use real instances for final classes
        $encryptor = new ContextEncryptor('test-secret-key-32-bytes-long!!');
        $contextResolver = $this->createStub(ContextResolverInterface::class);
        $slotRegistry = new SlotRegistry();
        $slotRenderer = new SlotRenderer();

        $twig = new Environment(new ArrayLoader([]), ['cache' => false]);
        $twig->addExtension(new PrerenderExtension($encryptor, $contextResolver, $slotRegistry, $slotRenderer));

        return $twig;
    }

    public function testStandardIncludeReturnsIncludeNode(): void
    {
        $twig = $this->createTwig();
        $source = "{% include 'template.twig' %}";

        $stream = $twig->tokenize(new Source($source, 'test'));
        $node = $twig->parse($stream)->getNode('body')->getNode('0');

        static::assertInstanceOf(IncludeNode::class, $node);
    }

    public function testPrerenderFalseReturnsPrerenderIncludeNode(): void
    {
        $twig = $this->createTwig();
        $source = "{% include 'template.twig' prerender(false) skeleton('skeleton.twig') %}";

        $stream = $twig->tokenize(new Source($source, 'test'));
        $node = $twig->parse($stream)->getNode('body')->getNode('0');

        static::assertInstanceOf(PrerenderIncludeNode::class, $node);
    }

    public function testDeferTrueReturnsDeferIncludeNode(): void
    {
        $twig = $this->createTwig();
        $source = "{% include 'template.twig' defer(true) skeleton('skeleton.twig') %}";

        $stream = $twig->tokenize(new Source($source, 'test'));
        $node = $twig->parse($stream)->getNode('body')->getNode('0');

        static::assertInstanceOf(DeferIncludeNode::class, $node);
    }

    public function testDeferWithFallbackTemplate(): void
    {
        $twig = $this->createTwig();
        $source = "{% include 'template.twig' defer(true) skeleton('s.twig') fallback('error.twig') %}";

        $stream = $twig->tokenize(new Source($source, 'test'));
        $node = $twig->parse($stream)->getNode('body')->getNode('0');

        static::assertInstanceOf(DeferIncludeNode::class, $node);
        static::assertTrue($node->hasNode('fallback'));
    }

    public function testDeferWithCustomId(): void
    {
        $twig = $this->createTwig();
        $source = "{% include 'template.twig' defer(true) skeleton('s.twig') id('my-custom-id') %}";

        $stream = $twig->tokenize(new Source($source, 'test'));
        $node = $twig->parse($stream)->getNode('body')->getNode('0');

        static::assertInstanceOf(DeferIncludeNode::class, $node);
        static::assertTrue($node->hasNode('customId'));
    }

    public function testDeferAndPrerenderAreMutuallyExclusive(): void
    {
        $twig = $this->createTwig();
        $source = "{% include 'template.twig' defer(true) prerender(false) skeleton('s.twig') %}";

        static::expectException(\Twig\Error\SyntaxError::class);
        static::expectExceptionMessage('defer(true) and prerender(false) cannot be used together');

        $stream = $twig->tokenize(new Source($source, 'test'));
        $twig->parse($stream);
    }
}
