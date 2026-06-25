<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Package\Package;
use Mockery as M;
use Spora\Composer\SporaFrontendInstaller;

/**
 * Build a Composer mock that survives `new SporaFrontendInstaller($io, $composer)`.
 *
 * Guarded with function_exists so this self-contained copy does not collide
 * with the identically-named helper in SporaPluginInstallerTest.php when
 * PHPUnit loads both files into the same process.
 */
if (!function_exists('makeComposerMock')) {
    function makeComposerMock(): Composer
    {
        $config = new Config(false, '/vendor');

        $composer = M::mock(Composer::class);
        $composer->shouldReceive('getConfig')->andReturn($config);
        $composer->shouldIgnoreMissing();

        return $composer;
    }
}

test('supports() returns true only for the spora-frontend type', function (): void {
    $installer = new SporaFrontendInstaller(new NullIO(), makeComposerMock());

    expect($installer->supports('spora-frontend'))->toBeTrue();
    expect($installer->supports('spora-plugin'))->toBeFalse();
    expect($installer->supports('library'))->toBeFalse();
    expect($installer->supports('metapackage'))->toBeFalse();
    expect($installer->supports(''))->toBeFalse();
});

test('getInstallPath() returns public/dist/ regardless of package name', function (): void {
    $installer = new SporaFrontendInstaller(new NullIO(), makeComposerMock());

    $frontend = new Package('spora-ai/spora-frontend', '1.0.0.0', '1.0.0');
    $acme = new Package('acme/anything', '1.0.0.0', '1.0.0');

    expect($installer->getInstallPath($frontend))->toBe('public/dist/');
    expect($installer->getInstallPath($acme))->toBe('public/dist/');
});
