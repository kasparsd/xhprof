--TEST--
XHProf: Generators keep the call graph consistent
Author: kasparsd
--SKIPIF--
<?php
// Before PHP 8 there is no observer API and generators are counted differently.
if (PHP_VERSION_ID < 80000) die("skip Generator call counts differ before PHP 8");
?>
--FILE--
<?php

include_once dirname(__FILE__).'/common.php';

// Functions with side effects, so opcache can't inline them.
function work() {
  static $n = 0;
  return ++$n;
}

function gen() {
  work();
  yield 1;
  work();
  yield 2;
  work();
}

function consume() {
  foreach (gen() as $v) {
    work();
  }
}

// A generator that is abandoned half way and one that is delegated to.
function inner_gen() {
  work();
  yield 1;
  work();
}

function outer_gen() {
  yield from inner_gen();
  work();
}

function partial() {
  $g = outer_gen();
  $g->current();
  work();
}

xhprof_enable();
consume();
$output = xhprof_disable();

echo "Part 1: foreach over a generator\n";
print_canonical($output);

xhprof_enable();
partial();
$output = xhprof_disable();

echo "\nPart 2: yield from, abandoned generator\n";
print_canonical($output);

xhprof_enable();
work();
$output = xhprof_disable();

echo "\nPart 3: profile still usable afterwards\n";
print_canonical($output);
?>
--EXPECT--
Part 1: foreach over a generator
consume==>gen                           : ct=       3; wt=*;
consume==>work                          : ct=       2; wt=*;
gen==>work                              : ct=       3; wt=*;
main()                                  : ct=       1; wt=*;
main()==>consume                        : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;

Part 2: yield from, abandoned generator
Generator::current==>inner_gen          : ct=       1; wt=*;
Generator::current==>outer_gen          : ct=       1; wt=*;
inner_gen==>work                        : ct=       1; wt=*;
main()                                  : ct=       1; wt=*;
main()==>partial                        : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
partial==>Generator::current            : ct=       1; wt=*;
partial==>work                          : ct=       1; wt=*;

Part 3: profile still usable afterwards
main()                                  : ct=       1; wt=*;
main()==>work                           : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
