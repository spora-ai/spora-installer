<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Package\Package;
use Mockery as M;
use Spora\Composer\SporaPluginFrontendInstaller;

afterEach(function () {
    M::close();
});

const SAMPLE_BODY_CSS = "body { color: red; }\n";
const SAMPLE_PLUGIN_NAME = 'acme/test-plugin';
const SAMPLE_SLUG = 'test-plugin';
const TEST_WORK_PREFIX = '/spora-plugin-frontend-test-';
const FIXTURE_PREFIX = '/spora-plugin-fixture-';
const KEEPME_FILENAME = 'keepme.txt';

/**
 * Build a Composer mock that survives `new SporaPluginFrontendInstaller($io, $composer)`.
 *
 * The parent LibraryInstaller constructor chains
 * `$composer->getConfig()->get('vendor-dir')` to build an InstallPathRegex.
 * We pass a real Config (cheap, fully initialised) and shouldIgnoreMissing()
 * the rest of the Composer dependency graph.
 */
function makePluginFrontendComposerMock(): Composer
{
    $config = new Config(false, sys_get_temp_dir().'/vendor');

    $composer = M::mock(Composer::class);
    $composer->shouldReceive('getConfig')->andReturn($config);
    $composer->shouldIgnoreMissing();

    return $composer;
}

/**
 * Build a fake Composer package on disk. The package's `pretty name`, `type`,
 * and `extra.spora-plugin-slug` are pinned so the installer resolves
 * paths deterministically.
 *
 * @param  array<string, string>  $files  relative-path => contents
 * @return array{package: Package, installPath: string, frontendDir: string, cleanup: callable}
 */
function buildFakePluginPackage(
    string $prettyName,
    string $type,
    array $files,
    ?string $slug = SAMPLE_SLUG,
): array {
    $installPath = sys_get_temp_dir().FIXTURE_PREFIX.uniqid('', true);
    $frontendDir = $installPath.'/frontend';
    mkdir($frontendDir, 0o755, true);

    foreach ($files as $relativePath => $contents) {
        $absolute = $frontendDir.'/'.$relativePath;
        $parent = dirname($absolute);
        if (!is_dir($parent)) {
            mkdir($parent, 0o755, true);
        }
        file_put_contents($absolute, $contents);
    }

    $package = new Package($prettyName, '1.0.0.0', '1.0.0'); // NOSONAR — Composer's 4-segment canonical version
    $package->setType($type);
    if ($slug !== null) {
        $package->setExtra(['spora-plugin-slug' => $slug]);
    }

    return [
        'package' => $package,
        'installPath' => $installPath,
        'frontendDir' => $frontendDir,
        'slug'      => $slug,
        'cleanup' => static function () use ($installPath): void {
            if (is_dir($installPath)) {
                $rii = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($installPath, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($rii as $entry) {
                    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
                }
                rmdir($installPath);
            }
        },
    ];
}

/**
 * Wrap a test body so it runs in an isolated CWD with the public/plugins
 * directory torn down afterwards. Eliminates ~20 lines of repeated setup
 * (mkdir, chdir, try/finally, cleanup) from each test.
 */
function inTempWorkdir(callable $body): void
{
    $work = sys_get_temp_dir().TEST_WORK_PREFIX.uniqid('', true);
    mkdir($work, 0o755, true);
    $previousCwd = getcwd();
    chdir($work);

    try {
        $body($work);
    } finally {
        resetPublicPluginsDir();
        chdir($previousCwd);
        if (is_dir($work)) {
            rmdir($work);
        }
    }
}

/**
 * Return the absolute destination the installer should have populated for a
 * given package slug. The installer writes under CWD.
 */
function destinationFor(string $slug): string
{
    return getcwd().'/public/plugins/'.$slug.'/';
}

function resetPublicPluginsDir(): void
{
    $public = getcwd().'/public';
    if (is_dir($public)) {
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($public, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($public);
    }
}

test('supports() returns true only for the spora-plugin-frontend type', function (): void {
    $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());

    expect($installer->supports('spora-plugin-frontend'))->toBeTrue();
    expect($installer->supports('spora-frontend'))->toBeFalse();
    expect($installer->supports('spora-plugin'))->toBeFalse();
    expect($installer->supports('library'))->toBeFalse();
    expect($installer->supports(''))->toBeFalse();
});

test('copyFrontend() copies main.js, style.css, and nested assets verbatim to public/plugins/<slug>/', function (): void {
    inTempWorkdir(function () use (&$fixture): void {
        $fixture = buildFakePluginPackage(SAMPLE_PLUGIN_NAME, 'spora-plugin-frontend', [
            'main.js' => "console.log('first');\n",
            'style.css' => SAMPLE_BODY_CSS,
            'assets/logo.png' => 'PNG-BYTES',
        ]);

        $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());
        $installer->copyFrontend($fixture['installPath'], $fixture['package']);

        $destination = destinationFor($fixture['slug']);
        expect(is_dir($destination))->toBeTrue();
        expect(file_get_contents($destination.'main.js'))->toBe("console.log('first');\n");
        expect(file_get_contents($destination.'style.css'))->toBe(SAMPLE_BODY_CSS);
        expect(file_get_contents($destination.'assets/logo.png'))->toBe('PNG-BYTES');
    });
    $fixture['cleanup']();
});

