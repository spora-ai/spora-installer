<?php

declare(strict_types=1);

namespace Spora\Composer;

use Composer\Composer;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use React\Promise\PromiseInterface;

/**
 * Routes packages of type `spora-plugin-frontend` to
 * `public/plugins/{$slug}/` when installed via Composer.
 *
 * The package is downloaded to Composer's default `vendor/<vendor>/<name>/`
 * location (the standard {@see LibraryInstaller} flow). After extraction, the
 * plugin's pre-built `frontend/` directory is copied verbatim into
 * `public/plugins/{$slug}/`, so the host SPA can lazy-load
 * `/plugins/<slug>/main.js` at runtime without any extra `/dist/` nesting.
 *
 * The slug is the last segment of the package name — mirroring how
 * {@see SporaPluginInstaller} derives the plugin directory — so that two
 * plugins with the same short name from different vendors would collide.
 * Composer treats the short name as unique within a project, which is the
 * same convention used everywhere else in the Spora plugin layout.
 *
 * Behaviour:
 * - A package without a `frontend/` directory is treated as "no UI shipped"
 *   and skipped silently. This keeps backend-only plugins (`spora-plugin`,
 *   `spora-plugin-minimax`, …) free to add an optional `frontend/` later
 *   without changing the install path.
 * - Existing files at the destination are overwritten on install/update.
 * - On uninstall, the entire `public/plugins/{$slug}/` directory is removed.
 */
final class SporaPluginFrontendInstaller extends LibraryInstaller
{
    private const SPORA_PLUGIN_FRONTEND_TYPE = 'spora-plugin-frontend';

    private const FRONTEND_SOURCE_DIR = 'frontend';

    private const PUBLIC_DESTINATION_DIR = 'public/plugins';

    public function __construct(IOInterface $io, Composer $composer, ?Filesystem $filesystem = null)
    {
        parent::__construct($io, $composer);

        // LibraryInstaller accepts a Filesystem via its constructor; we pass
        // the injected instance down so tests can swap in a mock if needed.
        if ($filesystem !== null) {
            $this->filesystem = $filesystem;
        }
    }

    public function supports(string $packageType): bool
    {
        return $packageType === self::SPORA_PLUGIN_FRONTEND_TYPE;
    }

    public function getInstallPath(PackageInterface $package): string
    {
        // Composer still downloads the package to its default vendor location;
        // we never rely on the returned path ourselves, but Composer's
        // installation manager and download manager do, so it must be a real,
        // writable path inside the project.
        return parent::getInstallPath($package);
    }

    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $promise = parent::install($repo, $package);
        if (!$promise instanceof PromiseInterface) {
            $promise = \React\Promise\resolve(null);
        }

        $installPath = $this->getInstallPath($package);

        return $promise->then(function () use ($installPath, $package): void {
            $this->copyFrontend($installPath, $package);
        });
    }

    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $promise = parent::update($repo, $initial, $target);
        if (!$promise instanceof PromiseInterface) {
            $promise = \React\Promise\resolve(null);
        }

        $installPath = $this->getInstallPath($target);

        return $promise->then(function () use ($installPath, $target): void {
            $this->copyFrontend($installPath, $target);
        });
    }

    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $destination = $this->getPluginDestination($package);

        $promise = parent::uninstall($repo, $package);
        if (!$promise instanceof PromiseInterface) {
            $promise = \React\Promise\resolve(null);
        }

        return $promise->then(function () use ($destination): void {
            // Only remove the destination if it looks like ours (i.e. inside
            // `public/plugins/`). This guards against deleting an unrelated
            // directory in the unlikely case someone reuses the slug.
            if ($this->isManagedDestination($destination) && is_dir($destination)) {
                $this->filesystem->removeDirectory($destination);
            }
        });
    }

    /**
     * Compute the runtime destination for a plugin's bundled frontend. Uses
     * the same short-name slug as {@see SporaPluginInstaller} so the public
     * path matches the on-disk plugin directory.
     */
    private function getPluginDestination(PackageInterface $package): string
    {
        $slug = $this->pluginSlug($package);

        return self::PUBLIC_DESTINATION_DIR.'/'.$slug.'/';
    }

    private function pluginSlug(PackageInterface $package): string
    {
        // Pretty name is always `<vendor>/<name>` for non-metapackages; the
        // short name is the canonical Composer identifier for a package.
        $prettyName = $package->getPrettyName();
        $parts = explode('/', $prettyName);

        return end($parts);
    }

    private function copyFrontend(string $installPath, PackageInterface $package): void
    {
        $source = rtrim($installPath, '/').'/'.self::FRONTEND_SOURCE_DIR;

        // No `frontend/` directory in the package → backend-only plugin.
        // Skip silently so plugins can opt into shipping UI without a
        // separate install path or Composer type.
        if (!is_dir($source)) {
            return;
        }

        $destination = $this->getPluginDestination($package);

        $this->filesystem->ensureDirectoryExists($destination);

        // Recursive copy; overwrites existing files in the destination so a
        // plugin update replaces the previous bundle in place.
        $this->filesystem->copy($source, $destination);
    }

    private function isManagedDestination(string $destination): bool
    {
        $normalized = rtrim($destination, '/').'/';

        return str_starts_with($normalized, self::PUBLIC_DESTINATION_DIR.'/');
    }
}