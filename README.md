# xhprof for PHP 7.2–8.5

[![Tests](https://github.com/wpelevator/xhprof/actions/workflows/tests.yml/badge.svg)](https://github.com/wpelevator/xhprof/actions/workflows/tests.yml)
[![Windows](https://github.com/wpelevator/xhprof/actions/workflows/windows.yml/badge.svg)](https://github.com/wpelevator/xhprof/actions/workflows/windows.yml)

XHProf is a function-level hierarchical profiler for PHP and has a simple HTML based navigational interface. The raw data collection component is implemented in C (as a PHP extension). The reporting/UI layer is all in PHP. It is capable of reporting function-level inclusive and exclusive wall times, memory usage, CPU times and number of calls for each function. Additionally, it supports ability to compare two runs (hierarchical DIFF reports), or aggregate results from multiple runs.

This is a maintained fork of [longxinH/xhprof](https://github.com/longxinH/xhprof), published as the PIE package `wpelevator/xhprof`. Fixes are sent upstream as pull requests too.

# PHP Version
PHP 7.2 to 8.5, NTS and ZTS, on Linux, macOS and Windows. CI tests 8.2–8.5 (NTS and ZTS, plus 8.5 with opcache) and 7.2–8.1 (NTS) on Linux, and builds and tests the Windows DLLs.

# Installation

### PIE

Install with [PIE](https://github.com/php/pie), which builds the extension from source on Linux and macOS and downloads a prebuilt DLL on Windows:

```
pie install wpelevator/xhprof
```

### From source
```
git clone https://github.com/wpelevator/xhprof.git ./xhprof
cd xhprof/extension/
/path/to/php/bin/phpize
./configure --with-php-config=/path/to/php/bin/php-config
make && sudo make install
```

#### configuration add to your php.ini
```
[xhprof]
extension = xhprof.so
xhprof.output_dir = /tmp/xhprof
```

### php.ini configuration
|      Options        |  Defaults  |  Version  |  Explain  |
| --------------- |:-------------:|:-------------:|:---------|
|xhprof.output_dir  | "" | All |Output directory|
|xhprof.sampling_interval  | 100000 | >= v2.* | Sampling interval to be used by the sampling profiler, in microseconds|
|xhprof.sampling_depth  | INT_MAX | >= v2.* | Depth to trace call-chain by the sampling profiler|
|xhprof.collect_additional_info  | 0 | >= v2.1 | Collect mysql_query, curl_exec internal info. The default is 0. Open value is 1|

# Turn on extra collection
#### php.ini adds xhprof.collect_additional_info
```sh
xhprof.collect_additional_info = 1
````
# Options
```php
xhprof_enable(XHPROF_FLAGS_NO_BUILTINS | XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
```
- `XHPROF_FLAGS_NO_BUILTINS` do not profile builtins
- `XHPROF_FLAGS_CPU` gather CPU times for funcs
- `XHPROF_FLAGS_MEMORY` gather memory usage for funcs

Example
```php
<?php

array(
    "main()" => array(
        "wt" => 237,
        "ct" => 1,
        "cpu" => 100,
    )
)
```

- `wt` The execution time of the function method is time consuming
- `ct` The number of times the function was called
- `cpu` The CPU time consumed by the function method execution
- `mu` Memory used by function methods. The call is zend_memory_usage to get the memory usage
- `pmu` Peak memory used by the function method. The call is zend_memory_peak_usage to get the memory

### PDO::exec
### PDO::query
### mysqli_query
```php
$mysqli = new mysqli("localhost", "my_user", "my_password", "user");
$result = $mysqli->query("SELECT * FROM user LIMIT 10");
```
##### Output data
```
mysqli::query#SELECT * FROM user LIMIT 10
```

### PDO::prepare
Convert preprocessing placeholders for actual parameters, more intuitive analytic performance (does not change the zend execution process)
```php
$_sth = $db->prepare("SELECT * FROM user where userid = :id and username = :name");
$_sth->execute([':id' => '1', ':name' => 'admin']);
$data1 = $_sth->fetch();

$_sth = $db->prepare("SELECT * FROM user where userid = ?");
$_sth->execute([1]);
$data2 = $_sth->fetch();
```
##### Output data
```
PDOStatement::execute#SELECT * FROM user where userid = 1 and username = admin
PDOStatement::execute#SELECT * FROM user where userid = 1
```

### Curl
```php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://www.baidu.com");
$output = curl_exec($ch);
curl_close($ch);
```
##### Output data
```
curl_exec#http://www.baidu.com
```

## PECL Repository
The PECL package is published from upstream [longxinH/xhprof](https://github.com/longxinH/xhprof).

[![pecl](resource/pecl.png)](https://pecl.php.net/package/xhprof)

# Run files

`XHProfRuns_Default::save_run()` stores each run as JSON in `<run id>.<namespace>.xhprof`. Older versions wrote PHP `serialize()` output; those files are still read, but without allowing any class to be instantiated, so a run file that someone else can write to cannot inject objects. Runs written now cannot be read by an older xhprof UI.

# Profiling a share of production requests

`xhprof_production_start()` profiles a random share of requests and saves each run when the request ends. It never throws and never prints: a missing extension, an unwritable output directory or a failed save is written to the error log and the request carries on. It does not start profiling at all when the run could not be saved.

```php
require 'xhprof_lib/utils/xhprof_lib.php';
require 'xhprof_lib/utils/xhprof_runs.php';
require 'xhprof_lib/utils/xhprof_production.php';

xhprof_production_start(array(
    'sample_rate' => 0.01,          // 1 request in 100
    'dir'         => '/var/xhprof', // or xhprof.output_dir / XHPROF_OUTPUT_DIR
    'namespace'   => 'myapp',
    'flags'       => XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY,
    'max_depth'   => 200,
    'max_calls'   => 2000000,
));
```

Call it as early as possible in the request. The run is saved from a shutdown function, so code that runs after the shutdown functions, such as destructors, is not part of the profile. With PHP-FPM the response is sent first (`fastcgi_finish_request()`), unless you pass `'finish_request' => false`. Pass `'force' => true` to profile a request regardless of `sample_rate`, for example when it carries a trusted header. See the comments in `xhprof_lib/utils/xhprof_production.php` for all options.

`max_depth` and `max_calls` are also options of `xhprof_enable($flags, array('max_depth' => 200, 'max_calls' => 2000000))`. Calls nested deeper than `max_depth`, and calls after the first `max_calls`, run normally but are not recorded. This keeps the result small for deep recursion (each recursion level is a separate entry in the profile). It does not remove the cost of the profiler hooks, so use `sample_rate` to limit overhead. Recorded calls still add up correctly; time spent in unrecorded calls is counted in the function that made them.

# Flame graph export

`xhprof_lib/utils/xhprof_export.php` turns the data from `xhprof_disable()` into formats that flame graph viewers read:

```php
require 'xhprof_lib/utils/xhprof_export.php';

$data = xhprof_disable();

// "main();foo;bar 1234" per line, for flamegraph.pl and inferno.
file_put_contents('profile.folded', xhprof_export_collapsed($data));

// Open in https://www.speedscope.app
file_put_contents('profile.speedscope.json', xhprof_export_speedscope($data, 'wt', 'GET /'));
```

The second argument is the metric: `wt` (default), `cpu`, `mu`, `pmu` or `ct`. XHProf only keeps one row per `parent==>child` pair, not whole call stacks, so the stacks are rebuilt from those pairs. Totals are exact. When a function has several callers, the time spent below it is split between them in proportion to the time each caller spent in it, so deep stacks below shared functions are an estimate. It is a flame graph of the aggregated data, not a timeline.

# Overhead

Measured with `make bench` (`tools/bench/`) on PHP 8.5.12 NTS in Docker on an Apple Silicon Mac, no opcache: about 115-145 ns per call with the default flags, about 180-205 ns with `XHPROF_FLAGS_MEMORY`, and about 650-750 ns with `XHPROF_FLAGS_CPU`. The CPU flag costs several times more than everything else because reading the process CPU clock is a system call on Linux. Leave it off unless you need CPU time, especially on production traffic. Calls cut off by `max_depth` or `max_calls` cost about 10 ns each. Numbers differ between machines, so run `make bench` on yours.

# Known limitations

### Opcache can hide small functions

With opcache enabled, the optimizer inlines trivial functions (for example `function bar() { return 1; }`) into their callers. An inlined function never runs as a call, so it does not appear in the profile and its cost is added to the caller. Profiles taken in production with opcache on can therefore omit small functions that a profile taken without opcache shows. This is how the engine works, not something xhprof can detect per call.

To see every function, profile with `opcache.optimization_level=0` (or opcache disabled). The bundled tests use functions that cannot be inlined so they give the same result with and without opcache.

### Wall time across suspensions

Generators and fibers are profiled per call. Each fiber (PHP 8.1+) has its own call stack, so a function running inside a fiber is attributed to its real caller. A function that is suspended and resumed later keeps its start time, so its wall time includes the time it spent suspended. Calls that are still suspended when `xhprof_disable()` runs are not part of the result.

# Development
Build the extension and run its tests against any PHP version in Docker, without installing a toolchain locally:

```bash
make test                       # PHP 8.5 NTS
make test PHP=8.2 VARIANT=zts   # another PHP build
make test-all                   # PHP 8.2-8.5, NTS and ZTS
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the other targets (opcache and valgrind runs, older PHP versions, a shell, the report UI) and the Docker setup behind them.
