# Contributing to laravel-vector-schema

Thanks for taking the time to improve this package. Bug reports, focused fixes, tests, and documentation improvements are welcome.

## Development setup

1. Fork the repository and create a branch for your change.
2. Install dependencies with `composer install`.
3. Make the smallest change that addresses the issue and add or update tests.

## Checks

Run the package test suite before opening a pull request:

```sh
composer test
```

Run static analysis when available:

```sh
composer analyse
```

Check formatting with `./vendor/bin/pint --test`. If Pint reports changes, run `./vendor/bin/pint` locally and commit the result. Do not include API keys, credentials, or other secrets in commits or test fixtures.

## Pull requests

Explain the problem and the change, link related issues, and describe the checks you ran. Keep unrelated refactors in separate pull requests. Maintainers may request changes before merging.
