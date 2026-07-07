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
 * {@see SporaPluginInstaller} derives the plugin directory. Note that
 * Composer's package identity is `<vendor>/<name>`, so two packages
 * from different vendors that happen to share a short name would
 * collide on disk. We treat this as a Spora convention, not a
 * Composer guarantee — the spora-ai org owns all plugin packages
 * shipped via the public Packagist distribution, and operators who
 * vendor plugins privately should keep the short names unique.
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
    public const SPORA_PLUGIN_FRONTEND_TYPE = 'spora-plugin-frontend';

    public const FRONTEND_SOURCE_DIR = 'frontend';

    public const PUBLIC_DESTINATION_DIR = 'public/plugins';

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
            $this->removeDestinationSafely($destination);
        });
    }

    /**
     * Delete the destination only when it looks like ours (i.e. inside
     * `public/plugins/`). Extracted from the uninstall() promise body so
     * the safety check + Filesystem::removeDirectory() call can be unit
     * tested directly without mocking the full Composer uninstall chain.
     *
     * Public for the same testability reason as {@see copyFrontend()}.
     */
    public function removeDestinationSafely(string $destination): void
    {
        if ($this->isManagedDestination($destination) && is_dir($destination)) {
            $this->filesystem->removeDirectory($destination);
        }
    }

    /**
     * Compute the runtime destination for a plugin's bundled frontend. Uses
     * the same short-name slug as {@see SporaPluginInstaller} so the public
     * path matches the on-disk plugin directory.
     */
    public function getPluginDestination(PackageInterface $package): string
    {
        return self::PUBLIC_DESTINATION_DIR.'/'.self::pluginSlug($package).'/';
    }

    /**
     * Public to keep the installer's helper methods unit-testable without
     * reflection. Production callers go through {@see install()} / {@see update()}.
     */
    public function copyFrontend(string $installPath, PackageInterface $package): void
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

    public function isManagedDestination(string $destination): bool
    {
        // Reject any path that tries to escape via traversal segments
        // before the prefix check. `public/plugins/../etc/` would
        // otherwise pass `str_starts_with($normalized, 'public/plugins/')`.
        $trimmed = rtrim($destination, '/');
        if ($trimmed === self::PUBLIC_DESTINATION_DIR) {
            return true;
        }
        $segments = explode('/', $trimmed);
        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }

        return str_starts_with($trimmed.'/', self::PUBLIC_DESTINATION_DIR.'/');
    }

    private static function pluginSlug(PackageInterface $package): string
    {
        // Pretty name is always `<vendor>/<name>` for non-metapackages; the
        // short name is the canonical Composer identifier for a package.
        $prettyName = $package->getPrettyName();
        $parts = explode('/', $prettyName);

        return end($parts);
    }
}
