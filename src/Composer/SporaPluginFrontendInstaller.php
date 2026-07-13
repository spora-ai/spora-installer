<?php

declare(strict_types=1);

namespace Spora\Composer;

use Composer\Composer;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use InvalidArgumentException;
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
 * The slug is read from `composer.json#extra.spora-plugin-slug` on the
 * frontend package. The frontend's parent PHP plugin (a sibling Composer
 * package) carries its slug in its own `plugin.json#slug`; the installer
 * can't read that file (it doesn't know which parent the frontend pairs
 * with), so the frontend must declare the slug itself. The value MUST
 * match the parent plugin's `plugin.json#slug` exactly — the host SPA's
 * `/api/v1/apps` response emits that slug, and a mismatch leaves the
 * bundle unreachable at runtime.
 *
 * If `extra.spora-plugin-slug` is absent or empty, the installer refuses
 * to install the package and throws {@see \Spora\Composer\Exceptions\PluginInstallFailedException}
 * with a message pointing at the missing field. There is no fallback to
 * the package short name — silent fallback produced a runtime 404 in
 * the past when the parent plugin's slug (`media-archive`) differed from
 * the frontend package's short name (`spora-plugin-media-archive-frontend`).
 * Fail-loud is the contract.
 *
 * Behaviour:
 * - A package without a `frontend/` directory is treated as "no UI shipped"
 *   and skipped silently. This keeps backend-only plugins (`spora-plugin`,
 *   `spora-plugin-minimax`, …) free to add an optional `frontend/` later
 *   without changing the install path.
 * - Existing files at the destination are overwritten on install/update.
 * - On uninstall, the entire `public/plugins/{$slug}/` directory is removed.
 *
 * @see docs/spora-plugin-frontend.md (operator + author docs)
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

    /**
     * Read `extra.spora-plugin-slug` from the frontend package's
     * composer.json. Strict: missing or empty throws — see the class
     * docblock for why. The slug must match the parent PHP plugin's
     * `plugin.json#slug`.
     *
     * Exposed (not private) so the test suite can exercise the same
     * resolution path without driving a full install.
     */
    public static function pluginSlug(PackageInterface $package): string
    {
        $extra = $package->getExtra();
        if (!is_array($extra)) {
            throw new InvalidArgumentException(sprintf(
                "SporaPluginFrontendInstaller: package '%s' (type spora-plugin-frontend) has no composer.json#extra block — declare '\"extra\": {\"spora-plugin-slug\": \"<parent-slug>\"}' in composer.json, where <parent-slug> is the slug from the parent PHP plugin's plugin.json#slug.",
                $package->getPrettyName(),
            ));
        }

        $slug = $extra['spora-plugin-slug'] ?? null;
        if (!is_string($slug) || $slug === '') {
            throw new InvalidArgumentException(sprintf(
                "SporaPluginFrontendInstaller: package '%s' (type spora-plugin-frontend) is missing composer.json#extra.spora-plugin-slug. Declare '\"extra\": {\"spora-plugin-slug\": \"<parent-slug>\"}' in composer.json, where <parent-slug> is the slug from the parent PHP plugin's plugin.json#slug.",
                $package->getPrettyName(),
            ));
        }

        // Defensive: the destination is on the public web root, so reject
        // slugs that could escape it via traversal or absolute paths.
        if (str_contains($slug, '/') || str_contains($slug, '\\') || str_contains($slug, '..')) {
            throw new InvalidArgumentException(sprintf(
                "SporaPluginFrontendInstaller: package '%s' declared an invalid spora-plugin-slug '%s' (must not contain '/', '\\\\', or '..').",
                $package->getPrettyName(),
                $slug,
            ));
        }

        return $slug;
    }
}
