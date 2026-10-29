<?php
// Measures what the profiler costs per call.
//
//   php -d extension=/path/to/xhprof.so tools/bench/bench.php [repetitions]
//   tools/bench/run.sh                      (builds the extension, then runs this)
//
// Each workload runs without the profiler and then with each profiler
// configuration, several times; the median is reported. "ns/call" is
// (profiled time - baseline time) / number of calls the workload makes, taken
// from the "default" run, so it is comparable between configurations even
// when one of them records fewer calls. Keep this PHP 7.2 compatible.

if (!function_exists('xhprof_enable')) {
  fwrite(STDERR, "xhprof extension is not loaded\n");
  exit(1);
}

$repetitions = isset($argv[1]) ? max(3, (int) $argv[1]) : 7;

// ---------------------------------------------------------------- workloads

function fib($n) {
  return $n < 2 ? $n : fib($n - 1) + fib($n - 2);
}

// Many different functions called from one place, none recursive.
function w_a($x) { return $x + 1; }
function w_b($x) { return w_a($x) + 1; }
function w_c($x) { return w_b($x) + 1; }
function w_d($x) { return w_c($x) + 1; }

class Counter {
  private $n = 0;
  public function inc() { $this->n++; return $this; }
  public function get() { return $this->n; }
}

// A deep stack of distinct functions, with a call at the bottom, to exercise
// the recursion check that func_hash_counters short-circuits.
function deep_call($depth) {
  if ($depth === 0) {
    $s = 0;
    for ($i = 0; $i < 100000; $i++) {
      $s += w_d($i);
    }
    return $s;
  }
  $name = 'deep_' . ($depth % 64);
  return $name($depth - 1);
}
for ($i = 0; $i < 64; $i++) {
  eval("function deep_$i(\$d) { return deep_call(\$d); }");
}

function work_recursive() { return fib(26); }

function work_flat() {
  $s = 0;
  for ($i = 0; $i < 200000; $i++) {
    $s += w_d($i);
  }
  return $s;
}

function work_methods() {
  $c = new Counter();
  for ($i = 0; $i < 500000; $i++) {
    $c->inc();
  }
  return $c->get();
}

function work_builtins() {
  $s = 0;
  for ($i = 0; $i < 300000; $i++) {
    $s += strlen(str_repeat('x', $i % 8)) + abs($i) + max($i, 3);
  }
  return $s;
}

function work_deep() {
  return deep_call(120);
}

$workloads = array(
  'recursion (fib 26)' => 'work_recursive',
  'flat calls (4 levels)' => 'work_flat',
  'method calls' => 'work_methods',
  'builtins only' => 'work_builtins',
  'deep stack (120)' => 'work_deep',
);

$configs = array(
  'default' => array(0, array()),
  'cpu' => array(XHPROF_FLAGS_CPU, array()),
  'memory' => array(XHPROF_FLAGS_MEMORY, array()),
  'cpu+memory' => array(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY, array()),
  'no builtins' => array(XHPROF_FLAGS_NO_BUILTINS, array()),
  'max_depth 3' => array(0, array('max_depth' => 3)),
);

// ------------------------------------------------------------------ running

function median($values) {
  sort($values);
  $n = count($values);
  return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

function time_it($callable, $flags = null, $options = null) {
  $start = microtime(true);
  if ($flags !== null) {
    xhprof_enable($flags, $options);
  }
  $callable();
  if ($flags !== null) {
    $data = xhprof_disable();
  } else {
    $data = null;
  }
  return array(microtime(true) - $start, $data);
}

function count_calls($data) {
  $calls = 0;
  foreach ($data as $name => $metrics) {
    if ($name !== 'main()') {
      $calls += $metrics['ct'];
    }
  }
  return $calls;
}

printf("PHP %s (%s), %d repetitions, median\n\n", PHP_VERSION, PHP_ZTS ? 'ZTS' : 'NTS', $repetitions);

foreach ($workloads as $label => $callable) {
  // Warm up caches and let the opcache/JIT settle, if any.
  time_it($callable);

  $baseline = array();
  for ($i = 0; $i < $repetitions; $i++) {
    list($t) = time_it($callable);
    $baseline[] = $t;
  }
  $base = median($baseline);

  printf("%s\n  baseline: %.1f ms\n", $label, $base * 1000);
  printf("  %-12s %10s %10s %12s %10s\n", 'config', 'time ms', 'slowdown', 'recorded', 'ns/call');

  $all_calls = 0;

  foreach ($configs as $name => $config) {
    list($flags, $options) = $config;
    $times = array();
    $calls = 0;

    for ($i = 0; $i < $repetitions; $i++) {
      list($t, $data) = time_it($callable, $flags, $options);
      $times[] = $t;
      $calls = count_calls($data);
    }

    if ($all_calls === 0) {
      $all_calls = $calls;
    }

    $t = median($times);
    $ns = $all_calls > 0 ? ($t - $base) / $all_calls * 1e9 : 0;

    printf("  %-12s %10.1f %9.2fx %12d %10.0f\n", $name, $t * 1000, $t / $base, $calls, $ns);
  }

  echo "\n";
}
