# spora/installer

Composer plugin for [Spora](https://github.com/spora-ai/spora-core). Routes
packages of type `spora-plugin` to `plugins/{$name}/` instead of Composer's
default `vendor/` install location.

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

## License

MIT.