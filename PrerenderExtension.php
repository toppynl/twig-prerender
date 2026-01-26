<?php

declare(strict_types=1);

namespace Toppy\TwigPrerender;

use Toppy\AsyncViewModel\Context\ContextResolverInterface;
use Toppy\TwigPrerender\Service\ContextEncryptor;
use Toppy\TwigPrerender\TokenParser\IncludeTokenParser;
use Toppy\TwigStreaming\Slot\DeferredSlot;
use Toppy\TwigStreaming\Slot\SlotRegistryInterface;
use Toppy\TwigStreaming\Slot\SlotRenderer;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use function Amp\async;

/**
 * Provides prerender(false) and defer(true) support for {% include %} tags.
 */
final class PrerenderExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContextEncryptor $encryptor,
        private readonly ContextResolverInterface $contextResolver,
        private readonly ?SlotRegistryInterface $slotRegistry = null,
        private readonly ?SlotRenderer $slotRenderer = null,
    ) {}

    public function getTokenParsers(): array
    {
        return [
            new IncludeTokenParser(),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('prerender', fn(bool $value) => $value),
            new TwigFunction('defer', fn(bool $value) => $value),
            new TwigFunction('skeleton', fn(string $path) => $path),
            new TwigFunction('fallback', fn(string $value) => $value),
            new TwigFunction('id', fn(string $value) => $value),
        ];
    }

    /**
     * Called by PrerenderIncludeNode at runtime.
     */
    public function renderPrerenderPlaceholder(
        Environment $twig,
        string $template,
        ?string $skeleton,
        array $context,
    ): string {
        $requestContext = $this->contextResolver->getRequestContext();
        $encryptedCtx = $this->encryptor->encrypt($requestContext);
        $fragmentUrl = '/_fragment/' . $template . '?ctx=' . $encryptedCtx;

        $skeletonHtml = $this->resolveSkeleton($twig, $context, $template, $skeleton);

        return sprintf(
            '<div hx-get="%s" hx-trigger="load" hx-swap="outerHTML">%s</div>',
            htmlspecialchars($fragmentUrl, ENT_QUOTES),
            $skeletonHtml,
        );
    }

    /**
     * Called by DeferIncludeNode at runtime - renders slot placeholder.
     *
     * @param array<string, mixed> $context
     */
    public function renderDeferredPlaceholder(
        Environment $twig,
        string $template,
        ?string $skeleton,
        ?string $fallback,
        ?string $customId,
        array $context,
    ): string {
        if ($this->slotRegistry === null || $this->slotRenderer === null) {
            throw new \RuntimeException(
                'defer(true) requires the twig-streaming package. '
                . 'Install with: composer require toppy/twig-streaming '
                . 'or use prerender(false) for client-side lazy loading.'
            );
        }

        // Generate or use custom ID
        $requestContext = $this->contextResolver->getRequestContext();
        $slotId = $customId ?? DeferredSlot::generateId($template, $requestContext);

        // Determine if fallback is inline string or template path
        $isInlineFallback = $fallback !== null && !str_ends_with($fallback, '.twig');

        // Create slot value object
        $slot = new DeferredSlot(
            id: $slotId,
            template: $template,
            skeleton: $skeleton ?? '',
            fallback: $fallback,
            isInlineFallback: $isInlineFallback,
        );

        // Render skeleton content
        $skeletonHtml = $this->resolveSkeleton($twig, $context, $template, $skeleton);

        // Create Future that will render the actual template content when awaited
        // Using async() to defer the rendering until streamSlotFragments() awaits it
        $contentFuture = async(fn() => $twig->render($template, $context));

        // Register slot with its content Future
        $this->slotRegistry->register($slot, $contentFuture);

        return $this->slotRenderer->renderPlaceholder($slot, $skeletonHtml);
    }

    private function resolveSkeleton(Environment $twig, array $context, string $template, ?string $skeleton): string
    {
        // 1. Explicit skeleton parameter
        if ($skeleton !== null) {
            try {
                return $twig->render($skeleton, $context);
            } catch (LoaderError) {
                // Template not found, fall through
            }
        }

        // 2. Convention: template.skeleton.html.twig
        $conventionPath = str_replace('.html.twig', '.skeleton.html.twig', $template);
        try {
            return $twig->render($conventionPath, $context);
        } catch (LoaderError) {
            // Template not found, fall through
        }

        // 3. Default fallback
        try {
            return $twig->render('skeletons/default.html.twig', $context);
        } catch (LoaderError) {
            // Template not found, fall through
        }

        // 4. Hardcoded fallback
        return '<div class="skeleton"></div>';
    }
}
