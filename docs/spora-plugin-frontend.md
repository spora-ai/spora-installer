# Spora plugin frontend installer

Companion to [`SporaPluginFrontendInstaller`](../src/Composer/SporaPluginFrontendInstaller.php). Routes `spora-plugin-frontend` Composer packages into the operator's `public/plugins/<slug>/` tree at install time, so the host SPA can lazy-load `/plugins/<slug>/main.js` without any extra `/dist/` nesting.

## Operator cheat sheet

A frontend package must declare which parent PHP plugin it pairs with:

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
- The installer **fails loud** (`InvalidArgumentException`) if the field is missing, empty, or not a string.
- Slugs must match `^[a-z0-9]+(?:-[a-z0-9]+)*$` after trimming — `/`, `\`, `..`, whitespace, uppercase, and underscores are rejected as invalid.
- There is **no fallback** to the package short name. Silent fallback produced a runtime 404 in the past when the parent plugin's slug (`media-archive`) differed from the frontend package's short name (`spora-plugin-media-archive-frontend`).
- On uninstall, the entire `public/plugins/<slug>/` directory is removed if the slug is still resolvable. Legacy installs (pre-this-contract) are skipped silently so removal still succeeds.

## Author workflow

1. Add `"type": "spora-plugin-frontend"` to the frontend package's `composer.json`.
2. Add `"extra": { "spora-plugin-slug": "<matches parent plugin.json#slug>" }`.
3. Ship a `frontend/` directory containing `main.js` (and any sibling assets).
4. Tag and publish the frontend before the PHP plugin's CI can resolve the require — see the [Author guide → Publishing sequencing](https://spora.ai/develop/plugins/author-guide/admin-ui#publishing-sequencing).

## Error messages

The installer surfaces actionable error strings so operators can pattern-match without leaving the terminal:

```text
SporaPluginFrontendInstaller: package 'spora-ai/spora-plugin-foo-frontend' (type
spora-plugin-frontend) is missing composer.json#extra.spora-plugin-slug.
Declare '"extra": {"spora-plugin-slug": "<parent-slug>"}' in composer.json,
where <parent-slug> is the slug from the parent PHP plugin's plugin.json#slug.
```

```text
SporaPluginFrontendInstaller: package 'spora-ai/spora-plugin-foo-frontend'
declared an invalid spora-plugin-slug '../etc' (must match
^[a-z0-9]+(?:-[a-z0-9]+)*$ and may not contain '/', '\\', '..', or whitespace).
```

## See also

- [`SporaPluginFrontendInstaller`](../src/Composer/SporaPluginFrontendInstaller.php) — runtime.
- [Author guide → Admin UI](https://spora.ai/develop/plugins/author-guide/admin-ui#declaring-the-install-destination-slug) — the operator-facing docs.
- [`spora-plugin-media-archive-frontend`](https://github.com/spora-ai/spora-plugin-media-archive-frontend) — canonical worked example.