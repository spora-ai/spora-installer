<?php

declare(strict_types=1);

use Composer\IO\NullIO;
use Composer\Package\Package;
use Spora\Composer\SporaFrontendInstaller;

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

    // Composer normalizes versions to a 4-segment format (1.2.3.4) per
    // its VersionParser. The literal strings below look like IPv4
    // addresses but are canonical version identifiers, not network
    // addresses — NOSONAR silences SonarQube's hardcoded-IP rule.
    $frontend = new Package('spora-ai/spora-frontend', '1.0.0.0', '1.0.0'); // NOSONAR
    $acme = new Package('acme/anything', '1.0.0.0', '1.0.0'); // NOSONAR

    expect($installer->getInstallPath($frontend))->toBe('public/dist/');
    expect($installer->getInstallPath($acme))->toBe('public/dist/');
});