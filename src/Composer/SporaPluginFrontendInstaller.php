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
 * If `extra.spora-plugin-slug` is absent, empty, or not a string, the
 * installer refuses to install the package and throws
 * {@see \InvalidArgumentException} with a message pointing at the missing
 * field. There is no fallback to the package short name — silent fallback
 * produced a runtime 404 in the past when the parent plugin's slug
 * (`media-archive`) differed from the frontend package's short name
 * (`spora-plugin-media-archive-frontend`). Fail-loud is the contract.
 *
 * Behaviour:
 * - A package without a `frontend/` directory is treated as "no UI shipped"
 *   and skipped silently.
 * - Existing files at the destination are overwritten on install/update.
 * - On uninstall, the entire `public/plugins/{$slug}/` directory is removed
 *   if the slug is still resolvable; legacy installs that pre-date the
 *   `extra.spora-plugin-slug` contract are skipped silently so removal
 *   still succeeds.
 *
 * @see docs/spora-plugin-frontend.md (operator + author docs)
 */
final class SporaPluginFrontendInstaller extends LibraryInstaller
{
    public const SPORA_PLUGIN_FRONTEND_TYPE = 'spora-plugin-frontend';

    public const FRONTEND_SOURCE_DIR = 'frontend';

    public const PUBLIC_DESTINATION_DIR = 'public/plugins';

    /**
     * Frontend slugs must match `^[a-z0-9]+(?:-[a-z0-9]+)*$` after trimming.
     * Mirrors `plugin.schema.json#slug` for the parent plugin, where `slug`
     * is `required` and bound to the same pattern. Anything that fails this
     * regex is rejected as `SLUG_REASON_INVALID` so a stray `\\`, `..`, or
     * whitespace cannot reproduce the silent slug-mismatch 404 the fail-
     * loud contract exists to prevent.
     */
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const SLUG_REASON_MISSING = 'missing';

    private const SLUG_REASON_WRONG_TYPE = 'wrong_type';

    private const SLUG_REASON_INVALID = 'invalid';

    /**
     * @param  Filesystem|null  $filesystem  Optional filesystem implementation used for frontend copy and removal operations. Defaults to the parent's filesystem.
     */
    public function __construct(IOInterface $io, Composer $composer, ?Filesystem $filesystem = null)
    {
        parent::__construct($io, $composer);

        if ($filesystem !== null) {
            $this->filesystem = $filesystem;
        }
    }

    public function supports(string $packageType): bool
    {
        return $packageType === self::SPORA_PLUGIN_FRONTEND_TYPE;
    }

    /**
     * Install the package, then copy its bundled frontend into the public plugin directory.
     *
     * @throws InvalidArgumentException When a shipped frontend has no valid `extra.spora-plugin-slug`.
     */
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

    /**
     * Update the package, then replace its public frontend bundle in place.
     *
     * @throws InvalidArgumentException When the target package has no valid `extra.spora-plugin-slug`.
     */
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

    /**
     * Uninstall the package, then remove only its managed public frontend directory.
     *
     * Legacy installs (pre-`extra.spora-plugin-slug` contract) cannot resolve
     * the destination slug from composer.json; removal is skipped silently
     * so the operator can still tear the package down cleanly. Use the
     * slug source-of-truth path under `public/plugins/` to clean those up
     * manually if needed.
     *
     * @throws InvalidArgumentException When the package declares an invalid `extra.spora-plugin-slug`.
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $destination = $this->resolvePluginDestinationForUninstall($package);

        $promise = parent::uninstall($repo, $package);
        if (!$promise instanceof PromiseInterface) {
            $promise = \React\Promise\resolve(null);
        }

        return $promise->then(function () use ($destination): void {
            if ($destination !== null) {
                $this->removeDestinationSafely($destination);
            }
        });
    }

    /**
     * Delete the destination only when it looks like ours (i.e. inside
     * `public/plugins/`). Extracted from the uninstall() promise body so
     * the safety check + Filesystem::removeDirectory() call can be unit
     * tested directly without mocking the full Composer uninstall chain.
     */
    public function removeDestinationSafely(string $destination): void
    {
        if ($this->isManagedDestination($destination) && is_dir($destination)) {
            $this->filesystem->removeDirectory($destination);
        }
    }

