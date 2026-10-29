--TEST--
XHProf: Fibers keep the call graph consistent
Author: kasparsd
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80100) die("skip Fibers need PHP 8.1");
?>
--FILE--
<?php

include_once dirname(__FILE__).'/common.php';

// Functions with side effects, so opcache can't inline them.
function work() {
  static $n = 0;
  return ++$n;
}

function fiber_inner() {
  work();
  Fiber::suspend(1);
  work();
}

function fiber_outer() {
  fiber_inner();
  work();
}

function drive_fiber() {
  $f = new Fiber('fiber_outer');
  $f->start();
  work();
  $f->resume();
}

// Two fibers that are interleaved.
function ping() {
  work();
  Fiber::suspend();
  work();
}

function pong() {
  work();
  Fiber::suspend();
  work();
}

function interleave() {
  $a = new Fiber('ping');
  $b = new Fiber('pong');
  $a->start();
  $b->start();
  $a->resume();
  $b->resume();
}

// A fiber that is never finished.
function abandon() {
  $f = new Fiber('ping');
  $f->start();
  work();
}

// A fiber that throws.
function thrower() {
  work();
  throw new Exception('x');
}

function catch_it() {
  $f = new Fiber('thrower');
  try {
    $f->start();
  } catch (Exception $e) {
    work();
  }
}

xhprof_enable();
drive_fiber();
$output = xhprof_disable();

echo "Part 1: suspend and resume in the middle of a call\n";
print_canonical($output);

xhprof_enable();
interleave();
$output = xhprof_disable();

echo "\nPart 2: interleaved fibers\n";
print_canonical($output);

xhprof_enable();
abandon();
$output = xhprof_disable();

echo "\nPart 3: fiber left suspended\n";
print_canonical($output);

xhprof_enable();
catch_it();
$output = xhprof_disable();

echo "\nPart 4: fiber that throws\n";
print_canonical($output);

// Profiling stopped while a fiber is suspended.
$f = new Fiber('ping');
xhprof_enable();
$f->start();
$output = xhprof_disable();
$f->resume();

echo "\nPart 5: profiling stopped while a fiber is suspended\n";
print_canonical($output);

xhprof_enable();
work();
$output = xhprof_disable();

echo "\nPart 6: profile still usable afterwards\n";
print_canonical($output);
?>
--EXPECT--
Part 1: suspend and resume in the middle of a call
Fiber::start==>fiber_outer              : ct=       1; wt=*;
drive_fiber==>Fiber::__construct        : ct=       1; wt=*;
drive_fiber==>Fiber::resume             : ct=       1; wt=*;
drive_fiber==>Fiber::start              : ct=       1; wt=*;
drive_fiber==>work                      : ct=       1; wt=*;
fiber_inner==>Fiber::suspend            : ct=       1; wt=*;
fiber_inner==>work                      : ct=       2; wt=*;
fiber_outer==>fiber_inner               : ct=       1; wt=*;
fiber_outer==>work                      : ct=       1; wt=*;
main()                                  : ct=       1; wt=*;
main()==>drive_fiber                    : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;

Part 2: interleaved fibers
Fiber::start==>ping                     : ct=       1; wt=*;
Fiber::start==>pong                     : ct=       1; wt=*;
interleave==>Fiber::__construct         : ct=       2; wt=*;
interleave==>Fiber::resume              : ct=       2; wt=*;
interleave==>Fiber::start               : ct=       2; wt=*;
main()                                  : ct=       1; wt=*;
main()==>interleave                     : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
ping==>Fiber::suspend                   : ct=       1; wt=*;
ping==>work                             : ct=       2; wt=*;
pong==>Fiber::suspend                   : ct=       1; wt=*;
pong==>work                             : ct=       2; wt=*;

Part 3: fiber left suspended
Fiber::start==>ping                     : ct=       1; wt=*;
abandon==>Fiber::__construct            : ct=       1; wt=*;
abandon==>Fiber::start                  : ct=       1; wt=*;
abandon==>work                          : ct=       1; wt=*;
main()                                  : ct=       1; wt=*;
main()==>abandon                        : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
ping==>Fiber::suspend                   : ct=       1; wt=*;
ping==>work                             : ct=       1; wt=*;

Part 4: fiber that throws
Fiber::start==>thrower                  : ct=       1; wt=*;
catch_it==>Fiber::__construct           : ct=       1; wt=*;
catch_it==>Fiber::start                 : ct=       1; wt=*;
catch_it==>work                         : ct=       1; wt=*;
main()                                  : ct=       1; wt=*;
main()==>catch_it                       : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
thrower==>Exception::__construct        : ct=       1; wt=*;
thrower==>work                          : ct=       1; wt=*;

Part 5: profiling stopped while a fiber is suspended
main()                                  : ct=       1; wt=*;
main()==>Fiber::start                   : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
ping==>work                             : ct=       1; wt=*;

Part 6: profile still usable afterwards
main()                                  : ct=       1; wt=*;
main()==>work                           : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
