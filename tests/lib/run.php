<?php
// Runs the PHP library tests: php tests/lib/run.php
//
// No dependencies. Every *_test.php in this directory is included and its
// test_* functions are called. Keep this PHP 7.2 compatible.

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('XHPROF_REPO_ROOT', dirname(dirname(__DIR__)));

$GLOBALS['xhprof_test_failures'] = 0;
$GLOBALS['xhprof_test_checks'] = 0;

// Turn notices and deprecations into failures.
set_error_handler(function ($severity, $message, $file, $line) {
  if (!(error_reporting() & $severity)) {
    return false;
  }
  throw new ErrorException($message, 0, $severity, $file, $line);
});

function check($condition, $message) {
  $GLOBALS['xhprof_test_checks']++;
  if (!$condition) {
    $GLOBALS['xhprof_test_failures']++;
    echo "  FAIL: $message\n";
  }
}

function check_same($expected, $actual, $message) {
  $ok = ($expected === $actual);
  check($ok, $message . ($ok ? '' : "\n    expected: " . var_export($expected, true) . "\n    actual:   " . var_export($actual, true)));
}

function make_temp_dir() {
  $dir = sys_get_temp_dir() . '/xhprof-lib-test-' . uniqid('', true);
  mkdir($dir);
  return $dir;
}

function remove_dir($dir) {
  foreach (glob($dir . '/*') as $file) {
    unlink($file);
  }
  rmdir($dir);
}

require_once XHPROF_REPO_ROOT . '/xhprof_lib/utils/xhprof_lib.php';
require_once XHPROF_REPO_ROOT . '/xhprof_lib/utils/xhprof_runs.php';

$files = glob(__DIR__ . '/*_test.php');
sort($files);

foreach ($files as $file) {
  require_once $file;
}

$tests = array();
foreach (get_defined_functions()['user'] as $function) {
  if (strpos($function, 'test_') === 0) {
    $tests[] = $function;
  }
}

foreach ($tests as $test) {
  echo $test . "\n";
  try {
    $test();
  } catch (Throwable $e) {
    $GLOBALS['xhprof_test_failures']++;
    echo '  FAIL: uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
  }
}

printf("\n%d tests, %d checks, %d failures\n", count($tests), $GLOBALS['xhprof_test_checks'], $GLOBALS['xhprof_test_failures']);
exit($GLOBALS['xhprof_test_failures'] ? 1 : 0);
