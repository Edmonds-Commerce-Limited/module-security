# EdmondsCommerce_Security

## Running tests

Tests run inside the ddev sandbox at `~/projects/magento2-testing` (project `m2sec`). A standalone
checkout has no Magento integration framework and no real DI, so the sandbox is the source of truth.

### How the module gets there

- The sandbox requires `edmondscommerce/module-security:@dev` from a composer path repository
  (`../magento2-security`), so `vendor/edmondscommerce/module-security` is a symlink to this repo.
- `.ddev/docker-compose.module.yaml` mounts this repo at `/var/www/magento2-security` so that
  symlink resolves inside the web container. Host edits are live; there is nothing to copy.
- This repo's own `vendor/` is tmpfs-masked in the container. Always use the sandbox's
  `vendor/bin/*`, never this repo's.
- Composer snapshots the module's `autoload` into the sandbox's `vendor/composer/installed.json`.
  After changing `composer.json` autoload (or moving `registration.php`), run
  `ddev composer update edmondscommerce/module-security` in the sandbox; `dump-autoload` is not enough.

### Commands

Run everything from the sandbox root, e.g. `cd ~/projects/magento2-testing && ddev ...`. Start it
with `ddev start` if `ddev describe` shows the services as exited.

| What | Command |
| --- | --- |
| Unit tests | `ddev exec -d /var/www/html/dev/tests/unit php ../../../vendor/bin/phpunit ../../../vendor/edmondscommerce/module-security/tests/Unit` |
| Integration tests | `ddev m2-integration [<phpunit args>]` |
| Integration tests, fresh DB | `ddev m2-integration --clean` |
| DI compile | `ddev m2-compile` |

- Unit tests run from `dev/tests/unit` so core's config supplies the bootstrap; the path argument
  limits the run to this module.
- Integration tests use `dev/tests/integration/phpunit.edmondscommerce.xml`, a copy of
  `tests/Integration/phpunit.edmondscommerce.xml`. Re-copy it after editing the original:
  `cp tests/Integration/phpunit.edmondscommerce.xml ~/projects/magento2-testing/dev/tests/integration/`
- Use `--clean` on the first run, after changing `etc/` schema or data patches, or when the test DB
  is in a bad state. It reinstalls Magento and takes minutes.
- `m2-integration` deletes `generated/code` and `generated/metadata`, so run `m2-compile` after it,
  not before, if you need both.
- Run `m2-compile` after any change to `di.xml` or plugins; it is the fastest DI sanity check.
- Never have the module in both `vendor/` and `app/code/`: duplicate registration stops Magento booting.

### Static analysis

Run phpcs, phpstan and rector from this repo only, never in the sandbox. Commands match
`.github/workflows/main.yml`:

```
vendor/bin/phpunit
vendor/bin/phpcs
vendor/bin/phpstan analyse --no-progress --memory-limit=-1
vendor/bin/rector process --dry-run --no-progress-bar
```

`vendor/bin/phpunit` here runs unit tests only. Integration tests need the sandbox.
