# PriceWatch

Competitor price monitoring for Thelia 3. Collects competitor offers, matches
them to catalogue variants, and reports price gaps in the back-office.

Matching is deterministic wherever the code can see the answer — GTIN equality,
price gap, currency — and only the narrow semantic questions go to typed Jev
judgments: is it the same article, the same variant, is this price comparable.

A price far from ours is a doubt, never a verdict: from a 25 % gap a match
Jev alone made waits for a human, and beyond 70 % the offer is still checked
(another article is rejected on Jev's word, the same article goes to a human).
A match proven by a GTIN only goes to a human beyond 70 %.

A product whose sizes we all sell at one price is one comparison: the collect
pass searches one of its variants (the limit counts searches), the other sizes
ride on the card it leads to, a "same article?" verdict is asked once for sizes
read on the same listing at the same price, and the comparator shows them on
one line. A size is never given a price it was not read at.

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

**Never run a statement that commits implicitly in a test** (`ANALYZE TABLE`,
`OPTIMIZE`, any DDL). It ends the transaction behind Propel's back and
everything the test wrote stays in the development database. The base class
writes a sentinel row at the start of each test and fails the test if the row
survives the rollback, so such a test cannot go unnoticed, but the rows it
wrote still have to be removed by hand.

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
- Never name a constructor argument `$environment`, `$env`, `$debug`,
  `$debugMode`, `$cacheDir`, `$kernelCacheDir`, `$kernelDebug`,
  `$kernelEnvironment`, `$sessionSavePath`, `$formDefinition` or
  `$apiResourceAddons`: the core binds those names globally
  (`vendor/thelia/core/lib/Thelia/Config/Resources/services.php:43-56`), the
  binding applies to module services too, and it wins over the type hint. A
  `ServerEnvironment $environment` argument receives the string `dev`.
- In PHP, always pass the domain: `trans('X', [], 'pricewatch')`. The injected
  translator defaults to the `core` domain and falls back to the raw key, so a
  missing translation shows up as the key and never raises.
- Never run `thelia:cache:clear` while a pass runs: it removes the code under
  the running process, which dies without closing its row. The next pass of
  the same kind closes such rows as failed ("Interrupted"), once it holds the
  lock.
- PHPUnit's `TestCase::run()` is final: never name a test helper `run()`.

## Direct reading of competitor pages

`pricewatch:direct` reads prices straight from the competitor product pages
tied to our products (`pw_competitor_page`): free, a few seconds a page, every
size at once. It records exact matches only:

- one of our GTINs on the page (JSON-LD `gtin*` or a GTIN-shaped `sku`): that
  variant, and the page is confirmed;
- otherwise, on a page confirmed by hand, a size stated once, in a single
  colour, on a page that lists no GTIN at all.

A page listing GTINs that are not ours is rejected. Anything uncertain (no
price in euros, structured data that disagree, data naming another page after
a redirect) records nothing. Extractors: JSON-LD, and the `__NUXT_DATA__`
state, restricted to the object whose `url` is the page (the state also holds
the recommended products). The fetcher announces itself as `PriceWatch`,
honours robots.txt and leaves each site 2 s between two pages.

```bash
ddev exec php Thelia pricewatch:page seed        # candidates from the merchant pages Google led to
ddev exec php Thelia pricewatch:page add --competitor=motoblouz.com --product=REF --url=https://... [--confirm]
ddev exec php Thelia pricewatch:page list
ddev exec php Thelia pricewatch:page confirm --id=71
ddev exec php Thelia pricewatch:direct [--product=REF] [--competitor=domain]
```

A variant read directly steps the Google Shopping offer of the same pair aside,
and the collect pass no longer records that pair from Google.

## Logs

- `var/log/pricewatch.log`: what each collect pass did and why it stopped
  (searches posted, waits, DataForSEO errors, summary line).
- `var/log/pricewatch-manual.log`: the console output of passes started from
  the back office.

While a pass runs, each step writes where it stands on its `pw_collect_run`
row (`progress_step`, `progress_done`, `progress_total`); the back office polls
`/admin/module/pricewatch/run/progress` to draw the progress bar.
