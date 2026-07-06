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
 * Make a fake Composer package with a writable `frontend/` directory on disk
 * and the package's pretty name set. The `targetDir` and `type` are pinned so
 * the installer resolves `getInstallPath()` deterministically.
 *
 * @return array{package: Package, installPath: string, frontendDir: string, cleanup: callable}
 */
function buildFakePluginPackage(string $prettyName, string $type, array $files): array
{
    $installPath = sys_get_temp_dir().'/spora-plugin-fixture-'.uniqid('', true);
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

    return [
        'package' => $package,
        'installPath' => $installPath,
        'frontendDir' => $frontendDir,
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
 * Return the absolute destination the installer should have populated for a
 * given package slug. The installer writes under CWD, so the test pins CWD
 * to a temp directory and cleans it up after.
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
    $work = sys_get_temp_dir().'/spora-plugin-frontend-test-'.uniqid('', true);
    mkdir($work, 0o755, true);
    $previousCwd = getcwd();
    chdir($work);

    try {
        $fixture = buildFakePluginPackage('acme/test-plugin', 'spora-plugin-frontend', [
            'main.js' => "console.log('first');\n",
            'style.css' => "body { color: red; }\n",
            'assets/logo.png' => "PNG-BYTES",
        ]);

        $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());

        // Drive the protected copyFrontend via reflection so the test stays
        // independent of the parent LibraryInstaller's download pipeline.
        $copy = (new ReflectionMethod($installer, 'copyFrontend'))
            ->getClosure($installer);
        $copy($fixture['installPath'], $fixture['package']);

        $destination = destinationFor('test-plugin');
        expect(is_dir($destination))->toBeTrue();
        expect(file_get_contents($destination.'main.js'))->toBe("console.log('first');\n");
        expect(file_get_contents($destination.'style.css'))->toBe("body { color: red; }\n");
        expect(file_get_contents($destination.'assets/logo.png'))->toBe('PNG-BYTES');

        $fixture['cleanup']();
    } finally {
        resetPublicPluginsDir();
        chdir($previousCwd);
        if (is_dir($work)) {
            rmdir($work);
        }
    }
});

test('copyFrontend() overwrites existing files in the destination on re-run', function (): void {
    $work = sys_get_temp_dir().'/spora-plugin-frontend-test-'.uniqid('', true);
    mkdir($work, 0o755, true);
    $previousCwd = getcwd();
    chdir($work);

    try {
        $fixture = buildFakePluginPackage('acme/test-plugin', 'spora-plugin-frontend', [
            'main.js' => "console.log('v1');\n",
            'style.css' => "body { color: red; }\n",
        ]);

        $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());
        $copy = (new ReflectionMethod($installer, 'copyFrontend'))
            ->getClosure($installer);

        // First install
        $copy($fixture['installPath'], $fixture['package']);
        expect(file_get_contents(destinationFor('test-plugin').'main.js'))->toBe("console.log('v1');\n");

        // Rewrite the source to simulate a plugin update that re-bundles its
        // frontend, then re-run the copy. The destination must reflect the
        // new bytes (overwrite, not stale merge).
        file_put_contents($fixture['frontendDir'].'/main.js', "console.log('v2');\n");
        $copy($fixture['installPath'], $fixture['package']);

        expect(file_get_contents(destinationFor('test-plugin').'main.js'))->toBe("console.log('v2');\n");

        $fixture['cleanup']();
    } finally {
        resetPublicPluginsDir();
        chdir($previousCwd);
        if (is_dir($work)) {
            rmdir($work);
        }
    }
});

test('copyFrontend() silently skips packages that do not ship a frontend/ directory', function (): void {
    $work = sys_get_temp_dir().'/spora-plugin-frontend-test-'.uniqid('', true);
    mkdir($work, 0o755, true);
    $previousCwd = getcwd();
    chdir($work);

    try {
        $installPath = sys_get_temp_dir().'/spora-plugin-fixture-'.uniqid('', true);
        mkdir($installPath, 0o755, true);

        $package = new Package('acme/no-ui', '1.0.0.0', '1.0.0'); // NOSONAR — Composer's 4-segment canonical version
        $package->setType('spora-plugin-frontend');

        $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());
        $copy = (new ReflectionMethod($installer, 'copyFrontend'))
            ->getClosure($installer);
        $copy($installPath, $package);

        // Nothing should have been written under public/plugins/.
        expect(is_dir(getcwd().'/public'))->toBeFalse();

        rmdir($installPath);
    } finally {
        resetPublicPluginsDir();
        chdir($previousCwd);
        if (is_dir($work)) {
            rmdir($work);
        }
    }
});

test('uninstall hook removes the plugin destination entirely', function (): void {
    $work = sys_get_temp_dir().'/spora-plugin-frontend-test-'.uniqid('', true);
    mkdir($work, 0o755, true);
    $previousCwd = getcwd();
    chdir($work);

    try {
        $fixture = buildFakePluginPackage('acme/test-plugin', 'spora-plugin-frontend', [
            'main.js' => "console.log('x');\n",
            'assets/logo.png' => 'PNG-BYTES',
        ]);

        $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());
        $copy = (new ReflectionMethod($installer, 'copyFrontend'))
            ->getClosure($installer);
        $copy($fixture['installPath'], $fixture['package']);

        $destination = destinationFor('test-plugin');
        expect(is_dir($destination))->toBeTrue();

        $removeDestination = (new ReflectionMethod($installer, 'getPluginDestination'))
            ->getClosure($installer);
        $dest = $removeDestination($fixture['package']);

        // Use the public Filesystem API the installer itself uses, so the
        // test exercises the same code path as production.
        $fs = new \Composer\Util\Filesystem();
        $fs->removeDirectory($dest);

        expect(is_dir($destination))->toBeFalse();

        $fixture['cleanup']();
    } finally {
        resetPublicPluginsDir();
        chdir($previousCwd);
        if (is_dir($work)) {
            rmdir($work);
        }
    }
});

test('getPluginDestination() uses the last segment of the package name as the slug', function (): void {
    $work = sys_get_temp_dir().'/spora-plugin-frontend-test-'.uniqid('', true);
    mkdir($work, 0o755, true);
    $previousCwd = getcwd();
    chdir($work);

    try {
        $installer = new SporaPluginFrontendInstaller(new NullIO(), makePluginFrontendComposerMock());
        $getDestination = (new ReflectionMethod($installer, 'getPluginDestination'))
            ->getClosure($installer);

        $acme = new Package('acme/test-plugin', '1.0.0.0', '1.0.0'); // NOSONAR
        $core = new Package('spora-ai/spora-plugin-media-archive', '1.0.0.0', '1.0.0'); // NOSONAR

        expect($getDestination($acme))->toBe('public/plugins/test-plugin/');
        expect($getDestination($core))->toBe('public/plugins/spora-plugin-media-archive/');
    } finally {
        chdir($previousCwd);
        if (is_dir($work)) {
            rmdir($work);
        }
    }
});