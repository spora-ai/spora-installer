<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\IO\NullIO;
use Mockery as M;
use Spora\Composer\SporaPluginInstaller;
use Spora\Composer\SporaPluginInstallerPlugin;

test('activate() registers a SporaPluginInstaller with the InstallationManager', function (): void {
    $manager = M::mock(\Composer\Installer\InstallationManager::class);
    $manager->shouldReceive('addInstaller')
        ->once()
        ->with(M::type(SporaPluginInstaller::class));

    // Real Config so LibraryInstaller's `$config->get('vendor-dir')`
    // resolves without us guessing Composer internals; the rest of the
    // Composer graph is shouldIgnoreMissing()'d.
    $config = new \Composer\Config(false, '/vendor');
    $composer = M::mock(Composer::class);
    $composer->shouldReceive('getConfig')->andReturn($config);
    $composer->shouldReceive('getInstallationManager')->once()->andReturn($manager);
    $composer->shouldIgnoreMissing();

    $plugin = new SporaPluginInstallerPlugin();
    $plugin->activate($composer, new NullIO());
});

test('deactivate() is a no-op', function (): void {
    $plugin = new SporaPluginInstallerPlugin();

    expect(fn () => $plugin->deactivate(M::mock(Composer::class)->shouldIgnoreMissing(), new NullIO()))
        ->not->toThrow(Throwable::class);
});

test('uninstall() is a no-op', function (): void {
    $plugin = new SporaPluginInstallerPlugin();

    expect(fn () => $plugin->uninstall(M::mock(Composer::class)->shouldIgnoreMissing(), new NullIO()))
        ->not->toThrow(Throwable::class);
});