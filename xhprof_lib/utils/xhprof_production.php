<?php
//
// Helpers for running XHProf on a small share of production requests.
//
//   require 'xhprof_lib/utils/xhprof_lib.php';
//   require 'xhprof_lib/utils/xhprof_runs.php';
//   require 'xhprof_lib/utils/xhprof_production.php';
//
//   xhprof_production_start(array(
//     'sample_rate' => 0.01,            // profile 1 request in 100
//     'dir'         => '/var/xhprof',   // where runs are saved
//     'namespace'   => 'myapp',
//     'max_depth'   => 200,
//     'max_calls'   => 2000000,
//   ));
//
// Profiling stops and the run is saved when the request ends. Nothing here
// throws, and nothing prints: if the extension is missing, the output
// directory is not writable or saving fails, the request carries on and the
// problem goes to the error log. Keep this file PHP 7.2 compatible.
//

if (!function_exists('xhprof_error')) {
  function xhprof_error($message) {
    error_log($message);
  }
}

/**
 * Maybe start profiling the current request.
 *
 * Options:
 *   sample_rate  Share of requests to profile, 0 to 1 (default 0.01).
 *   force        true to profile this request regardless of sample_rate, for
 *                example when a header or cookie asks for it. Only pass true
 *                for requests you trust.
 *   flags        XHPROF_FLAGS_* for xhprof_enable() (default 0).
 *   max_depth    Do not record calls nested deeper than this.
 *   max_calls    Stop recording after this many calls.
 *   ignored_functions  Functions to leave out of the profile.
 *   dir          Output directory for XHProfRuns_Default (default
 *                xhprof.output_dir or XHPROF_OUTPUT_DIR).
 *   runs         An iXHProfRuns object to save with instead of dir.
 *   namespace    Run namespace (default "xhprof").
 *   on_complete  Optional callable($run_id, $data) called after saving,
 *                for example to log the id. Exceptions are caught.
 *   finish_request  Call fastcgi_finish_request() before saving, so the
 *                client does not wait for it (default true).
 *
 * @return bool true if this request is being profiled.
 */
function xhprof_production_start($options = array()) {
  try {
    return xhprof_production_start_unsafe(is_array($options) ? $options : array());
  } catch (Throwable $e) {
    xhprof_error('xhprof: not profiling this request: ' . $e->getMessage());
    return false;
  }
}

/**
 * Stop profiling now and save the run. Called automatically at the end of
 * the request; call it yourself to end profiling earlier.
 *
 * @return string|null The run id, or null if nothing was saved.
 */
function xhprof_production_stop() {
  $state = &xhprof_production_state();

  if (!$state['active']) {
    return null;
  }

  $state['active'] = false;

  try {
    $data = xhprof_disable();

    if (!is_array($data) || !$data) {
      return null;
    }

    if ($state['finish_request'] && function_exists('fastcgi_finish_request')) {
      fastcgi_finish_request();
    }

    $run_id = $state['runs']->save_run($data, $state['namespace']);

    if ($run_id !== null && $state['on_complete'] !== null) {
      call_user_func($state['on_complete'], $run_id, $data);
    }

    return $run_id;
  } catch (Throwable $e) {
    xhprof_error('xhprof: could not save run: ' . $e->getMessage());
    return null;
  }
}

/**
 * Whether a request should be profiled for a sample rate between 0 and 1.
 */
function xhprof_production_should_sample($rate) {
  $rate = (float) $rate;

  if ($rate <= 0) {
    return false;
  }

  if ($rate >= 1) {
    return true;
  }

  return mt_rand() / mt_getrandmax() < $rate;
}

function &xhprof_production_state() {
  static $state = array(
    'active' => false,
    'registered' => false,
    'runs' => null,
    'namespace' => 'xhprof',
    'on_complete' => null,
    'finish_request' => true,
  );

  return $state;
}

function xhprof_production_start_unsafe($options) {
  $state = &xhprof_production_state();

  if ($state['active']) {
    return false;
  }

  $forced = !empty($options['force']);
  $rate = isset($options['sample_rate']) ? $options['sample_rate'] : 0.01;

  // Decide before doing any work, so the common case costs one random number.
  if (!$forced && !xhprof_production_should_sample($rate)) {
    return false;
  }

  if (!function_exists('xhprof_enable') || !function_exists('xhprof_disable')) {
    xhprof_error('xhprof: extension is not loaded, not profiling');
    return false;
  }

  if (isset($options['runs'])) {
    $runs = $options['runs'];
  } else {
    $dir = isset($options['dir']) ? $options['dir'] : getenv('XHPROF_OUTPUT_DIR');

    if (empty($dir)) {
      $dir = ini_get('xhprof.output_dir');
    }

    // XHProfRuns_Default would fall back to the system temp directory, which
    // is not where production profiles should end up by accident.
    if (empty($dir)) {
      xhprof_error('xhprof: no output directory configured, not profiling');
      return false;
    }

    $runs = new XHProfRuns_Default($dir);
  }

  if (!($runs instanceof iXHProfRuns)) {
    xhprof_error('xhprof: "runs" must implement iXHProfRuns, not profiling');
    return false;
  }

  // Do not pay for profiling a request whose result cannot be saved.
  if ($runs instanceof XHProfRuns_Default && !$runs->is_writable()) {
    xhprof_error('xhprof: output directory is not writable, not profiling');
    return false;
  }

  $enable_options = array();
  foreach (array('max_depth', 'max_calls', 'ignored_functions') as $name) {
    if (isset($options[$name])) {
      $enable_options[$name] = $options[$name];
    }
  }

  $state['runs'] = $runs;
  $state['namespace'] = isset($options['namespace']) ? (string) $options['namespace'] : 'xhprof';
  $state['on_complete'] = isset($options['on_complete']) && is_callable($options['on_complete']) ? $options['on_complete'] : null;
  $state['finish_request'] = !isset($options['finish_request']) || $options['finish_request'];

  xhprof_enable(isset($options['flags']) ? (int) $options['flags'] : 0, $enable_options);
  $state['active'] = true;

  if (!$state['registered']) {
    $state['registered'] = true;
    register_shutdown_function('xhprof_production_stop');
  }

  return true;
}
