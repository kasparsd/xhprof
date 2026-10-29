<?php

require_once XHPROF_REPO_ROOT . '/xhprof_lib/utils/xhprof_production.php';

// Stand-ins for the extension, so the helper can be tested without it. When
// the real extension is loaded these tests are skipped.
$GLOBALS['xhprof_test_stub'] = !function_exists('xhprof_enable');

if ($GLOBALS['xhprof_test_stub']) {
  $GLOBALS['xhprof_test_enabled_with'] = null;

  function xhprof_enable($flags = 0, $options = array()) {
    $GLOBALS['xhprof_test_enabled_with'] = array($flags, $options);
  }

  function xhprof_disable() {
    return array('main()' => array('ct' => 1, 'wt' => 5));
  }
}

function xhprof_test_quiet($callback) {
  $previous = ini_set('error_log', '/dev/null');
  $result = call_user_func($callback);
  ini_set('error_log', $previous === false ? '' : $previous);
  return $result;
}

function test_production_sample_rate_bounds() {
  check_same(false, xhprof_production_should_sample(0), 'rate 0 never samples');
  check_same(false, xhprof_production_should_sample(-1), 'negative rate never samples');
  check_same(true, xhprof_production_should_sample(1), 'rate 1 always samples');
  check_same(true, xhprof_production_should_sample(5), 'rate above 1 always samples');
}

function test_production_sample_rate_is_roughly_honoured() {
  mt_srand(42);
  $hits = 0;
  for ($i = 0; $i < 10000; $i++) {
    if (xhprof_production_should_sample(0.1)) {
      $hits++;
    }
  }

  check($hits > 800 && $hits < 1200, "10% of 10000 requests sampled, got $hits");
}

function test_production_not_sampled_does_nothing() {
  if (!$GLOBALS['xhprof_test_stub']) {
    return;
  }

  $dir = make_temp_dir();
  $GLOBALS['xhprof_test_enabled_with'] = null;

  check_same(false, xhprof_production_start(array('sample_rate' => 0, 'dir' => $dir)), 'not sampled');
  check_same(null, $GLOBALS['xhprof_test_enabled_with'], 'profiler stays off');
  check_same(null, xhprof_production_stop(), 'stop without start does nothing');

  remove_dir($dir);
}

function test_production_saves_the_run_with_options() {
  if (!$GLOBALS['xhprof_test_stub']) {
    return;
  }

  $dir = make_temp_dir();
  $saved = array();

  $started = xhprof_production_start(array(
    'force' => true,
    'sample_rate' => 0,
    'dir' => $dir,
    'namespace' => 'prod',
    'flags' => 6,
    'max_depth' => 50,
    'max_calls' => 1000,
    'unknown' => 'ignored',
    'on_complete' => function ($id, $data) use (&$saved) {
      $saved = array($id, $data);
    },
  ));

  check_same(true, $started, 'forced request is profiled');
  check_same(array(6, array('max_depth' => 50, 'max_calls' => 1000)), $GLOBALS['xhprof_test_enabled_with'], 'flags and limits reach xhprof_enable()');
  check_same(false, xhprof_production_start(array('force' => true, 'dir' => $dir)), 'cannot start twice');

  $id = xhprof_production_stop();

  check($id !== null && file_exists("$dir/$id.prod.xhprof"), 'run is saved under the namespace');
  check_same($id, $saved[0], 'on_complete gets the run id');
  check_same(array('main()' => array('ct' => 1, 'wt' => 5)), $saved[1], 'on_complete gets the data');
  check_same(null, xhprof_production_stop(), 'a stopped run is not saved twice');

  remove_dir($dir);
}

function test_production_missing_output_dir_does_not_start_or_throw() {
  if (!$GLOBALS['xhprof_test_stub']) {
    return;
  }

  $GLOBALS['xhprof_test_enabled_with'] = null;
  $dir = sys_get_temp_dir() . '/xhprof-does-not-exist-' . uniqid('', true);

  $started = xhprof_test_quiet(function () use ($dir) {
    return xhprof_production_start(array('force' => true, 'dir' => $dir));
  });

  check_same(false, $started, 'no profiling when the directory is missing');
  check_same(null, $GLOBALS['xhprof_test_enabled_with'], 'profiler stays off');
}

function test_production_no_output_dir_configured() {
  if (!$GLOBALS['xhprof_test_stub']) {
    return;
  }

  // Without the extension xhprof.output_dir is not registered and reads as false.
  putenv('XHPROF_OUTPUT_DIR');

  $started = xhprof_test_quiet(function () {
    return xhprof_production_start(array('force' => true));
  });

  check_same(false, $started, 'no profiling without an output directory');
}

function test_production_save_failure_is_not_fatal() {
  if (!$GLOBALS['xhprof_test_stub']) {
    return;
  }

  $dir = make_temp_dir();
  $started = xhprof_production_start(array('force' => true, 'dir' => $dir));
  check_same(true, $started, 'started');

  // The directory disappears while the request runs.
  remove_dir($dir);

  $id = xhprof_test_quiet('xhprof_production_stop');

  check_same(null, $id, 'stop reports the failure with null and does not throw or warn');
}

function test_production_failing_callback_is_not_fatal() {
  if (!$GLOBALS['xhprof_test_stub']) {
    return;
  }

  $dir = make_temp_dir();
  xhprof_production_start(array(
    'force' => true,
    'dir' => $dir,
    'on_complete' => function () {
      throw new RuntimeException('callback failed');
    },
  ));

  $id = xhprof_test_quiet('xhprof_production_stop');

  check_same(null, $id, 'a throwing callback is caught');

  check_same(1, count(glob("$dir/*.xhprof")), 'run was saved before the callback');

  remove_dir($dir);
}

function test_runs_save_to_missing_directory_returns_null_quietly() {
  $dir = sys_get_temp_dir() . '/xhprof-does-not-exist-' . uniqid('', true);
  $runs = new XHProfRuns_Default($dir);

  $id = xhprof_test_quiet(function () use ($runs) {
    return $runs->save_run(array('main()' => array('ct' => 1)), 'ns');
  });

  check_same(null, $id, 'save_run returns null when the directory is missing');
  check_same(false, $runs->is_writable(), 'directory is reported as not writable');
}

function test_runs_save_leaves_no_temporary_files() {
  $dir = make_temp_dir();
  $runs = new XHProfRuns_Default($dir);

  $runs->save_run(array('main()' => array('ct' => 1)), 'ns', 'fixed');

  check_same(array("$dir/fixed.ns.xhprof"), glob("$dir/*"), 'only the run file remains');
  check_same(true, $runs->is_writable(), 'directory is reported as writable');

  remove_dir($dir);
}
