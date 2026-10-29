<?php
//
//  Copyright (c) 2009 Facebook
//
//  Licensed under the Apache License, Version 2.0 (the "License");
//  you may not use this file except in compliance with the License.
//  You may obtain a copy of the License at
//
//      http://www.apache.org/licenses/LICENSE-2.0
//
//  Unless required by applicable law or agreed to in writing, software
//  distributed under the License is distributed on an "AS IS" BASIS,
//  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
//  See the License for the specific language governing permissions and
//  limitations under the License.
//

//
// This file defines the interface iXHProfRuns and also provides a default
// implementation of the interface (class XHProfRuns).
//

/**
 * iXHProfRuns interface for getting/saving a XHProf run.
 *
 * Clients can either use the default implementation,
 * namely XHProfRuns_Default, of this interface or define
 * their own implementation.
 *
 * @author Kannan
 */
interface iXHProfRuns {

  /**
   * Returns XHProf data given a run id ($run) of a given
   * type ($type).
   *
   * Also, a brief description of the run is returned via the
   * $run_desc out parameter.
   */
  public function get_run($run_id, $type, &$run_desc);

  /**
   * Save XHProf data for a profiler run of specified type
   * ($type).
   *
   * The caller may optionally pass in run_id (which they
   * promise to be unique). If a run_id is not passed in,
   * the implementation of this method must generated a
   * unique run id for this saved XHProf run.
   *
   * Returns the run id for the saved XHProf run, or null if it could not
   * be saved. Implementations should log failures instead of throwing.
   *
   */
  public function save_run($xhprof_data, $type, $run_id = null);
}


/**
 * XHProfRuns_Default is the default implementation of the
 * iXHProfRuns interface for saving/fetching XHProf runs.
 *
 * It stores/retrieves runs to/from a filesystem directory
 * specified by the "xhprof.output_dir" ini parameter.
 *
 * @author Kannan
 */
class XHProfRuns_Default implements iXHProfRuns {

  private $dir = '';
  private $suffix = 'xhprof';

  private function gen_run_id($type) {
    return uniqid();
  }

  /**
   * Run ids and namespaces end up in a file name, so only allow characters
   * that cannot change the directory (no slashes, backslashes or NUL bytes).
   */
  private function is_valid_name($name) {
    return is_scalar($name)
      && preg_match('/\A[A-Za-z0-9_.-]+\z/', (string) $name) === 1;
  }

  private function file_name($run_id, $type) {

    $file = "$run_id.$type." . $this->suffix;

    if (!empty($this->dir)) {
      $file = $this->dir . "/" . $file;
    }
    return $file;
  }

  public function __construct($dir = null) {

    // if user hasn't passed a directory location,
    // we use the xhprof.output_dir ini setting
    // if specified, else we default to the directory
    // in which the error_log file resides.

    if (empty($dir) && !($dir = getenv('XHPROF_OUTPUT_DIR'))) {
      $dir = ini_get("xhprof.output_dir");
      if (empty($dir)) {

        $dir = sys_get_temp_dir();

        xhprof_error("Warning: Must specify directory location for XHProf runs. ".
                     "Trying {$dir} as default. You can either pass the " .
                     "directory location as an argument to the constructor ".
                     "for XHProfRuns_Default() or set xhprof.output_dir ".
                     "ini param, or set XHPROF_OUTPUT_DIR environment variable.");
      }
    }
    $this->dir = $dir;
  }

  /**
   * Whether runs can be saved: the directory exists and is writable.
   */
  public function is_writable() {
    return is_dir($this->dir) && is_writable($this->dir);
  }

  public function get_run($run_id, $type, &$run_desc) {
    if (!$this->is_valid_name($run_id) || !$this->is_valid_name($type)) {
      xhprof_error("Invalid run id or namespace");
      $run_desc = "Invalid Run Id";
      return null;
    }

    $file_name = $this->file_name($run_id, $type);

    if (!file_exists($file_name)) {
      xhprof_error("Could not find file $file_name");
      $run_desc = "Invalid Run Id = $run_id";
      return null;
    }

    $contents = file_get_contents($file_name);
    $run_desc = "XHProf Run (Namespace=$type)";

    if ($contents === false) {
      xhprof_error("Could not read file $file_name");
      return null;
    }

    return $this->decode($contents);
  }

  public function save_run($xhprof_data, $type, $run_id = null) {

    $encoded = $this->encode($xhprof_data);

    if ($run_id === null) {
      $run_id = $this->gen_run_id($type);
    }

    if (!$this->is_valid_name($run_id) || !$this->is_valid_name($type)) {
      xhprof_error("Invalid run id or namespace");
      return null;
    }

    $file_name = $this->file_name($run_id, $type);

    // Write to a temporary file first so a reader never sees half a run, and
    // so a full disk leaves nothing behind. Failures are logged, never
    // thrown or shown: saving a profile must not break the request.
    $temp_name = $file_name . '.' . uniqid('', true) . '.tmp';
    $written = @file_put_contents($temp_name, $encoded);

    if ($written === false || $written !== strlen($encoded)) {
      @unlink($temp_name);
      xhprof_error("Could not write $file_name");
      return null;
    }

    if (!@rename($temp_name, $file_name)) {
      @unlink($temp_name);
      xhprof_error("Could not move run to $file_name");
      return null;
    }

    // echo "Saved run in {$file_name}.\nRun id = {$run_id}.\n";
    return $run_id;
  }

  /**
   * Encode the raw profiler data for storage as JSON.
   *
   * Unlike unserialize(), reading JSON back cannot instantiate objects, so
   * a run file that someone else can write to is not an attack vector.
   * Function names that are not valid UTF-8 are substituted rather than
   * failing the whole run.
   */
  private function encode($xhprof_data) {
    $json = json_encode($xhprof_data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

    if ($json === false) {
      // Not expected for plain arrays of numbers. Keep the run readable
      // in the legacy format instead of losing it.
      xhprof_error("Could not encode run as JSON: " . json_last_error_msg());
      return serialize($xhprof_data);
    }

    return $json;
  }

  /**
   * Decode the contents of a run file.
   *
   * Files written by older versions are PHP serialized arrays. They are
   * still read, but never allowed to instantiate classes.
   */
  private function decode($contents) {
    $head = ltrim(substr($contents, 0, 16));

    // Profiler data is an array keyed by "parent==>child", so JSON files
    // start with "{" ("[" for an empty run). Serialized arrays start with "a:".
    if ($head !== '' && ($head[0] === '{' || $head[0] === '[')) {
      $data = json_decode($contents, true);

      if (!is_array($data)) {
        xhprof_error("Could not decode run: " . json_last_error_msg());
        return null;
      }

      return $data;
    }

    $data = @unserialize($contents, ['allowed_classes' => false]);

    return is_array($data) ? $data : null;
  }

  function list_runs() {
    if (is_dir($this->dir)) {
        echo "<hr/>Existing runs:\n<ul>\n";
        $files = glob("{$this->dir}/*.{$this->suffix}");
		usort($files, function($a, $b) {return filemtime($b) - filemtime($a);});
        foreach ($files as $file) {
            list($run,$source) = explode('.', basename($file));
            echo '<li><a href="' . htmlentities($_SERVER['SCRIPT_NAME'])
                . '?run=' . htmlentities($run) . '&source='
                . htmlentities($source) . '">'
                . htmlentities(basename($file)) . "</a><small> "
                . date("Y-m-d H:i:s", filemtime($file)) . "</small></li>\n";
        }
        echo "</ul>\n";
    }
  }
}
