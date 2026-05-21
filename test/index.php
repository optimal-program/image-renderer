<?php declare(strict_types=1);

/**
 * Functional smoke-test runner for optimal/image-renderer.
 *
 * Run from the project root or this directory:
 *   php test/index.php
 * or open in a browser:
 *   php -S localhost:8000 -t test
 *   http://localhost:8000/index.php
 *
 * Add sample files to test/images/ before running:
 *   - any bitmap (jpg/jpeg/png/webp/gif), at least one
 *   - any svg
 *   - a "no-image" bitmap (filename containing "no-image" or "noimage")
 *   - a "no-image" svg (filename containing "no-image" or "noimage")
 */

require __DIR__ . '/../vendor/autoload.php';

use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Bridges\ApplicationLatte\TemplateFactory;
use Optimal\ImageRenderer\BitmapImageRenderer;
use Optimal\ImageRenderer\VectorImageRenderer;
use Optimal\FileManaging\Utils\ImageResolutionSettings;
use Optimal\FileManaging\Utils\ImageResolutionsSettings;

const TEST_BASE = __DIR__;
$cli = PHP_SAPI === 'cli';

// ---------------------------------------------------------------------------
// Directories
// ---------------------------------------------------------------------------
$dirs = [
    'images'         => TEST_BASE . '/images',
    'temp'           => TEST_BASE . '/temp',
    'latteCache'     => TEST_BASE . '/temp/latte',
    'imagesCache'    => TEST_BASE . '/temp/cache/images',
    'variants'       => TEST_BASE . '/output/variants',
];
foreach ($dirs as $name => $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "Cannot create directory: $dir\n");
        exit(1);
    }
}

// BitmapImageRenderer hard-codes __DIR__ . '/../../../../temp/cache/images' relative to
// src/, which only resolves nicely when installed under vendor/optimal/image-renderer.
// Pre-create whatever it resolves to here so its constructor does not blow up.
$bitmapHardcoded = dirname(__DIR__) . '/src/../../../../temp/cache/images';
if (!is_dir($bitmapHardcoded) && !@mkdir($bitmapHardcoded, 0777, true) && !is_dir($bitmapHardcoded)) {
    fwrite(STDERR, "Cannot create BitmapImageRenderer cache directory: $bitmapHardcoded\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Mini test harness
// ---------------------------------------------------------------------------
$results = [];

function check(string $name, callable $fn): void
{
    global $results;
    $start = microtime(true);
    try {
        $detail = $fn();
        $results[] = [
            'name' => $name,
            'status' => 'pass',
            'detail' => is_string($detail) ? $detail : '',
            'time' => microtime(true) - $start,
        ];
    } catch (\Throwable $e) {
        $results[] = [
            'name' => $name,
            'status' => 'fail',
            'detail' => get_class($e) . ': ' . $e->getMessage()
                . "\n  at " . $e->getFile() . ':' . $e->getLine(),
            'time' => microtime(true) - $start,
        ];
    }
}

function skip(string $name, string $reason): void
{
    global $results;
    $results[] = ['name' => $name, 'status' => 'skip', 'detail' => $reason, 'time' => 0.0];
}

function assertTrue(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) {
        throw new \RuntimeException($msg);
    }
}

function assertContains(string $haystack, string $needle, string $context = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException(
            ($context !== '' ? "$context: " : '')
            . "expected output to contain '$needle', got: "
            . substr($haystack, 0, 200)
        );
    }
}

// ---------------------------------------------------------------------------
// Template factory wiring
// ---------------------------------------------------------------------------
$latteFactory = new class($dirs['latteCache']) implements LatteFactory {
    public function __construct(private string $tempDir) {}
    public function create(): \Latte\Engine
    {
        $engine = new \Latte\Engine();
        $engine->setTempDirectory($this->tempDir);
        $engine->setAutoRefresh(true);
        return $engine;
    }
};
$templateFactory = new TemplateFactory($latteFactory);

