--TEST--
XHProf: Symbol names for methods, recursion levels and long names
Author: kasparsd
--FILE--
<?php

include_once dirname(__FILE__).'/common.php';

class Node {
  public function walk($n) {
    return $n > 0 ? $this->walk($n - 1) : self::leaf();
  }

  public static function leaf() {
    static $calls = 0;
    return ++$calls;
  }
}

function recurse($n) {
  return $n > 0 ? recurse($n - 1) : Node::leaf();
}

// Longer than the buffer the profiler builds "parent==>child" names in.
$medium = 'm' . str_repeat('y', 300);
$long = 'l' . str_repeat('x', 600);
eval("function $medium() { return Node::leaf(); }");
eval("function $long() { global \$medium; return \$medium(); }");
$long_name = $long;

function call_long($name) {
  return $name();
}

xhprof_enable();
(new Node)->walk(2);
recurse(2);
$output = xhprof_disable();

echo "Part 1: methods and recursion levels\n";
print_canonical($output);

xhprof_enable();
call_long($long_name);
$output = xhprof_disable();

echo "\nPart 2: long names\n";
$names = array_keys($output);
sort($names);
foreach ($names as $name) {
  echo strlen($name), ' ', substr($name, 0, 8), '...', substr($name, -12), "\n";
}

$anonymous = new class {
  public function run() { return Node::leaf(); }
};

xhprof_enable();
$anonymous->run();
$output = xhprof_disable();

echo "\nPart 3: anonymous class\n";
$names = array_keys($output);
sort($names);
foreach ($names as $name) {
  echo strlen($name), ' ', str_replace("\0", '\\0', $name), "\n";
}
?>
--EXPECT--
Part 1: methods and recursion levels
Node::walk==>Node::walk@1               : ct=       1; wt=*;
Node::walk@1==>Node::walk@2             : ct=       1; wt=*;
Node::walk@2==>Node::leaf               : ct=       1; wt=*;
main()                                  : ct=       1; wt=*;
main()==>Node::walk                     : ct=       1; wt=*;
main()==>recurse                        : ct=       1; wt=*;
main()==>xhprof_disable                 : ct=       1; wt=*;
recurse==>recurse@1                     : ct=       1; wt=*;
recurse@1==>recurse@2                   : ct=       1; wt=*;
recurse@2==>Node::leaf                  : ct=       1; wt=*;

Part 2: long names
511 call_lon...xxxxxxxxxxxx
511 lxxxxxxx...xxxxxxxxxxxx
6 main()...main()
18 main()==...==>call_long
23 main()==...prof_disable
314 myyyyyyy...=>Node::leaf

Part 3: anonymous class
33 class@anonymous::run==>Node::leaf
6 main()
29 main()==>class@anonymous::run
23 main()==>xhprof_disable
