<?php
//
// Export of raw XHProf data for flame graph viewers.
//
// XHProf keeps one row per "parent==>child" edge, not one per call stack, so
// the real stacks are gone. These functions rebuild an approximate call tree
// from the edges, starting at main(). Where a function has several callers,
// its callees are split between them in proportion to how much of the
// function's total time each caller accounts for. Totals are exact, the
// shape below the first shared function is an estimate.
//
// The output works with flamegraph.pl, inferno and speedscope
// (https://www.speedscope.app). Keep this file PHP 7.2 compatible.
//

/**
 * Rebuild weighted call stacks from raw XHProf data.
 *
 * Each returned element is array('stack' => array of frame names, 'weight'
 * => int), where the weight is the metric spent in the last frame itself,
 * excluding its callees ("self"). Weights add up to the metric of main(),
 * except where a function's callees took longer than the function itself
 * (timer noise, or negative memory deltas); those are clamped at zero.
 *
 * @param array  $raw_data   Result of xhprof_disable().
 * @param string $metric     wt, cpu, mu, pmu (inclusive metrics) or ct
 *                           (number of calls).
 * @param int    $max_stacks Upper bound on returned stacks. Shared functions
 *                           can make the tree large; once the bound is
 *                           reached the rest of a subtree is folded into its
 *                           parent so totals stay correct.
 * @return array
 */
function xhprof_export_stacks($raw_data, $metric = 'wt', $max_stacks = 100000) {
  $children = array();
  $incoming = array();
  $root = 'main()';

  foreach ($raw_data as $key => $values) {
    if (!is_array($values) || !isset($values[$metric])) {
      continue;
    }

    $key = (string) $key;
    $pos = strpos($key, '==>');

    if ($pos === false) {
      if ($key === $root) {
        $incoming[$root] = (float) $values[$metric];
      }
      continue;
    }

    $parent = substr($key, 0, $pos);
    $child = substr($key, $pos + 3);
    $value = (float) $values[$metric];

    $children[$parent][$child] = $value;
    $incoming[$child] = (isset($incoming[$child]) ? $incoming[$child] : 0.0) + $value;
  }

  if (!isset($incoming[$root])) {
    return array();
  }

  $stacks = array();
  $max_stacks = max(1, (int) $max_stacks);
  $names = array();

  // Iterative depth-first walk, so deep recursion cannot exhaust the stack.
  // Each frame: node, inclusive value in this context, path of node names.
  $work = array(array($root, $incoming[$root], array($root)));

  while ($work) {
    list($node, $inclusive, $path) = array_pop($work);

    $kids = array();
    $kids_total = 0.0;

    if (isset($children[$node]) && $incoming[$node] > 0) {
      $scale = $inclusive / $incoming[$node];

      foreach ($children[$node] as $child => $value) {
        // Cycles cannot come from real call graphs, but the data can be
        // hand made. Treat a repeated node as part of the caller's self.
        if (!in_array($child, $path, true)) {
          $kids[$child] = $value * $scale;
        }
      }

      // Keep the biggest callees if there is no room for all of them. The
      // rest stays in this node's self value.
      $room = $max_stacks - count($stacks) - count($work) - 1;

      if ($room < count($kids)) {
        arsort($kids);
        $kids = $room > 0 ? array_slice($kids, 0, $room, true) : array();
      }

      $kids_total = array_sum($kids);
    }

    if ($metric === 'ct') {
      // Call counts are not inclusive of callees.
      $self = $inclusive;
    } else {
      $self = $inclusive - $kids_total;
    }

    $self = (int) round($self);

    if ($self > 0) {
      $frames = array();
      foreach ($path as $name) {
        if (!isset($names[$name])) {
          $names[$name] = xhprof_export_frame_name($name);
        }
        $frames[] = $names[$name];
      }

      $stacks[] = array('stack' => $frames, 'weight' => $self);
    }

    // Push in reverse so output follows the order of the raw data.
    foreach (array_reverse($kids, true) as $child => $value) {
      if ($value <= 0) {
        continue;
      }

      $child_path = $path;
      $child_path[] = $child;
      $work[] = array($child, $value, $child_path);
    }
  }

  return $stacks;
}

/**
 * Frame name for a function symbol: drops the "@level" recursion suffix so
 * recursive calls stay one function, and removes characters that the
 * collapsed stack format uses as separators.
 */
function xhprof_export_frame_name($symbol) {
  $name = preg_replace('/@\d+$/', '', $symbol);
  return str_replace(array(';', "\n", "\r"), array(',', ' ', ' '), $name);
}

/**
 * Collapsed stacks ("main();foo;bar 1234" per line) for flamegraph.pl,
 * inferno and similar tools.
 *
 * @param array  $raw_data Result of xhprof_disable().
 * @param string $metric   See xhprof_export_stacks().
 * @return string
 */
function xhprof_export_collapsed($raw_data, $metric = 'wt') {
  $lines = array();
  $weights = array();

  // Merge stacks that collapse to the same text (recursion levels).
  foreach (xhprof_export_stacks($raw_data, $metric) as $entry) {
    $line = implode(';', $entry['stack']);
    $weights[$line] = (isset($weights[$line]) ? $weights[$line] : 0) + $entry['weight'];
  }

  foreach ($weights as $line => $weight) {
    $lines[] = $line . ' ' . $weight;
  }

  return $lines ? implode("\n", $lines) . "\n" : '';
}

/**
 * Speedscope file (https://www.speedscope.app/file-format-schema.json) with
 * one "sampled" profile, one sample per stack weighted by its self value.
 *
 * @param array  $raw_data Result of xhprof_disable().
 * @param string $metric   See xhprof_export_stacks().
 * @param string $name     Profile name shown in speedscope.
 * @return string JSON
 */
function xhprof_export_speedscope($raw_data, $metric = 'wt', $name = 'xhprof') {
  $units = array(
    'wt' => 'microseconds',
    'cpu' => 'microseconds',
    'mu' => 'bytes',
    'pmu' => 'bytes',
    'ct' => 'none',
  );

  $frames = array();
  $frame_index = array();
  $samples = array();
  $weights = array();
  $total = 0;

  foreach (xhprof_export_stacks($raw_data, $metric) as $entry) {
    $sample = array();

    foreach ($entry['stack'] as $frame) {
      if (!isset($frame_index[$frame])) {
        $frame_index[$frame] = count($frames);
        $frames[] = array('name' => $frame);
      }
      $sample[] = $frame_index[$frame];
    }

    $samples[] = $sample;
    $weights[] = $entry['weight'];
    $total += $entry['weight'];
  }

  $file = array(
    '$schema' => 'https://www.speedscope.app/file-format-schema.json',
    'shared' => array('frames' => $frames),
    'profiles' => array(array(
      'type' => 'sampled',
      'name' => $name,
      'unit' => isset($units[$metric]) ? $units[$metric] : 'none',
      'startValue' => 0,
      'endValue' => $total,
      'samples' => $samples,
      'weights' => $weights,
    )),
    'name' => $name,
    'activeProfileIndex' => 0,
    'exporter' => 'xhprof',
  );

  return json_encode($file, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}
