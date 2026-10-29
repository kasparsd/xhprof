<?php

require_once XHPROF_REPO_ROOT . '/xhprof_lib/utils/xhprof_export.php';

// main() 100 = 10 self + foo 60 + bar 30
// foo 60 = 20 self + baz 40 (60 total for baz: 40 from foo, 20 from bar)
// bar 30 = 10 self + baz 20
// baz 60 = 60 self
function xhprof_test_export_run() {
  return array(
    'main()' => array('ct' => 1, 'wt' => 100, 'mu' => 1000),
    'main()==>foo' => array('ct' => 1, 'wt' => 60, 'mu' => 600),
    'main()==>bar' => array('ct' => 1, 'wt' => 30, 'mu' => 300),
    'foo==>baz' => array('ct' => 2, 'wt' => 40, 'mu' => 400),
    'bar==>baz' => array('ct' => 1, 'wt' => 20, 'mu' => 200),
    'baz' => array('ct' => 3, 'wt' => 60, 'mu' => 600),
  );
}

function xhprof_test_parse_collapsed($text) {
  $out = array();
  foreach (array_filter(explode("\n", $text)) as $line) {
    $pos = strrpos($line, ' ');
    $out[substr($line, 0, $pos)] = (int) substr($line, $pos + 1);
  }
  return $out;
}

function test_export_collapsed_self_time() {
  $lines = xhprof_test_parse_collapsed(xhprof_export_collapsed(xhprof_test_export_run()));

  // baz is called from foo (2 calls, 40) and bar (1 call, 20), so each
  // caller's share of baz is its edge, not the unrelated "baz" row.
  ksort($lines);
  check_same(array(
    'main()' => 10,
    'main();bar' => 10,
    'main();bar;baz' => 20,
    'main();foo' => 20,
    'main();foo;baz' => 40,
  ), $lines, 'self time per stack');

  check_same(100, array_sum($lines), 'weights add up to main()');
}

function test_export_collapsed_other_metrics() {
  $lines = xhprof_test_parse_collapsed(xhprof_export_collapsed(xhprof_test_export_run(), 'mu'));

  check_same(1000, array_sum($lines), 'memory weights add up to main()');
  check_same(400, $lines['main();foo;baz'], 'memory for baz under foo');

  $calls = xhprof_test_parse_collapsed(xhprof_export_collapsed(xhprof_test_export_run(), 'ct'));
  ksort($calls);
  check_same(array('main()' => 1, 'main();bar' => 1, 'main();bar;baz' => 1, 'main();foo' => 1, 'main();foo;baz' => 2), $calls, 'call counts');
}

function test_export_shared_callee_is_split_by_caller_share() {
  // helper is called from a (30 of 40 total) and b (10 of 40), and spends
  // 20 in leaf. The 20 is split 15 / 5.
  $run = array(
    'main()' => array('wt' => 100),
    'main()==>a' => array('wt' => 50),
    'main()==>b' => array('wt' => 20),
    'a==>helper' => array('wt' => 30),
    'b==>helper' => array('wt' => 10),
    'helper==>leaf' => array('wt' => 20),
  );

  $lines = xhprof_test_parse_collapsed(xhprof_export_collapsed($run));

  check_same(15, $lines['main();a;helper;leaf'], 'leaf under a');
  check_same(5, $lines['main();b;helper;leaf'], 'leaf under b');
  check_same(100, array_sum($lines), 'weights add up to main()');
}

function test_export_recursion_levels_are_merged() {
  $run = array(
    'main()' => array('wt' => 100),
    'main()==>fact' => array('wt' => 90),
    'fact==>fact@1' => array('wt' => 60),
    'fact@1==>fact@2' => array('wt' => 30),
  );

  $lines = xhprof_test_parse_collapsed(xhprof_export_collapsed($run));

  // fact is 90 inclusive, 60 of it in fact@1, 30 of that in fact@2.
  ksort($lines);
  check_same(array(
    'main()' => 10,
    'main();fact' => 30,
    'main();fact;fact' => 30,
    'main();fact;fact;fact' => 30,
  ), $lines, 'recursion levels become nested frames of the same function');
  check(strpos(xhprof_export_collapsed($run), '@') === false, 'no @level suffix in frame names');
}

function test_export_survives_cycles_and_clamps_negative_self() {
  $run = array(
    'main()' => array('wt' => 10),
    'main()==>a' => array('wt' => 10),
    'a==>b' => array('wt' => 15),
    'b==>a' => array('wt' => 5),
  );

  $lines = xhprof_test_parse_collapsed(xhprof_export_collapsed($run));

  check(isset($lines['main();a;b']), 'cycle is cut instead of looping forever');
  check(min($lines) > 0, 'only positive weights are exported');
}

function test_export_limits_the_number_of_stacks() {
  $run = array('main()' => array('wt' => 1000));
  for ($i = 0; $i < 50; $i++) {
    $run["main()==>f$i"] = array('wt' => 10);
    $run["f$i==>g"] = array('wt' => 5);
  }

  check_same(101, count(xhprof_export_stacks($run, 'wt', 1000)), 'all stacks without a limit');

  $limited = xhprof_export_stacks($run, 'wt', 10);
  $total = 0;
  foreach ($limited as $entry) {
    $total += $entry['weight'];
  }

  check(count($limited) <= 10, 'stack count is bounded');
  check_same(1000, $total, 'totals stay correct when subtrees are folded');
}

function test_export_names_and_empty_input() {
  check_same('', xhprof_export_collapsed(array()), 'empty run exports nothing');
  check_same('', xhprof_export_collapsed(array('foo==>bar' => array('wt' => 1))), 'run without main() exports nothing');

  $run = array(
    'main()' => array('wt' => 5),
    'main()==>load::a;b.php' => array('wt' => 4),
  );

  check_same("main() 1\nmain();load::a,b.php 4\n", xhprof_export_collapsed($run), 'separator characters are removed from names');
}

function test_export_speedscope_file() {
  $json = xhprof_export_speedscope(xhprof_test_export_run(), 'wt', 'GET /');
  $file = json_decode($json, true);

  check_same('https://www.speedscope.app/file-format-schema.json', $file['$schema'], 'schema');

  $profile = $file['profiles'][0];
  check_same('sampled', $profile['type'], 'profile type');
  check_same('microseconds', $profile['unit'], 'unit');
  check_same('GET /', $profile['name'], 'name');
  check_same(count($profile['samples']), count($profile['weights']), 'one weight per sample');
  check_same(100, $profile['endValue'], 'end value is the total');
  check_same(100, array_sum($profile['weights']), 'weights add up to main()');

  $frames = array();
  foreach ($file['shared']['frames'] as $frame) {
    $frames[] = $frame['name'];
  }
  check_same(array('main()', 'foo', 'baz', 'bar'), $frames, 'frames are shared and in first-use order');

  $max_index = count($frames) - 1;
  $valid = true;
  foreach ($profile['samples'] as $sample) {
    foreach ($sample as $index) {
      $valid = $valid && is_int($index) && $index >= 0 && $index <= $max_index;
    }
  }
  check($valid, 'samples only reference existing frames');

  $first = array();
  foreach ($profile['samples'][0] as $index) {
    $first[] = $frames[$index];
  }
  check_same(array('main()'), $first, 'first sample is main() self');
}
