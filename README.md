# PriceWatch

Competitor price monitoring for Thelia 3. Collects competitor offers, matches
them to catalogue variants, and reports price gaps in the back-office.

Matching is deterministic wherever the code can see the answer — GTIN equality,
price gap, currency — and only the narrow semantic questions go to typed Jev
judgments: is it the same article, the same variant, is this price comparable.

Requires Thelia 3.0.0 and PHP 8.3.

## Install

From the project root:

```bash
ddev exec php Thelia module:generate:model PriceWatch --generate-sql
ddev exec php Thelia module:schema:apply PriceWatch --dry-run   # read before applying
ddev exec php Thelia module:schema:apply PriceWatch
ddev exec php Thelia module:refresh
ddev exec php Thelia module:activate PriceWatch
ddev exec php Thelia module:post-activate-all                   # Model classes exist only after this
ddev exec php Thelia thelia:cache:clear
```

Secrets go in `.env.local`, never in the repository and never in the database:
`DATAFORSEO_USERNAME`, `DATAFORSEO_PASSWORD`, `TYPESAFE_API_KEY`.

## Tests

```bash
# from the project root
ddev exec php -d auto_prepend_file=/var/www/html/bootstrap.php \
  vendor/bin/phpunit -c local/modules/PriceWatch/phpunit.xml
```

`auto_prepend_file` is not optional. `vendor/thelia/core/bootstrap.php` is
listed in Composer's `autoload.files`, so it runs the moment
`vendor/autoload.php` is loaded — before any PHPUnit bootstrap — and it defines
`THELIA_ROOT` as `vendor/thelia/`. Pre-pending the project's own
`bootstrap.php` wins the race and pins the constants to the project root.
Without it, Propel looks for the schema in `vendor/thelia/vendor/thelia/config`
and the kernel refuses to boot.

The unit suite needs neither a database nor a network. The integration suite
boots the development kernel and wraps every test in a transaction rolled back
afterwards; the development database comes out unchanged, which is checked by
comparing row counts around the run.

**Never run `bin/test-prepare` against this install.** It calls
`createDatabase()` on `DATABASE_NAME`, which here is the development database.

### Why not `Thelia\Test\IntegrationTestCase`

It extends `KernelTestCase`, which needs `framework.test: true`, which only
exists under `APP_ENV=test`. On this install that environment has neither its
Propel models generated (`var/propel/test`) nor the core console commands.
`Tests/Integration/PriceWatchIntegrationTestCase` keeps the part that matters —
one transaction per test, `forceRollBack` in `tearDown`, instance pooling off —
and boots the development kernel instead. Module services are final classes
with explicit constructor injection, so tests build them directly and never
need a container.

## Traps worth knowing before touching the code

- `TINYINT` columns get `?int` setters: `setGtinValid(1)`, never `true`.
- `DECIMAL` columns get `?string` setters: `setTargetPrice('349.000000')`,
  never a float.
- `_or()` rewrites the whole `WHERE`. Group with `condition()` + `combine()`,
  or filter with `Criteria::IN`.
- `filterById([], Criteria::NOT_IN)` on an empty array returns every row.
- Auto-increment values are not rolled back: never hardcode an id in a test.
- A hook absent from the `hook` table is dropped silently, with no error and no
  output. Only subscribe to hooks whose row exists in `local/setup/insert.sql`.
- In PHP, always pass the domain: `trans('X', [], 'pricewatch')`. The injected
  translator defaults to the `core` domain and falls back to the raw key, so a
  missing translation shows up as the key and never raises.
