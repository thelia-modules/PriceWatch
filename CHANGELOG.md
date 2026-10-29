# Changelog

## 0.16.0

### Upgrading from `classicride/price-watch`

The Composer package is now `thelia/price-watch-module`. The module code
(`PriceWatch`), its tables and its settings do not change: no schema command is
needed. On a shop that has the old package:

```bash
ddev composer remove classicride/price-watch
ddev composer require thelia/price-watch-module
ddev exec php Thelia module:refresh
ddev exec php Thelia thelia:cache:clear
```

If the old package came from a `repositories` entry of `composer.json`, point
that entry at the new repository first. A copy in `local/modules/PriceWatch`
is replaced by the new files, followed by the last two commands.

The module requires Thelia 3.3.0. Passes started from the back-office need a
worker (`messenger:consume async_heavy`) or, without one, the hourly
`pricewatch:scheduled` cron line. The settings of the language model and of the
competitor discovery are removed; their rows in `module_config` are ignored.

### Added

- The Jev and DataForSEO calls are declared as AI usages and their costs are
  written to the AI usage log; a pass stops when the budget of the usage is
  reached.
- Passes started from the back-office run on the `async_heavy` Messenger
  transport. Without a worker the request is recorded and run by
  `pricewatch:scheduled`; the screen shows its state and, when the task has not
  run for 2 hours, the cron line to add.
- A match only Jev vouches for needs the GTINs of both sides to agree: without
  a GTIN it waits for a human (`GTIN_UNVERIFIED`), with a different one it is
  `GTIN_MISMATCH`.
- Every Jev question carries the "untrusted retailer copy" clause.
- Competitors are proposed from the sellers Google Shopping shows for the
  watched products.

### Changed

- Package name `thelia/price-watch-module`, LGPL-3.0-or-later licence file,
  Thelia 3.3.0 required.
- Tests needing the purchase table or the Statistic module are skipped when
  they are absent.

### Removed

- The detached shell (`nohup`) that ran the passes.
- The OpenRouter language model for competitor discovery, and its settings.
