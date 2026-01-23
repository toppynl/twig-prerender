<?php

declare(strict_types=1);

namespace Toppy\TwigPrerender\Bundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Bundle for automatic service registration of twig-prerender package.
 *
 * Dependency on ToppyTwigViewModelBundle is enforced via composer require
 * (not runtime checks, since bundles aren't resolved during build()).
 */
class ToppyTwigPrerenderBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
