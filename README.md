# spora/installer

Composer plugin for [Spora](https://github.com/spora-ai/spora-core). Routes
two Composer package types:

- `spora-plugin` → `plugins/{$name}/`
- `spora-plugin-frontend` → `public/plugins/{$slug}/`

…instead of Composer's default `vendor/` install location.

You don't install this directly — it ships as a transitive dependency of
[`spora-ai/spora-core`](https://packagist.org/packages/spora-ai/spora-core).
The Spora host application activates it automatically the first time
Composer runs in a project that depends on `spora-ai/spora-core`.

## Usage

```bash
composer require spora-ai/spora-core
# Any package with type "spora-plugin" now installs to plugins/<name>/
# instead of vendor/<vendor>/<name>/.
```

This is the package-type router that powers
[Spora's plugin system](https://github.com/spora-ai/spora-core/blob/main/docs/07_plugins.md).

## Frontend bundles (`spora-plugin-frontend`)

A second installer routes `spora-plugin-frontend` packages into
`public/plugins/<slug>/` (the directory the host SPA lazy-loads
`/plugins/<slug>/main.js` from at runtime). The destination `<slug>` is
**not** derived from the package short name — that previously produced a
runtime 404 when the parent PHP plugin's `plugin.json#slug` (e.g. `foo`)
differed from the frontend package's short name (e.g.
`spora-plugin-foo-frontend`).

The frontend package declares the slug explicitly in `composer.json`:

```json
{
  "name": "spora-ai/spora-plugin-foo-frontend",
  "type": "spora-plugin-frontend",
  "extra": {
    "spora-plugin-slug": "foo"
  }
}
```

Rules:

- `extra.spora-plugin-slug` MUST equal the parent PHP plugin's `plugin.json#slug`.
- The installer **fails loud** (`InvalidArgumentException`) when the field is missing, empty, or not a string. There is no fallback to the package short name.
- Slugs must match `^[a-z0-9]+(?:-[a-z0-9]+)*$` after trimming — `/`, `\`, `..`, whitespace, uppercase, and underscores are rejected as path-traversal protection under the public web root.

See [docs/spora-plugin-frontend.md](docs/spora-plugin-frontend.md) for the full contract, error messages, and the
[Spora docs → Declaring the install destination slug](https://spora.ai/develop/plugins/author-guide/admin-ui#declaring-the-install-destination-slug) for the operator-facing guide.

## License

MIT.