test('copyFrontend() overwrites existing files in the destination on re-run', function (): void {
    inTempWorkdir(function () use (&$fixture): void {
        $fixture = buildFakePluginPackage(SAMPLE_PLUGIN_NAME, 'spora-plugin-frontend', [
            'main.js' => "console.log('v1');\n",
            'style.css' => SAMPLE_BODY_CSS,
        ]);

        $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());

        $installer->copyFrontend($fixture['installPath'], $fixture['package']);
        expect(file_get_contents(destinationFor($fixture['slug']).'main.js'))->toBe("console.log('v1');\n");

        file_put_contents($fixture['frontendDir'].'/main.js', "console.log('v2');\n");
        $installer->copyFrontend($fixture['installPath'], $fixture['package']);

        expect(file_get_contents(destinationFor($fixture['slug']).'main.js'))->toBe("console.log('v2');\n");
    });
    $fixture['cleanup']();
});

test('copyFrontend() silently skips packages that do not ship a frontend/ directory', function (): void {
    inTempWorkdir(function (): void {
        $installPath = sys_get_temp_dir().FIXTURE_PREFIX.uniqid('', true);
        mkdir($installPath, 0o755, true);

        $package = new Package('acme/no-ui', '1.0.0.0', '1.0.0'); // NOSONAR — Composer's 4-segment canonical version
        $package->setType('spora-plugin-frontend');
        $package->setExtra(['spora-plugin-slug' => 'no-ui']);

        $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());
        $installer->copyFrontend($installPath, $package);

        expect(is_dir(getcwd().'/public'))->toBeFalse();

        rmdir($installPath);
    });
});

test('copyFrontend() routes to public/plugins/<extra.spora-plugin-slug>/, not the package short name', function (): void {
    // The package's composer short name is 'foo-frontend', but its
    // extra.spora-plugin-slug declares 'media-archive' — the installer's
    // destination MUST follow the declaration, not the short name.
    inTempWorkdir(function () use (&$fixture): void {
        $fixture = buildFakePluginPackage('acme/foo-frontend', 'spora-plugin-frontend', [
            'main.js' => "console.log('hi');\n",
        ], 'media-archive');

        $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());
        $installer->copyFrontend($fixture['installPath'], $fixture['package']);

        expect(is_dir(destinationFor('media-archive')))->toBeTrue();
        expect(is_dir(destinationFor('foo-frontend')))->toBeFalse();
        expect(file_get_contents(destinationFor('media-archive').'main.js'))
            ->toBe("console.log('hi');\n");
    });
    $fixture['cleanup']();
});

test('copyFrontend() throws InvalidArgumentException when extra.spora-plugin-slug is missing', function (): void {
    inTempWorkdir(function () use (&$fixture): void {
        // null = simulate a package without the extra block at all.
        $fixture = buildFakePluginPackage('acme/missing-slug', 'spora-plugin-frontend', [
            'main.js' => "console.log('hi');\n",
        ], null);

        $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());

        expect(fn () => $installer->copyFrontend($fixture['installPath'], $fixture['package']))
            ->toThrow(
                InvalidArgumentException::class,
                'extra.spora-plugin-slug',
            );

        // The destination must NOT be created — fail-loud means no
        // partial install.
        expect(is_dir(getcwd().'/public'))->toBeFalse();
    });
    $fixture['cleanup']();
});

test('copyFrontend() throws InvalidArgumentException when extra.spora-plugin-slug is empty', function (): void {
    inTempWorkdir(function () use (&$fixture): void {
        $fixture = buildFakePluginPackage('acme/empty-slug', 'spora-plugin-frontend', [
            'main.js' => "console.log('hi');\n",
        ], '');

        $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());

        expect(fn () => $installer->copyFrontend($fixture['installPath'], $fixture['package']))
            ->toThrow(InvalidArgumentException::class);
    });
    $fixture['cleanup']();
});