    /**
     * Return the public runtime directory for the frontend package.
     *
     * The directory name comes exclusively from `composer.json#extra.spora-plugin-slug`
     * and must match the parent plugin's `plugin.json#slug`.
     *
     * @throws InvalidArgumentException When the slug is missing, empty, not a string, or does not match `^[a-z0-9]+(?:-[a-z0-9]+)*$`.
     */
    public function getPluginDestination(PackageInterface $package): string
    {
        return self::PUBLIC_DESTINATION_DIR.'/'.self::pluginSlug($package).'/';
    }

    /**
     * Copy a package's frontend directory into its declared public plugin destination.
     *
     * Packages without a `frontend/` directory are skipped. Existing
     * destination files are overwritten.
     *
     * @throws InvalidArgumentException When a shipped frontend has no valid `extra.spora-plugin-slug`.
     */
    public function copyFrontend(string $installPath, PackageInterface $package): void
    {
        $source = rtrim($installPath, '/').'/'.self::FRONTEND_SOURCE_DIR;

        // No `frontend/` directory in the package → no UI shipped.
        // Skip silently; plugins opt in to shipping UI without changing
        // their install path or Composer type.
        if (!is_dir($source)) {
            return;
        }

        $destination = $this->getPluginDestination($package);

        $this->filesystem->ensureDirectoryExists($destination);
        $this->filesystem->copy($source, $destination);
    }

    /**
     * Determine whether a destination is confined to the managed `public/plugins` tree and contains no traversal segments.
     */
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
     * Resolve the frontend's runtime slug from `composer.json#extra.spora-plugin-slug`.
     *
     * The value must match the parent plugin's `plugin.json#slug`; no
     * package-name fallback is used.
     *
     * @throws InvalidArgumentException When the slug is missing, empty, not a string, or does not match `^[a-z0-9]+(?:-[a-z0-9]+)*$`.
     */
    public static function pluginSlug(PackageInterface $package): string
    {
        $extra = $package->getExtra();
        $raw   = is_array($extra) ? ($extra['spora-plugin-slug'] ?? null) : null;

        if ($raw === null) {
            self::throwSlugError($package, null, self::SLUG_REASON_MISSING);
        }
        if (!is_string($raw)) {
            self::throwSlugError($package, null, self::SLUG_REASON_WRONG_TYPE);
        }

        $slug = trim($raw);
        if ($slug === '' || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            self::throwSlugError($package, $raw, self::SLUG_REASON_INVALID);
        }

        return $slug;
    }

    /**
     * Single throw site for every slug-validation failure. Centralised so
     * adding new validation rules doesn't multiply error templates.
     */
    private static function throwSlugError(PackageInterface $package, ?string $slug, string $reason): never
    {
        $message = match ($reason) {
            self::SLUG_REASON_MISSING => sprintf(
                "SporaPluginFrontendInstaller: package '%s' (type spora-plugin-frontend) is missing composer.json#extra.spora-plugin-slug. Declare '\"extra\": {\"spora-plugin-slug\": \"<parent-slug>\"}' in composer.json, where <parent-slug> is the slug from the parent PHP plugin's plugin.json#slug.",
                $package->getPrettyName(),
            ),
            self::SLUG_REASON_WRONG_TYPE => sprintf(
                "SporaPluginFrontendInstaller: package '%s' has composer.json#extra.spora-plugin-slug set to a non-string value. Declare it as a string in composer.json, where the value is the slug from the parent PHP plugin's plugin.json#slug.",
                $package->getPrettyName(),
            ),
            self::SLUG_REASON_INVALID => sprintf(
                "SporaPluginFrontendInstaller: package '%s' declared an invalid spora-plugin-slug '%s' (must match ^[a-z0-9]+(?:-[a-z0-9]+)*$ and may not contain '/', '\\\\', '..', or whitespace).",
                $package->getPrettyName(),
                (string) $slug,
            ),
        };

        throw new InvalidArgumentException($message);
    }

    /**
     * Resolve the destination for the uninstall path. Returns null for
     * legacy installs that pre-date the `extra.spora-plugin-slug` contract
     * so uninstall() can skip the filesystem cleanup without throwing.
     */
    private function resolvePluginDestinationForUninstall(PackageInterface $package): ?string
    {
        $extra = $package->getExtra();
        if (!is_array($extra)) {
            return null;
        }
        $raw = $extra['spora-plugin-slug'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return self::PUBLIC_DESTINATION_DIR.'/'.trim($raw).'/';
    }
}