// Provide $basePath to every template created by the factory.
// The library's latte templates (imgtag.latte) reference $basePath.
$decoratingFactory = new class($templateFactory) extends TemplateFactory {
    public function __construct(private TemplateFactory $inner) {}
    public function createTemplate(?\Nette\Application\UI\Control $control = null, ?string $class = null): \Nette\Application\UI\Template
    {
        $tpl = $this->inner->createTemplate($control, $class);
        $tpl->basePath = '';
        return $tpl;
    }
};

// ---------------------------------------------------------------------------
// Discover test images
// ---------------------------------------------------------------------------
function findImage(string $dir, array $exts, ?string $nameContains = null): ?string
{
    $files = glob($dir . '/*') ?: [];
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $base = strtolower(pathinfo($file, PATHINFO_FILENAME));
        if (!in_array($ext, $exts, true)) {
            continue;
        }
        if ($nameContains !== null && !str_contains($base, $nameContains)) {
            continue;
        }
        if ($nameContains === null && (str_contains($base, 'no-image') || str_contains($base, 'noimage'))) {
            continue;
        }
        return $file;
    }
    return null;
}

$bitmapExts  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
$bitmapImage = findImage($dirs['images'], $bitmapExts);
$svgImage    = findImage($dirs['images'], ['svg']);
$noImgBitmap = findImage($dirs['images'], $bitmapExts, 'no-image')
            ?? findImage($dirs['images'], $bitmapExts, 'noimage');
$noImgSvg    = findImage($dirs['images'], ['svg'], 'no-image')
            ?? findImage($dirs['images'], ['svg'], 'noimage');

// ---------------------------------------------------------------------------
// VectorImageRenderer tests
// ---------------------------------------------------------------------------
check('VectorImageRenderer: instantiation', function () use ($decoratingFactory) {
    $r = new VectorImageRenderer($decoratingFactory);
    $r->setTemplateFactory($decoratingFactory);
    assertTrue($r instanceof VectorImageRenderer);
});

if ($noImgSvg !== null) {
    check('VectorImageRenderer: setNoImagePath accepts SVG', function () use ($decoratingFactory, $noImgSvg) {
        $r = new VectorImageRenderer($decoratingFactory);
        $r->setTemplateFactory($decoratingFactory);
        $r->setNoImagePath($noImgSvg);
        return $noImgSvg;
    });

    check('VectorImageRenderer: setNoImagePath rejects bitmap', function () use ($decoratingFactory, $bitmapImage, $noImgSvg) {
        if ($bitmapImage === null) {
            throw new \RuntimeException('skip: no bitmap to try');
        }
        $r = new VectorImageRenderer($decoratingFactory);
        $r->setTemplateFactory($decoratingFactory);
        try {
            $r->setNoImagePath($bitmapImage);
        } catch (\RuntimeException $e) {
            return 'rejected as expected: ' . $e->getMessage();
        }
        throw new \RuntimeException('expected RuntimeException for bitmap noImage');
    });
} else {
    skip('VectorImageRenderer: setNoImagePath accepts SVG', 'no SVG with "no-image" in name found in test/images/');
}

if ($svgImage !== null) {
    check('VectorImageRenderer: renderAsString returns HTML', function () use ($decoratingFactory, $svgImage, $noImgSvg) {
        $r = new VectorImageRenderer($decoratingFactory);
        $r->setTemplateFactory($decoratingFactory);
        if ($noImgSvg !== null) {
            $r->setNoImagePath($noImgSvg);
        }
        $html = $r->renderAsString($svgImage, 'sample svg', ['class' => 'foo']);
        assertContains($html, '<img', 'renderAsString');
        assertContains($html, basename($svgImage), 'renderAsString src');
        return substr($html, 0, 120) . '…';
    });

    check('VectorImageRenderer: renderInlineAsString returns inlined SVG', function () use ($decoratingFactory, $svgImage) {
        $r = new VectorImageRenderer($decoratingFactory);
        $r->setTemplateFactory($decoratingFactory);
        $html = $r->renderInlineAsString($svgImage);
        assertContains(strtolower($html), '<svg', 'renderInlineAsString');
        return substr($html, 0, 120) . '…';
    });
} else {
    skip('VectorImageRenderer: renderAsString returns HTML', 'no SVG in test/images/');
    skip('VectorImageRenderer: renderInlineAsString returns inlined SVG', 'no SVG in test/images/');
}

