<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\Config;
use Mockery as M;

afterEach(function () {
    M::close();
});

/**
 * Build a Composer mock that survives `new SporaPluginInstaller($io, $composer)`
 * (and its sibling SporaFrontendInstaller).
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