test('copyFrontend() rejects slugs that contain path-traversal segments', function (): void {
    inTempWorkdir(function () use (&$fixture): void {
        $fixture = buildFakePluginPackage('acme/evil-slug', 'spora-plugin-frontend', [
            'main.js' => "console.log('hi');\n",
        ], '../../etc');

        $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());

        expect(fn () => $installer->copyFrontend($fixture['installPath'], $fixture['package']))
            ->toThrow(InvalidArgumentException::class);
    });
    $fixture['cleanup']();
});

test('removeDestinationSafely() removes the destination when it is managed', function (): void {
    inTempWorkdir(function () use (&$fixture): void {
        $fixture = buildFakePluginPackage(SAMPLE_PLUGIN_NAME, 'spora-plugin-frontend', [
            'main.js' => "console.log('x');\n",
            'assets/logo.png' => 'PNG-BYTES',
        ]);

        $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());
        $installer->copyFrontend($fixture['installPath'], $fixture['package']);

        $destination = destinationFor($fixture['slug']);
        expect(is_dir($destination))->toBeTrue();

        // Drive the helper that backs uninstall()'s then-callback. The
        // uninstall() entry point chains a parent::uninstall() promise
        // which pulls in the Composer download manager — not worth
        // mocking here. We test the actual safety + delete logic.
        $installer->removeDestinationSafely($installer->getPluginDestination($fixture['package']));

        expect(is_dir($destination))->toBeFalse();
    });
    $fixture['cleanup']();
});

test('removeDestinationSafely() refuses to delete a destination outside public/plugins/', function (): void {
    inTempWorkdir(function () use (&$offLimits): void {
        // Use an absolute path so the assertion doesn't depend on
        // inTempWorkdir's CWD mechanics.
        $offLimits = sys_get_temp_dir().TEST_WORK_PREFIX.uniqid('', true).'/somewhere/else';
        mkdir($offLimits, 0o755, true);
        file_put_contents($offLimits.'/'.KEEPME_FILENAME, 'do not delete');

        $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());

        $installer->removeDestinationSafely($offLimits.'/');

        // The guard must reject this path — file should still exist.
        expect(is_file($offLimits.'/'.KEEPME_FILENAME))->toBeTrue();
    });

    if (isset($offLimits)) {
        @unlink($offLimits.'/'.KEEPME_FILENAME);
        @rmdir($offLimits);
    }
});

test('isManagedDestination() rejects traversal segments like ../', function (): void {
    $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());

    expect($installer->isManagedDestination('public/plugins/foo/'))->toBeTrue();
    expect($installer->isManagedDestination('public/plugins/foo/bar.js'))->toBeTrue();
    expect($installer->isManagedDestination('public/plugins'))->toBeTrue();

    expect($installer->isManagedDestination('public/plugins/../etc/'))->toBeFalse();
    expect($installer->isManagedDestination('public/plugins/./foo/'))->toBeFalse();
    expect($installer->isManagedDestination('private/plugins/foo/'))->toBeFalse();
    expect($installer->isManagedDestination('/etc/passwd'))->toBeFalse();
});

test('getPluginDestination() reads the slug from extra.spora-plugin-slug, not the package name', function (): void {
    // Two packages whose composer name and extra.spora-plugin-slug differ.
    // The destination MUST follow the declaration (the host SPA's
    // /api/v1/apps response emits that slug).
    inTempWorkdir(function (): void {
        $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());

        $acme = new Package('acme/test-plugin', '1.0.0.0', '1.0.0'); // NOSONAR
        $acme->setExtra(['spora-plugin-slug' => 'foo']);

        $core = new Package('spora-ai/spora-plugin-media-archive-frontend', '1.0.0.0', '1.0.0'); // NOSONAR
        $core->setExtra(['spora-plugin-slug' => 'media-archive']);

        expect($installer->getPluginDestination($acme))->toBe('public/plugins/foo/');
        expect($installer->getPluginDestination($core))->toBe('public/plugins/media-archive/');
    });
});

test('getPluginDestination() throws when extra.spora-plugin-slug is absent', function (): void {
    // The host SPA expects the destination slug to match the parent plugin's
    // plugin.json#slug. Without a declaration, the installer's destination
    // is unknowable — fail loud, never silently fall back to the short name.
    $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());

    $package = new Package('acme/foo-frontend', '1.0.0.0', '1.0.0'); // NOSONAR
    $package->setType('spora-plugin-frontend');

    expect(fn () => $installer->getPluginDestination($package))
        ->toThrow(InvalidArgumentException::class, 'extra.spora-plugin-slug');
});
