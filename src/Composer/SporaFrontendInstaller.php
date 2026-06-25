<?php

declare(strict_types=1);

namespace Spora\Composer;

use Composer\Composer;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;

/**
 * Routes packages of type `spora-frontend` to `public/dist/` when installed
 * via Composer. Other types fall through to Composer's default library
 * installer unchanged.
 *
 * The frontend is a singleton: only one version can be installed at a time,
 * so the path has no name segment — every `spora-frontend` package overwrites
 * the previous build artefacts.
 */
final class SporaFrontendInstaller extends LibraryInstaller
{
    private const SPORA_FRONTEND_TYPE = 'spora-frontend';

    public function __construct(IOInterface $io, Composer $composer)
    {
        parent::__construct($io, $composer);
    }

    public function supports(string $packageType): bool
    {
        return $packageType === self::SPORA_FRONTEND_TYPE;
    }

    public function getInstallPath(PackageInterface $package): string
    {
        return 'public/dist/';
    }
}
