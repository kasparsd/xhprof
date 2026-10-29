--TEST--
XHProf: max_depth and max_calls limit what is recorded
Author: kasparsd
--FILE--
<?php

include_once dirname(__FILE__).'/common.php';

// Functions with side effects, so opcache can't inline them.
function leaf() {
  static $n = 0;
  return ++$n;
}

function level3() {
  return leaf() + leaf();
}

function level2() {
  return level3();
}

function level1() {
  return level2();
}

function recurse($n) {
  return $n > 0 ? recurse($n - 1) : leaf();
}

function many() {
  for ($i = 0; $i < 10; $i++) {
    leaf();
  }
  return level1();
}

echo "Part 1: no limits\n";
xhprof_enable(0, array());
level1();
$output = xhprof_disable();
print_canonical($output);

echo "\nPart 2: max_depth 2\n";
xhprof_enable(0, array('max_depth' => 2));
level1();
$output = xhprof_disable();
print_canonical($output);

echo "\nPart 3: deep recursion keeps the profile small\n";
xhprof_enable(0, array('max_depth' => 5));
recurse(10000);
$output = xhprof_disable();
echo count($output) <= 8 ? "small\n" : "too many entries: " . count($output) . "\n";

echo "\nPart 4: max_calls 4\n";
xhprof_enable(0, array('max_calls' => 4));
many();
$output = xhprof_disable();
$calls = 0;
foreach ($output as $name => $metrics) {
  if ($name !== 'main()') {
    $calls += $metrics['ct'];
  }
}
echo "recorded calls: $calls\n";

echo "\nPart 5: limits do not stick to the next run\n";
xhprof_enable();
level1();
$output = xhprof_disable();
print_canonical($output);

echo "\nPart 6: bad option values are ignored\n";
xhprof_enable(0, array('max_depth' => 'x', 'max_calls' => -1));
level1();
$output = xhprof_disable();
echo count($output), "\n";
?>
--EXPECT--
Part 1: no limits
level1==>level2                         : ct=       1; wt=*;
level2==>level3                         : ct=       1; wt=*;
level3==>leaf                           : ct=       2; wt=*;
main()                                  : ct=       1; wt=*;
main()==>level1                         : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;

Part 2: max_depth 2
level1==>level2                         : ct=       1; wt=*;
main()                                  : ct=       1; wt=*;
main()==>level1                         : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;

Part 3: deep recursion keeps the profile small
small

Part 4: max_calls 4
recorded calls: 4

Part 5: limits do not stick to the next run
level1==>level2                         : ct=       1; wt=*;
level2==>level3                         : ct=       1; wt=*;
level3==>leaf                           : ct=       2; wt=*;
main()                                  : ct=       1; wt=*;
main()==>level1                         : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;

Part 6: bad option values are ignored
6
