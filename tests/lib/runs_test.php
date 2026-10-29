<?php

class XHProfTestPayload {
  public static $created = 0;
  public function __wakeup() {
    self::$created++;
  }
}

function xhprof_test_sample_run() {
  return array(
    'main()' => array('ct' => 1, 'wt' => 1234, 'cpu' => 1000, 'mu' => 2048, 'pmu' => 4096),
    'main()==>foo' => array('ct' => 2, 'wt' => 100, 'cpu' => 90, 'mu' => 512, 'pmu' => 512),
    'foo==>bar@1' => array('ct' => 3, 'wt' => 7, 'cpu' => 7, 'mu' => 0, 'pmu' => 0),
  );
}

function test_runs_save_writes_json_and_reads_back() {
  $dir = make_temp_dir();
  $runs = new XHProfRuns_Default($dir);
  $data = xhprof_test_sample_run();

  $id = $runs->save_run($data, 'ns');
  $contents = file_get_contents("$dir/$id.ns.xhprof");

  check($contents[0] === '{', 'run is stored as a JSON object');
  check_same($data, json_decode($contents, true), 'stored JSON decodes to the original data');
  check_same($data, $runs->get_run($id, 'ns', $desc), 'get_run returns the saved data');
  check_same('XHProf Run (Namespace=ns)', $desc, 'run description');

  remove_dir($dir);
}

function test_runs_empty_run_round_trips() {
  $dir = make_temp_dir();
  $runs = new XHProfRuns_Default($dir);

  $id = $runs->save_run(array(), 'ns');

  check_same(array(), $runs->get_run($id, 'ns', $desc), 'an empty run reads back as an empty array');

  remove_dir($dir);
}

function test_runs_invalid_utf8_names_do_not_lose_the_run() {
  $dir = make_temp_dir();
  $runs = new XHProfRuns_Default($dir);

  $id = $runs->save_run(array("main()==>bad\xff" => array('ct' => 1, 'wt' => 5)), 'ns');
  $data = $runs->get_run($id, 'ns', $desc);

  check(is_array($data) && count($data) === 1, 'run with an invalid UTF-8 name is still saved');
  check_same(5, array_values($data)[0]['wt'], 'metrics survive');

  remove_dir($dir);
}

function test_runs_reads_legacy_serialized_files() {
  $dir = make_temp_dir();
  $runs = new XHProfRuns_Default($dir);
  $data = xhprof_test_sample_run();

  file_put_contents("$dir/legacy.ns.xhprof", serialize($data));

  check_same($data, $runs->get_run('legacy', 'ns', $desc), 'a serialized file written by an older version is still read');

  remove_dir($dir);
}

function test_runs_legacy_files_cannot_instantiate_objects() {
  $dir = make_temp_dir();
  $runs = new XHProfRuns_Default($dir);

  $payload = serialize(array('main()' => new XHProfTestPayload()));
  XHProfTestPayload::$created = 0;
  file_put_contents("$dir/evil.ns.xhprof", $payload);

  $data = $runs->get_run('evil', 'ns', $desc);

  check_same(0, XHProfTestPayload::$created, 'unserialize does not wake up objects');
  check(is_array($data) && !($data['main()'] instanceof XHProfTestPayload), 'object is not instantiated as its class');
  check(is_array($data) && get_class($data['main()']) === '__PHP_Incomplete_Class', 'object is an incomplete class');

  remove_dir($dir);
}

function test_runs_garbage_returns_null() {
  $dir = make_temp_dir();
  $runs = new XHProfRuns_Default($dir);

  foreach (array('' => 'empty', 'not a run' => 'text', '{"a":' => 'truncated JSON', 'O:8:"stdClass":0:{}' => 'object') as $contents => $label) {
    file_put_contents("$dir/bad.ns.xhprof", $contents);
    // xhprof_error() logs through error_log(), keep the test output clean.
    $previous = ini_set('error_log', '/dev/null');
    $data = $runs->get_run('bad', 'ns', $desc);
    ini_set('error_log', $previous === false ? '' : $previous);
    check_same(null, $data, "$label file returns null");
  }

  remove_dir($dir);
}
