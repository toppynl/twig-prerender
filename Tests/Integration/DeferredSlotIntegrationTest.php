<?php

declare(strict_types=1);

namespace Toppy\TwigPrerender\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Toppy\AsyncViewModel\Context\ContextResolverInterface;
use Toppy\AsyncViewModel\Context\RequestContext;
use Toppy\AsyncViewModel\Context\ViewContext;
use Toppy\AsyncViewModel\ViewModelManagerInterface;
use Toppy\TwigPrerender\PrerenderExtension;
use Toppy\TwigPrerender\Service\ContextEncryptor;
use Toppy\TwigStreaming\Slot\SlotRegistry;
use Toppy\TwigStreaming\Slot\SlotRenderer;
use Toppy\TwigStreaming\Twig\StreamingTemplateRenderer;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/** Integration tests for defer(true) with SlotRegistry */
final class DeferredSlotIntegrationTest extends TestCase
{
    public function testDeferTrueRendersSlotPlaceholder(): void
    {
        $output = $this->renderTemplate("{% include 'component.twig' defer(true) skeleton('skeleton.twig') %}", [
            'component.twig' => '<div class="content">Real content</div>',
            'skeleton.twig' => '<div class="loading">Loading...</div>',
        ]);

        // Should contain slot placeholder with skeleton
        static::assertStringContainsString('id="slot_', $output);
        static::assertStringContainsString('<div class="loading">Loading...</div>', $output);
    }

    public function testDeferWithCustomId(): void
    {
        $output = $this->renderTemplate("{% include 'component.twig' defer(true) skeleton('skeleton.twig') id('my-reviews') %}", [
            'component.twig' => '<div>Content</div>',
            'skeleton.twig' => '<div>Loading</div>',
        ]);

        static::assertStringContainsString('id="my-reviews"', $output);
    }

    public function testDeferStreamsSlotFragment(): void
    {
        $output = $this->renderTemplate("{% include 'component.twig' defer(true) skeleton('skeleton.twig') %}", [
            'component.twig' => '<div class="real-content">Actual Content</div>',
            'skeleton.twig' => '<div class="skeleton">Loading...</div>',
        ]);

        // Should contain the slot placeholder with skeleton
        static::assertStringContainsString('id="slot_', $output);
        static::assertStringContainsString('<div class="skeleton">Loading...</div>', $output);

        // Should also contain the streamed fragment with actual content
        static::assertStringContainsString('<template id="tmpl_', $output);
        static::assertStringContainsString('<div class="real-content">Actual Content</div>', $output);

        // Should contain the reconciliation script
        static::assertStringContainsString('replaceChildren', $output);
        static::assertStringContainsString('?.remove()', $output);
    }

    /**
     * @param array<string, string> $additionalTemplates
     */
    private function renderTemplate(string $mainTemplate, array $additionalTemplates): string
    {
        $templates = array_merge(['main.twig' => $mainTemplate], $additionalTemplates);

        $loader = new ArrayLoader($templates);
        $twig = new Environment($loader, ['use_yield' => true, 'cache' => false]);

        $slotRegistry = new SlotRegistry();
        $slotRenderer = new SlotRenderer();

        $contextResolver = $this->createStub(ContextResolverInterface::class);
        $contextResolver->method('getViewContext')->willReturn(ViewContext::create('EUR', 'en', false, false, null));
        $contextResolver->method('getRequestContext')->willReturn(RequestContext::create([], 'test'));

        // Use real ContextEncryptor with a test secret key (32 bytes for AES-256)
        $encryptor = new ContextEncryptor(str_repeat('a', times: 32));

        $extension = new PrerenderExtension($encryptor, $contextResolver, $slotRegistry, $slotRenderer);
        $twig->addExtension($extension);

        $manager = $this->createStub(ViewModelManagerInterface::class);
        $renderer = new StreamingTemplateRenderer($twig, $manager, $slotRegistry, $slotRenderer);

        $response = $renderer->renderDirect('main.twig');

        // Capture output using a custom handler that accumulates chunks
        $output = '';
        ob_start(static function (string $buffer) use (&$output): string {
            $output .= $buffer;
            return '';
        });
        $response->sendContent();
        ob_end_clean();

        return $output;
    }
}
