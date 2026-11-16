# PHPStan Doctrine Performance

PHPStan rules that find Doctrine N+1 and N×M query patterns without running the
application, rank every finding by severity and group the findings by cause.

```
 85 critical OrderRepository::save → flush()
             9 loops, query at src/Repository/OrderRepository.php:46
             85 = 60 flush per iteration recomputes the whole unit of work + 25 nested loops multiply the count
              85 src/Import/OrderImport.php:64
              85 src/Import/InvoiceImport.php:47
                 … 7 more, -v lists all
```

It needs PHPStan and Doctrine ORM, nothing else: no Symfony, no database, no
test run.

## Installation

```sh
composer require --dev leopries/phpstan-doctrine-performance
```

Requires PHP 8.4, PHPStan 2.1, `phpstan/phpstan-doctrine` 2 and Doctrine ORM 3.
With [phpstan/extension-installer](https://github.com/phpstan/extension-installer)
the extension is registered automatically. Otherwise include it:

```neon
includes:
    - vendor/phpstan/phpstan-doctrine/extension.neon
    - vendor/leopries/phpstan-doctrine-performance/extension.neon
```

The findings then appear in the regular `vendor/bin/phpstan analyse` run. For
accurate mapping information configure `phpstan-doctrine`'s
[`objectManagerLoader`](https://github.com/phpstan/phpstan-doctrine#configuration).

## What it reports

- `doctrine.queryInLoop`: Doctrine repository `find*`, `count` and `matching`
  calls, ORM query execution, entity manager reads and flushes, and DBAL query,
  fetch and write calls inside a loop.
- `doctrine.queryInNestedLoop`: the same inside nested loops (N×M).
- `doctrine.queryInBoundedLoop`: queries in `foreach` loops over an array whose
  size is statically known. Fixed cost, reported at `info` level.
- `doctrine.indirectQueryInLoop`: a call inside a loop to a project method that
  runs a query somewhere down its call chain, for example a repository service
  whose `save()` flushes. Finder methods written in your own repositories
  (`findByTeam()`) are followed into their body like any other project method.
  Calls through interfaces reach the implementations found in the analysed
  code, up to `maxImplementations`.
- `doctrine.lazyAssociationInLoop`: a lazy or extra-lazy association read
  through a property or a direct getter (`return $this->property;`) inside a
  loop that changes the owner.
- `doctrine.indirectLazyAssociationInLoop`: a call inside a loop that passes a
  loop-dependent entity to a project method which reads a lazy association of
  that parameter, directly or up to four calls further down (mappers, static
  factories). Whether the argument was fetch-joined is only known inside the
  calling method, so these findings score lower.

`foreach`, `for`, `while`, `do … while` and callbacks passed directly to
`array_map`, `array_filter`, `usort`, `Collection::map()` and similar count as
loops.

### What it deliberately does not report

- Files matching `testPaths`, unless `reportTests` is `true`.
- Batch loops: `while (true)`, `for (;;)` and `foreach` over `array_chunk()`.
- Reads served by a Doctrine cache, directly or down the call chain:
  - ORM queries with the result cache (`enableResultCache()`,
    `setResultCacheLifetime()`, `setResultCacheId()`, `setResultCacheProfile()`,
    `setResultCache()`) or the second-level query cache (`setCacheable(true)` on
    the query or its query builder), in the same method chain or on a local
    variable before execution. `disableResultCache()` and `setCacheable(false)`
    switch it off again.
  - `find()`, `findAll()`, `findBy()`, `findOneBy()` (including magic finders)
    and `EntityManager::find()` for entities mapped with `#[ORM\Cache]`.
  - DBAL `executeCacheQuery()`, `executeQuery()` with a `QueryCacheProfile` and
    query builders after `enableResultCache()`.
- A query behind a boolean parameter the caller switches off, as in
  `save($entity, false)` for `if ($flush) { ... }`.
- A lazy association whose owner does not change in the loop.
- An association that is fetch-joined (join and selected alias in the same
  query builder chain, followed through repository and service methods), mapped
  as eager, only compared with `===`, returned or stored, or of which only the
  identifier is read.
- The same association of the same variable a second time in one iteration,
  directly or through a call.
- An association a later `foreach` over the same, unchanged array reads again:
  the first loop already loaded it for the same entities.
- An association the code puts into the entity itself: entities created with
  `new`, and associations assigned directly or through a setter earlier in the
  same iteration. The assigned value must not change in the loop
  (`$order->setCustomer($customer)` with a fixed `$customer`), or be a `new`
  object or `null`. A value that changes every iteration, such as
  `getReference()`, can be a different proxy every time and is still reported.
- An association read behind a condition on the entity's state that the code
  already decided: the state is set to a constant (`setState(State::Invited)`,
  also in a fluent chain, or `$entity->state = …`) before the condition, in
  the same method or right before calling the method that holds it. Recognized
  conditions compare a getter or property with constants through `===`, `!==`,
  `in_array()` or a `match` that yields booleans, directly or through a local
  variable, and guard the read with `if` or an early `return`/`continue`.
  A method that changes the state itself before its condition, or a state set
  only behind a condition, decides nothing.

## Severity

Every finding gets a score from 0 to 100 and a level (`info` < 20 ≤ `low` < 40 ≤
`medium` < 60 ≤ `high` < 80 ≤ `critical`). The score ranks findings against each
other; it is not a query count. Each finding shows how its score adds up in the
tip, for example `Severity high (75/100) = 50 one query per iteration + 25
nested loops multiply the count`.

| Part | Points |
| --- | --- |
| `flush()` per iteration (every flush recomputes the unit of work) | 60 |
| explicit read query per iteration | 50 |
| write statement or lazy to-many collection per iteration | 45 |
| lazy to-one association (identity map serves repeated targets) | 30 |
| statically bounded loop | 10 |
| nested loops | +25 |
| file matches `hotPaths` | +10 |
| target table has at least `largeTableRows` rows | +10 |
| query sits behind an `if`, a ternary or a `catch` | −10 |
| read query that does not depend on the loop (run it once before the loop) | −10 |
| lazy load inside a called method (fetch state unknown across the call) | −10 |
| depends on which implementation of an interface runs | −10 |
| target table has at most `smallTableRows` rows | −25 for to-one, else −10 |
| file matches `testPaths` (only with `reportTests`) | −40 |

## Configuration

All parameters are optional; these are the defaults.

```neon
parameters:
    doctrinePerformance:
        minimumSeverity: info      # info, low, medium, high or critical
        severityTip: true
        baselineTip: true          # name the accept command in every tip
        tableStatsFile: null       # see "Table statistics"
        smallTableRows: 200
        largeTableRows: 100000
        hotPaths:                  # regular expressions on the file path
            - '#/Controller/#'
        testPaths:
            - '#/[Tt]ests?/#'
        reportTests: false
        ignoredMethods: []         # Class::method known not to query per call
        maxImplementations: 5
```

List parameters are merged with the defaults; write `hotPaths!:` to replace them.

## The report

The regular PHPStan output lists every loop. Many loops usually share one cause:
a repository `save()` that flushes is one problem, however many loops call it.
The report format groups by cause and lists the causes worst first:

```sh
vendor/bin/phpstan analyse --error-format=doctrinePerformance        # add -v for every loop
vendor/bin/phpstan analyse --error-format=doctrinePerformanceJson > doctrine-report.json
vendor/bin/phpstan analyse --error-format=doctrinePerformanceMarkdown
```

The report formats show only Doctrine findings and always exit with 0: they are
a report, the regular run is the gate. Environment variables narrow them
(PHPStan error formatters cannot take command line options):

| Variable | Effect |
| --- | --- |
| `DOCTRINE_REPORT_IDENTIFIER` | only findings with the given identifier; several separated by commas, the `doctrine.` prefix is optional |
| `DOCTRINE_REPORT_MIN_LEVEL` | only causes at or above the given level |
| `DOCTRINE_REPORT_TOP` | only the worst N causes; `0` means no limit |

```sh
DOCTRINE_REPORT_IDENTIFIER=indirectLazyAssociationInLoop vendor/bin/phpstan analyse --error-format=doctrinePerformance
```

The identifier filter works per finding: a cause that is reached both directly
and through calls shows only the loops with the requested identifier, and the
totals count only those. The level and top filters then apply to what is left.
The last line always states the totals, how many findings the identifier filter
left out and how many causes are hidden. An invalid value is ignored with a
warning, so a typo never makes the report look clean. The filter only affects
the report formats; the regular output and `doctrineBaseline` always cover every
finding. The JSON output has a stable order, so two reports can be diffed; the
Markdown output (top 20 by default) fits a pull request comment.

## Accepting all current findings

To accept the current findings, so only new ones fail the build:

```sh
vendor/bin/phpstan analyse --error-format=doctrineBaseline
```

It writes only the Doctrine findings to `phpstan-doctrine-baseline.php`, apart
from your regular PHPStan baseline, and tells you to include the file once:

```neon
includes:
    - phpstan-doctrine-baseline.php
```

Run the same command again after new findings appear: once the file is
included, the accepted findings no longer show up, so it adds only the new ones.
While the file is not included, it is written from scratch, so counts never
double. Pass your usual `-c` and paths; `DOCTRINE_BASELINE_FILE=path` picks
another file. The tip of every finding and the reports name the command;
`baselineTip: false` removes it from the tips.

## Introducing it to an existing project

1. Accept the existing findings, so only new findings fail the build:
   `vendor/bin/phpstan analyse --error-format=doctrineBaseline` (see above).
   `--generate-baseline` works too, but also baselines every other PHPStan error.
2. The message of a finding names only the call and the loop. The call chain and
   the score are in the tip, which a baseline does not match, so refactoring
   further down the chain leaves the baseline intact.
3. To keep seeing baselined findings in the report, run the report with a
   config that includes your main config and resets the ignores:

   ```neon
   includes:
       - phpstan.neon
   parameters:
       tmpDir: var/phpstan-doctrine-report   # own cache
       reportUnmatchedIgnoredErrors: false
       ignoreErrors!: []
   ```

## Accepting a finding

- All current findings: `vendor/bin/phpstan analyse --error-format=doctrineBaseline`,
  see [Accepting all current findings](#accepting-all-current-findings).
- One place: `// @phpstan-ignore doctrine.indirectQueryInLoop (at most 4 warehouses)`
  with the reason in parentheses.
- A method that never queries per call, such as a lookup cached outside
  Doctrine (Symfony Cache, memoization in a property): `ignoredMethods`.
  Doctrine's own caches are recognized without configuration.

PHPStan reports ignores and baseline entries that no longer match, so accepted
findings do not outlive the code they were written for.

## Table statistics

Whether a pattern hurts depends on table sizes. `vendor/bin/doctrine-table-stats`
writes approximate row counts per table as JSON. It reads the engine's own
statistics (MySQL, MariaDB, PostgreSQL) without counting rows and rounds to two
significant digits, so the file only changes when a table changes in magnitude.
Run it against production or a replica, commit the file and point
`tableStatsFile` at it; the analysis itself never needs a database.

```sh
DATABASE_URL=mysql://user:pass@host:3306/db vendor/bin/doctrine-table-stats > doctrine-table-stats.json
```

```neon
parameters:
    doctrinePerformance:
        tableStatsFile: %currentWorkingDirectory%/doctrine-table-stats.json
```

## Limitations

These are potential performance findings, not proof of SQL counts.

- The number of iterations is unknown; a finding in a loop over three items is
  reported like one over three million. Table statistics and `ignoredMethods`
  help, a runtime profiler settles it.
- Templates (Twig), serializers and anything else that reads associations
  outside PHP code PHPStan analyses are invisible.
- Calls on entities, vendor code and dynamic calls are not followed.
  `new Row($entity)` in a loop and first-class callables (`$mapper->map(...)`)
  are not followed for lazy associations.
- Caches outside Doctrine and entities built in memory look like queries and
  proxies. Doctrine cache settings are followed through method chains and local
  variables, not through properties or other methods; whether a cache region is
  configured is not checked, `#[ORM\Cache]` or `setCacheable(true)` counts as
  cached. Lazy associations to second-level cached entities are still reported.
- Fetch joins are recognized in query builder chains, not in DQL strings.
- The association rules read getter and repository bodies of other files, which
  PHPStan's result cache does not track. After changing a fetch join, run
  `vendor/bin/phpstan clear-result-cache` if a finding looks stale.

## Development

```sh
composer install
composer check    # tests and self-analysis
```
