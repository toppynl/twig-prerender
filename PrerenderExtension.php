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

    #[\Override]
    public function getTokenParsers(): array
    {
        return [
            new IncludeTokenParser(),
        ];
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('prerender', static fn(bool $value) => $value),
            new TwigFunction('defer', static fn(bool $value) => $value),
            new TwigFunction('skeleton', static fn(string $path) => $path),
            new TwigFunction('fallback', static fn(string $value) => $value),
            new TwigFunction('id', static fn(string $value) => $value),
        ];
    }

    /**
     * Called by PrerenderIncludeNode at runtime.
     *
     * @param array<string, mixed> $context
     *
     * @throws \Random\RandomException When random_bytes fails to generate IV
     * @throws \JsonException When context cannot be JSON encoded
     * @throws \RuntimeException When OpenSSL encryption fails
     * @throws \Twig\Error\SyntaxError When skeleton template has syntax errors
     * @throws \Twig\Error\RuntimeError When skeleton template has runtime errors
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
     *
     * @throws \RuntimeException When twig-streaming package is not installed
     * @throws \Twig\Error\SyntaxError When skeleton template has syntax errors
     * @throws \Twig\Error\RuntimeError When skeleton template has runtime errors
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
                . 'or use prerender(false) for client-side lazy loading.',
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
        $contentFuture = async(static fn() => $twig->render($template, $context));

        // Register slot with its content Future
        $this->slotRegistry->register($slot, $contentFuture);

        return $this->slotRenderer->renderPlaceholder($slot, $skeletonHtml);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @throws \Twig\Error\SyntaxError When a skeleton template has syntax errors
     * @throws \Twig\Error\RuntimeError When a skeleton template has runtime errors
     */
    private function resolveSkeleton(Environment $twig, array $context, string $template, ?string $skeleton): string
    {
        // 1. Explicit skeleton parameter
        if ($skeleton !== null) {
            try {
                return $twig->render($skeleton, $context);

                // @mago-ignore lint:no-empty-catch-clause - Explicit skeleton not found, fallthrough to convention
            } catch (LoaderError) {
            }
        }

        // 2. Convention: template.skeleton.html.twig
        $conventionPath = str_replace(search: '.html.twig', replace: '.skeleton.html.twig', subject: $template);
        try {
            return $twig->render($conventionPath, $context);

            // @mago-ignore lint:no-empty-catch-clause - Convention skeleton not found, fallthrough to default
        } catch (LoaderError) {
        }

        // 3. Default fallback
        try {
            return $twig->render('skeletons/default.html.twig', $context);

            // @mago-ignore lint:no-empty-catch-clause - Default skeleton not found, use hardcoded fallback
        } catch (LoaderError) {
        }

        // 4. Hardcoded fallback
        return '<div class="skeleton"></div>';
    }
}
