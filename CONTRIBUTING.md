# Contributing

Thanks for helping improve PHPStan Doctrine Performance.

## Reporting a missed or false finding

Most issues are about a query pattern the rules miss or a finding that should
not be there. Please include:

- a minimal code snippet (entity mapping and the loop) that reproduces it,
- the finding you expected and the one you got, with its identifier
  (for example `doctrine.lazyAssociationInLoop`),
- your PHP, PHPStan, `phpstan/phpstan-doctrine` and Doctrine ORM versions.

## Development setup

```sh
git clone git@github.com:leopries/phpstan-doctrine-performance.git
cd phpstan-doctrine-performance
composer install
composer check    # PHPUnit and PHPStan self-analysis
```

## Pull requests

- Add a test for every behaviour change. Rule tests live in `tests/` and use
  fixture files that show the reported lines.
- Keep `composer check` green. PHPStan runs at level 8 with strict rules.
- Add an entry under `Unreleased` in [CHANGELOG.md](CHANGELOG.md).
- Keep one topic per pull request and describe why the change is needed.
