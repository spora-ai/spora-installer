<?php

declare(strict_types=1);

namespace Spora\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * Composer plugin entry point. Registers the Spora installers with
 * Composer's installation manager:
 *
 * - {@see SporaPluginInstaller} routes `spora-plugin` packages into
 *   `plugins/{$name}/` instead of the default `vendor/` location.
 * - {@see SporaFrontendInstaller} routes `spora-frontend` packages into
 *   `public/dist/` — the prebuilt frontend assets the operator's PHP
 *   project serves at runtime.
 */
final class SporaPluginInstallerPlugin implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        $manager = $composer->getInstallationManager();

        foreach ([
            new SporaPluginInstaller($io, $composer),
            new SporaFrontendInstaller($io, $composer),
        ] as $installer) {
            $manager->addInstaller($installer);
        }
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Intentionally empty: the registered installer becomes inert once
        // the plugin's own classes are no longer autoloadable. Matches the
        // pattern used by composer/installers.
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Intentionally empty: this plugin's own classes are being removed
        // in the same operation, so the installer registration is moot.
    }
}
