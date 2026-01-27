# Twig Prerender

> **Read-Only Repository**
> This is a read-only subtree split from the main repository.
> Please submit issues and pull requests to [toppynl/symfony-astro](https://github.com/toppynl/symfony-astro).

Extends Twig's `{% include %}` tag with `prerender(false)` and `defer(true)` modifiers for progressive rendering patterns. This package enables both client-side lazy loading via htmx and server-side streaming via deferred slots, allowing templates to render immediate skeleton placeholders while content loads asynchronously.

## Installation

```bash
composer require toppy/twig-prerender
```

## Requirements

- PHP 8.4 or higher
- `amphp/amp` ^3.0
- `toppy/twig-streaming` (required for `defer(true)` server-side streaming)

## Quick Start

```twig
{# Client-side lazy loading - renders skeleton, htmx fetches content #}
{% include 'components/reviews.html.twig' prerender(false) skeleton('skeletons/reviews.html.twig') %}

{# Server-side streaming - renders skeleton, streams content when ready #}
{% include 'components/stock.html.twig' defer(true) skeleton('skeletons/stock.html.twig') %}
```

## Architecture

### Key Classes

| Class | Purpose |
|-------|---------|
| `PrerenderExtension` | Twig extension providing modifier functions and runtime rendering methods |
| `IncludeTokenParser` | Parses extended `{% include %}` syntax with modifiers |
| `PrerenderIncludeNode` | Compiles `prerender(false)` to htmx placeholder output |
| `DeferIncludeNode` | Compiles `defer(true)` to streaming slot placeholder output |
| `ContextEncryptor` | Encrypts `RequestContext` for secure URL transport (AES-256-GCM) |

### Include Modifiers

The `IncludeTokenParser` extends Twig's standard `{% include %}` tag to support additional modifiers:

| Modifier | Type | Description |
|----------|------|-------------|
| `prerender(false)` | bool | Skip server-side rendering; client loads via htmx |
| `defer(true)` | bool | Render skeleton immediately; stream content via slots |
| `skeleton('path.twig')` | string | Template path for loading placeholder |
| `fallback('error.twig')` | string | Template or inline text for error state (defer only) |
| `id('custom-id')` | string | Custom DOM element ID (defer only) |

Standard Twig include arguments (`with`, `only`, `ignore missing`) remain fully supported:

```twig
{% include 'component.twig' defer(true) skeleton('loading.twig') with {id: item.id} only %}
```

## Usage

### prerender(false) Modifier

Use `prerender(false)` when you want the client to fetch content after initial page load. This outputs an htmx-powered placeholder that automatically fetches the rendered template via a fragment endpoint.

```twig
{% include 'components/reviews.html.twig'
   prerender(false)
   skeleton('skeletons/reviews.html.twig')
   with {productId: product.id} %}
```

**Generated output:**

```html
<div hx-get="/_fragment/components/reviews.html.twig?ctx=<encrypted>"
     hx-trigger="load"
     hx-swap="outerHTML">
    <!-- skeleton content rendered here -->
</div>
```

The `ctx` parameter contains an encrypted `RequestContext` to preserve request state during the fragment fetch, preventing tampering via AES-256-GCM authenticated encryption.

**When to use:**
- Non-critical content that can load after initial paint
- Content requiring additional API calls that would block TTFB
- User-specific content on cached pages (ESI-like pattern)
- Components with slow data dependencies

### defer(true) Modifier

Use `defer(true)` for server-side out-of-order streaming. The skeleton renders immediately in the response stream, then the actual content is pushed as a reconciliation fragment when ready.

```twig
{% include 'components/stock-status.html.twig'
   defer(true)
   skeleton('skeletons/stock.html.twig')
   id('stock-widget') %}
```

**Generated output (initial flush):**

```html
<div id="stock-widget">
    <!-- skeleton content -->
</div>
```

**Streamed later when data resolves:**

```html
<template id="tmpl_stock-widget">
    <!-- actual content -->
</template>
<script id="script_stock-widget">
(function(){
    var t=document.getElementById('tmpl_stock-widget'),
        s=document.getElementById('stock-widget');
    if(t&&s)s.replaceChildren(...t.content.cloneNode(true).childNodes);
    t?.remove();
    document.getElementById('script_stock-widget')?.remove();
})();
</script>
```

**When to use:**
- Critical content that must be in the initial response (SEO)
- Components with async data that can resolve in parallel
- Sub-100ms TTFB optimization with FrankenPHP streaming
- Real-time data that benefits from immediate shell rendering

### defer(true) with Fallback

Provide a fallback template or inline text for error handling:

```twig
{# Template fallback #}
{% include 'components/stock.html.twig'
   defer(true)
   skeleton('skeletons/stock.html.twig')
   fallback('errors/stock-unavailable.html.twig') %}

{# Inline fallback (detected by missing .twig extension) #}
{% include 'components/stock.html.twig'
   defer(true)
   skeleton('skeletons/stock.html.twig')
   fallback('Unable to load stock information') %}
```

### Custom Slot IDs

By default, slot IDs are generated deterministically from the template path and request context. Use `id()` to specify a custom ID for JavaScript targeting:

```twig
{% include 'components/cart-count.html.twig'
   defer(true)
   skeleton('skeletons/cart.html.twig')
   id('cart-badge') %}
```

### Combining with Skeletons

The skeleton resolution follows a fallback chain:

1. **Explicit skeleton parameter:** `skeleton('skeletons/custom.html.twig')`
2. **Convention-based:** `template.skeleton.html.twig` alongside `template.html.twig`
3. **Default fallback:** `skeletons/default.html.twig`
4. **Hardcoded fallback:** `<div class="skeleton"></div>`

**Skeleton template example:**

```twig
{# skeletons/product-card.html.twig #}
<div class="product-card skeleton">
    <div class="skeleton-image"></div>
    <div class="skeleton-text skeleton-title"></div>
    <div class="skeleton-text skeleton-price"></div>
</div>
```

**Convention-based skeleton:**

```
templates/
  components/
    product-card.html.twig           # Main component
    product-card.skeleton.html.twig  # Auto-discovered skeleton
```

### Mutual Exclusivity

`prerender(false)` and `defer(true)` cannot be used together as they represent different loading strategies:

```twig
{# ERROR: SyntaxError thrown #}
{% include 'component.twig' defer(true) prerender(false) %}
```

### Progressive Enhancement with htmx

For `prerender(false)`, ensure htmx is loaded in your base template:

```html
<script src="https://unpkg.com/htmx.org@2.0.0"></script>
```

The fragment endpoint (`/_fragment/...`) must be implemented to:
1. Decrypt the `ctx` parameter using `ContextEncryptor`
2. Restore the `RequestContext`
3. Render and return the template fragment

## Integration

### Dependency on twig-streaming

The `defer(true)` modifier requires the `twig-streaming` package for slot management:

```bash
composer require toppy/twig-streaming
```

Without it, using `defer(true)` throws a `RuntimeException` with installation instructions.

### Extension Registration

Register the extension with required dependencies:

```php
use Toppy\TwigPrerender\PrerenderExtension;
use Toppy\TwigPrerender\Service\ContextEncryptor;
use Toppy\TwigStreaming\Slot\SlotRegistry;
use Toppy\TwigStreaming\Slot\SlotRenderer;

$encryptor = new ContextEncryptor($secretKey); // 32-byte key for AES-256
$contextResolver = /* your ContextResolverInterface implementation */;
$slotRegistry = new SlotRegistry();
$slotRenderer = new SlotRenderer();

$twig->addExtension(new PrerenderExtension(
    $encryptor,
    $contextResolver,
    $slotRegistry,  // null if not using defer(true)
    $slotRenderer,  // null if not using defer(true)
));
```

### Symfony Integration

When using with `toppy/symfony-async-twig-bundle`, the extension is auto-configured via dependency injection. See the bundle documentation for configuration options.

## Testing

Run the test suite:

```bash
cd src/Toppy/Component/TwigPrerender
./vendor/bin/phpunit
```

Or from the monorepo root:

```bash
make demo-shell
cd /app/src/Toppy/Component/TwigPrerender && ./vendor/bin/phpunit
```

## License

Proprietary - see LICENSE file for details.
