<?php

declare(strict_types=1);

namespace Spora\Composer;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;

/**
 * Routes packages of type `spora-plugin` to `plugins/{$name}/` when
 * installed via Composer. Other types fall through to Composer's default
 * library installer unchanged.
 */
final class SporaPluginInstaller extends LibraryInstaller
{
    private const SPORA_PLUGIN_TYPE = 'spora-plugin';

    /**
     * Determine whether this installer handles the given Composer package type.
     */
    public function supports(string $packageType): bool
    {
        return $packageType === self::SPORA_PLUGIN_TYPE;
    }

    /**
     * Return the plugin installation directory derived from the package's short name.
     *
     * The vendor segment is discarded; the spora-ai Packagist org
     * guarantees uniqueness of short names for the public distribution.
     * Operators who vendor plugin packages privately must keep the short
     * names unique on disk.
     */
    public function getInstallPath(PackageInterface $package): string
    {
        [, $name] = explode('/', $package->getPrettyName());

        return "plugins/{$name}/";
    }
}
