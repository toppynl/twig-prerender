<?php

declare(strict_types=1);

namespace Toppy\TwigPrerender\Bundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Toppy\TwigPrerender\PrerenderExtension;
use Toppy\TwigPrerender\Service\ContextEncryptor;
use Toppy\TwigStreaming\Slot\SlotRegistryInterface;
use Toppy\TwigStreaming\Slot\SlotRenderer;

class ToppyTwigPrerenderExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        // Register ContextEncryptor with kernel.secret (zero config!)
        $container->setDefinition(ContextEncryptor::class, new Definition(ContextEncryptor::class))
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$secretKey', '%kernel.secret%');

        // Register PrerenderExtension with twig.extension tag and slot dependencies
        $container->setDefinition(PrerenderExtension::class, new Definition(PrerenderExtension::class))
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$slotRegistry', new Reference(SlotRegistryInterface::class))
            ->setArgument('$slotRenderer', new Reference(SlotRenderer::class))
            ->addTag('twig.extension');
    }
}
