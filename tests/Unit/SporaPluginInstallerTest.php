<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Package\Package;
use Mockery as M;
use Spora\Composer\SporaPluginInstaller;

/**
 * Build a Composer mock that survives `new SporaPluginInstaller($io, $composer)`.
 *
 * The parent LibraryInstaller constructor chains
 * `$composer->getConfig()->get('vendor-dir')` to build an InstallPathRegex.
 * We pass a real Config (cheap, fully initialised) and shouldIgnoreMissing()
 * the rest of the Composer dependency graph.
 */
function makeComposerMock(): Composer
{
    $config = new Config(false, '/vendor');

    $composer = M::mock(Composer::class);
    $composer->shouldReceive('getConfig')->andReturn($config);
    $composer->shouldIgnoreMissing();

    return $composer;
}

test('supports() returns true only for the spora-plugin type', function (): void {
    $installer = new SporaPluginInstaller(new NullIO(), makeComposerMock());

    expect($installer->supports('spora-plugin'))->toBeTrue();
    expect($installer->supports('library'))->toBeFalse();
    expect($installer->supports('metapackage'))->toBeFalse();
    expect($installer->supports(''))->toBeFalse();
});

test('getInstallPath() returns plugins/{name}/ regardless of vendor', function (): void {
    $installer = new SporaPluginInstaller(new NullIO(), makeComposerMock());

    $package = new Package('spora-ai/spora-plugin-foo', '0.1.0.0', '0.1.0');

    expect($installer->getInstallPath($package))->toBe('plugins/spora-plugin-foo/');
});

test('getInstallPath() uses just the last segment of the package name', function (): void {
    $installer = new SporaPluginInstaller(new NullIO(), makeComposerMock());

    // Two packages from different vendors with the same short name must
    // not collide — the routing uses the short segment, which Composer
    // treats as the unique plugin directory.
    $acme = new Package('acme/foo', '1.0.0.0', '1.0.0');
    $core = new Package('spora-ai/foo', '1.0.0.0', '1.0.0');

    expect($installer->getInstallPath($acme))->toBe('plugins/foo/');
    expect($installer->getInstallPath($core))->toBe('plugins/foo/');
});