check('VectorImageRenderer: checkImage throws when no image and no fallback', function () use ($decoratingFactory) {
    $r = new VectorImageRenderer($decoratingFactory);
    $r->setTemplateFactory($decoratingFactory);
    try {
        $r->renderAsString(null, 'alt');
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
    throw new \RuntimeException('expected RuntimeException');
});

// ---------------------------------------------------------------------------
// BitmapImageRenderer tests
// ---------------------------------------------------------------------------
check('BitmapImageRenderer: instantiation', function () use ($decoratingFactory) {
    $r = new BitmapImageRenderer($decoratingFactory);
    $r->setTemplateFactory($decoratingFactory);
    assertTrue($r instanceof BitmapImageRenderer);
});

check('BitmapImageRenderer: checkWebPSupport / isWebPSupported', function () use ($decoratingFactory) {
    BitmapImageRenderer::checkWebPSupport();
    $r = new BitmapImageRenderer($decoratingFactory);
    $r->setTemplateFactory($decoratingFactory);
    // Result depends on HTTP_ACCEPT; just verify it's a bool.
    assertTrue(is_bool($r->isWebPSupported()));
    return 'webp-supported=' . ($r->isWebPSupported() ? 'yes' : 'no');
});

check('BitmapImageRenderer: setters configure renderer', function () use ($decoratingFactory, $dirs) {
    $r = new BitmapImageRenderer($decoratingFactory);
    $r->setTemplateFactory($decoratingFactory);
    $r->setImagesVariantsCacheDirectory($dirs['variants']);
    $r->setDefaultLazyLoad(true);
    $r->setDefaultSizes('(max-width: 600px) 100vw, 600px');
    $r->setDefaultThumbSizes('(max-width: 200px) 100vw, 200px');
    $r->setCreateVariantsBottomLimit(100);
    assertTrue($r->getDefaultSizes() === '(max-width: 600px) 100vw, 600px');
    assertTrue($r->getDefaultThumbSizes() === '(max-width: 200px) 100vw, 200px');
});

if ($noImgBitmap !== null) {
    check('BitmapImageRenderer: setNoImagePath accepts bitmap', function () use ($decoratingFactory, $noImgBitmap) {
        $r = new BitmapImageRenderer($decoratingFactory);
        $r->setTemplateFactory($decoratingFactory);
        $r->setNoImagePath($noImgBitmap);
        return $noImgBitmap;
    });

    check('BitmapImageRenderer: setNoImagePath rejects SVG', function () use ($decoratingFactory, $svgImage) {
        if ($svgImage === null) {
            throw new \RuntimeException('skip: no SVG to try');
        }
        $r = new BitmapImageRenderer($decoratingFactory);
        $r->setTemplateFactory($decoratingFactory);
        try {
            $r->setNoImagePath($svgImage);
        } catch (\RuntimeException $e) {
            return 'rejected as expected';
        }
        throw new \RuntimeException('expected RuntimeException for svg noImage');
    });
} else {
    skip('BitmapImageRenderer: setNoImagePath accepts bitmap', 'no bitmap with "no-image" in name found in test/images/');
}

if ($bitmapImage !== null) {
    check('BitmapImageRenderer: createImageVariants', function () use ($decoratingFactory, $dirs, $bitmapImage, $noImgBitmap) {
        $r = new BitmapImageRenderer($decoratingFactory);
        $r->setTemplateFactory($decoratingFactory);
        $r->setImagesVariantsCacheDirectory($dirs['variants']);

        $resolutions = new ImageResolutionsSettings();
        $resolutions->addResolutionSettingsByObject(new ImageResolutionSettings(320));
        $resolutions->addResolutionSettingsByObject(new ImageResolutionSettings(640));
        $r->setImageVariantsResolutions($resolutions);
        $r->setCreateVariantsBottomLimit(50);

        if ($noImgBitmap !== null) {
            $r->setNoImagePath($noImgBitmap);
        }

        $variants = $r->createImageVariants($bitmapImage, true);
        return 'created ' . count($variants) . ' variant(s)';
    });

    check('BitmapImageRenderer: getImageSrcSet returns string', function () use ($decoratingFactory, $dirs, $bitmapImage, $noImgBitmap) {
        $r = new BitmapImageRenderer($decoratingFactory);
        $r->setTemplateFactory($decoratingFactory);
        $r->setImagesVariantsCacheDirectory($dirs['variants']);

        $resolutions = new ImageResolutionsSettings();
        $resolutions->addResolutionSettingsByObject(new ImageResolutionSettings(320));
        $resolutions->addResolutionSettingsByObject(new ImageResolutionSettings(640));
        $r->setImageVariantsResolutions($resolutions);
        $r->setCreateVariantsBottomLimit(50);

        if ($noImgBitmap !== null) {
            $r->setNoImagePath($noImgBitmap);
        }

        $out = $r->getImageSrcSet($bitmapImage);
        return strlen($out) > 0 ? substr($out, 0, 120) . '…' : '(empty)';
    });

    check('BitmapImageRenderer: getImage returns <img> markup', function () use ($decoratingFactory, $dirs, $bitmapImage, $noImgBitmap) {
        $r = new BitmapImageRenderer($decoratingFactory);
        $r->setTemplateFactory($decoratingFactory);
        $r->setImagesVariantsCacheDirectory($dirs['variants']);

        $resolutions = new ImageResolutionsSettings();
        $resolutions->addResolutionSettingsByObject(new ImageResolutionSettings(320));
        $resolutions->addResolutionSettingsByObject(new ImageResolutionSettings(640));
        $r->setImageVariantsResolutions($resolutions);
        $r->setCreateVariantsBottomLimit(50);

        if ($noImgBitmap !== null) {
            $r->setNoImagePath($noImgBitmap);
        }

        $html = $r->getImage($bitmapImage, 'sample bitmap', false, '100vw', 'a caption');
        assertContains($html, '<img', 'getImage');
        return substr($html, 0, 160) . '…';
    });

    check('BitmapImageRenderer: getImageVariant + getImageVariantSrc', function () use ($decoratingFactory, $dirs, $bitmapImage, $noImgBitmap) {
        $r = new BitmapImageRenderer($decoratingFactory);
        $r->setTemplateFactory($decoratingFactory);
        $r->setImagesVariantsCacheDirectory($dirs['variants']);
        $r->setCreateVariantsBottomLimit(50);
        if ($noImgBitmap !== null) {
            $r->setNoImagePath($noImgBitmap);
        }

        $size = new ImageResolutionSettings(320);
        $html = $r->getImageVariant($bitmapImage, $size, 'alt', '', false, []);
        $src = $r->getImageVariantSrc($bitmapImage, $size);
        assertContains($html, '<img', 'getImageVariant');
        assertTrue($src !== '', 'getImageVariantSrc should return non-empty');
        return 'src=' . $src;
    });
} else {
    foreach ([
        'BitmapImageRenderer: createImageVariants',
        'BitmapImageRenderer: getImageSrcSet returns string',
        'BitmapImageRenderer: getImage returns <img> markup',
        'BitmapImageRenderer: getImageVariant + getImageVariantSrc',
    ] as $name) {
        skip($name, 'no bitmap image in test/images/');
    }
}

// ---------------------------------------------------------------------------
// Factory interfaces
// ---------------------------------------------------------------------------
check('VectorImageRendererFactory interface contract', function () use ($decoratingFactory) {
    $factory = new class($decoratingFactory) implements \Optimal\ImageRenderer\VectorImageRendererFactory {
        public function __construct(private TemplateFactory $tf) {}
        public function create(): VectorImageRenderer
        {
            $r = new VectorImageRenderer($this->tf);
            $r->setTemplateFactory($this->tf);
            return $r;
        }
    };
    assertTrue($factory->create() instanceof VectorImageRenderer);
});

check('BitmapImageRendererFactory interface contract', function () use ($decoratingFactory) {
    $factory = new class($decoratingFactory) implements \Optimal\ImageRenderer\BitmapImageRendererFactory {
        public function __construct(private TemplateFactory $tf) {}
        public function create(): BitmapImageRenderer
        {
            $r = new BitmapImageRenderer($this->tf);
            $r->setTemplateFactory($this->tf);
            return $r;
        }
    };
    assertTrue($factory->create() instanceof BitmapImageRenderer);
});

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
$pass = 0; $fail = 0; $skipCount = 0;
foreach ($results as $r) {
    $r['status'] === 'pass' ? $pass++ : ($r['status'] === 'fail' ? $fail++ : $skipCount++);
}

if ($cli) {
    foreach ($results as $r) {
        $mark = match ($r['status']) {
            'pass' => "[PASS]",
            'fail' => "[FAIL]",
            'skip' => "[SKIP]",
        };
        printf("%-7s %s  (%.3fs)\n", $mark, $r['name'], $r['time']);
        if ($r['detail'] !== '') {
            foreach (explode("\n", $r['detail']) as $line) {
                echo "         $line\n";
            }
        }
    }
    echo "\n";
    echo "PHP: " . PHP_VERSION . "\n";
    echo "Images dir: " . $dirs['images'] . "\n";
    echo "Bitmap found: " . ($bitmapImage ?? '(none)') . "\n";
    echo "SVG found: " . ($svgImage ?? '(none)') . "\n";
    echo "No-image bitmap: " . ($noImgBitmap ?? '(none)') . "\n";
    echo "No-image svg: " . ($noImgSvg ?? '(none)') . "\n";
    echo "\n";
    echo sprintf("Total: %d   Pass: %d   Fail: %d   Skip: %d\n",
        count($results), $pass, $fail, $skipCount);
    exit($fail === 0 ? 0 : 1);
}

// Browser output
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html><head><meta charset="utf-8"><title>image-renderer tests</title>
<style>
body { font: 14px/1.4 ui-sans-serif, system-ui, sans-serif; max-width: 1100px; margin: 2em auto; padding: 0 1em; }
table { border-collapse: collapse; width: 100%; }
th, td { padding: .4em .6em; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
.pass { color: #0a7d33; font-weight: 600; }
.fail { color: #b00020; font-weight: 600; }
.skip { color: #888; }
pre { margin: 0; white-space: pre-wrap; word-break: break-word; font: 12px/1.4 ui-monospace, monospace; color: #444; }
.summary { padding: .8em; border-radius: 6px; background: #f4f6f8; margin-top: 1em; }
.summary.has-fail { background: #fdecea; }
</style></head><body>
<h1>image-renderer functional tests</h1>
<p>PHP <?= htmlspecialchars(PHP_VERSION) ?> · images dir: <code><?= htmlspecialchars($dirs['images']) ?></code></p>
<ul>
    <li>bitmap: <code><?= htmlspecialchars($bitmapImage ?? '(none — add a .jpg/.png/.webp)') ?></code></li>
    <li>svg: <code><?= htmlspecialchars($svgImage ?? '(none — add a .svg)') ?></code></li>
    <li>no-image bitmap: <code><?= htmlspecialchars($noImgBitmap ?? '(none — add no-image.jpg/png)') ?></code></li>
    <li>no-image svg: <code><?= htmlspecialchars($noImgSvg ?? '(none — add no-image.svg)') ?></code></li>
</ul>
<table>
<thead><tr><th>#</th><th>Test</th><th>Status</th><th>Time</th><th>Detail</th></tr></thead>
<tbody>
<?php foreach ($results as $i => $r): ?>
    <tr>
        <td><?= $i + 1 ?></td>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td class="<?= $r['status'] ?>"><?= strtoupper($r['status']) ?></td>
        <td><?= number_format($r['time'], 3) ?>s</td>
        <td><pre><?= htmlspecialchars($r['detail']) ?></pre></td>
    </tr>
<?php endforeach; ?>
</tbody></table>

<div class="summary <?= $fail > 0 ? 'has-fail' : '' ?>">
    <strong>Total:</strong> <?= count($results) ?>
    · <span class="pass">Pass: <?= $pass ?></span>
    · <span class="fail">Fail: <?= $fail ?></span>
    · <span class="skip">Skip: <?= $skipCount ?></span>
</div>
</body></html>
