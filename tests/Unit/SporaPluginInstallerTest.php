<?php

declare(strict_types=1);

use Composer\IO\NullIO;
use Composer\Package\Package;
use Spora\Composer\SporaPluginInstaller;

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

    // Two packages from different vendors with the same short name will
    // collide on disk — the routing discards the vendor segment. Operators
    // who vendor plugin packages privately must keep the short names
    // unique; the spora-ai Packagist org guarantees uniqueness for
    // publicly distributed plugins.
    $acme = new Package('acme/foo', '1.0.0.0', '1.0.0'); // NOSONAR — Composer's 4-segment canonical version, not a network address
    $core = new Package('spora-ai/foo', '1.0.0.0', '1.0.0'); // NOSONAR — Composer's 4-segment canonical version, not a network address

    expect($installer->getInstallPath($acme))->toBe('plugins/foo/');
    expect($installer->getInstallPath($core))->toBe('plugins/foo/');
});
