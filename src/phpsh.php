#!/usr/bin/env php
<?php
// Copyright 2004-2007 Facebook. All Rights Reserved.
// this is used by phpsh.py to exec php commands and maintain state
// @author  ccheever
// @author  dcorson
// @author  warman (added multiline input support \ing)
// @date    Thu Jun 15 22:27:46 PDT 2006
//
// usage: this is only called from phpsh (the python end), as:
// phpsh.php <comm-file> <codebase-mode> [-c]
//
// use '' for default codebase-mode, define others in /etc/phpsh/rc.php
// -c turns off color

// set the TFBENV to script
$_SERVER['TFBENV'] = 16777216;

// FIXME: www/lib/thrift/packages/falcon/falcon.php is huge
//  this is probably not the right fix, but we need it for now
$memory_limit = ini_get('memory_limit');
switch(strtolower($memory_limit[strlen($memory_limit)-1])) {
  case 'g':
    $memory_limit *= 1024;
  case 'm':
    $memory_limit *= 1024;
  case 'k':
    $memory_limit *= 1024;
}
ini_set('memory_limit', $memory_limit * 2);

if (version_compare(PHP_VERSION, '5.0.0', '<')) {
  fwrite(STDERR, 'Fatal error: phpsh requires PHP 5 or greater');
  exit;
}

$missing = array_diff(array('pcre','posix','tokenizer'),
                      get_loaded_extensions());
if ($missing) {
  fwrite(STDERR, 'Fatal error: phpsh requires the following extensions: '.
         implode(', ', $missing));
  exit;
}

define('PCNTL_EXISTS', in_array('pcntl', get_loaded_extensions()));

// we buffer the output on includes so that output that gets generated by
// includes doesn't interfere with the secret messages we pass between php and
// python we'll capture any output and show it when we construct the shell
// object
ob_start();

$___phpsh___codebase_mode = $argv[2];
$___phpsh___homerc = getenv('HOME').'/.phpsh/rc.php';
if (file_exists($___phpsh___homerc)) {
  require_once $___phpsh___homerc;
} else {
  require_once '/etc/phpsh/rc.php';
}

$___phpsh___do_color = true;
$___phpsh___do_autocomplete = true;
$___phpsh___do_undefined_function_check = true;
$___phpsh___options_possible = true;
$___phpsh___fork_every_command = false;
foreach (array_slice($GLOBALS['argv'], 3) as $___phpsh___arg) {
  $___phpsh___did_arg = false;
  if ($___phpsh___options_possible) {
    switch ($___phpsh___arg) {
    case '-c':
      $___phpsh___do_color = false;
      $___phpsh___did_arg = true;
      break;
    case '-A':
      $___phpsh___do_autocomplete = false;
      $___phpsh___did_arg = true;
      break;
    case '-u':
      $___phpsh___do_undefined_function_check = false;
      $___phpsh___did_arg = true;
      break;
    case '-f':
      $___phpsh___fork_every_command = true;
      $___phpsh___did_arg = true;
      break;
    case '--':
      $___phpsh___options_possible = false;
      $___phpsh___did_arg = true;
      break;
    }
    if ($___phpsh___did_arg) {
      continue;
    }
  }

  include_once $___phpsh___arg;
}
unset($___phpsh___arg);
unset($___phpsh___did_arg);
unset($___phpsh___options_possible);

$___phpsh___output_from_includes = ob_get_contents();
ob_end_clean();

// We make our pretty-printer override-able in rc.php just in case anyone cares
// enough to tweak it.
if (!function_exists('___phpsh___pretty_print')) {
  function ___phpsh___pretty_print($x) {
    return ___phpsh___parse_dump($x, ___phpsh___var_dump_cap($x));
  }
  function ___phpsh___var_dump_cap($x) {
    ob_start();
    var_dump($x);
    $str = ob_get_contents();
    ob_end_clean();
    return rtrim($str);
  }
  function ___phpsh___str_lit($str) {
    static $str_lit_esc_chars =
      "\\\"\000\001\002\003\004\005\006\007\010\011\012\013\014\015\016\017\020\021\022\023\024\025\026\027\030\031\032\033\034\035\036\037";
    // todo?: addcslashes makes weird control chars in octal instead of hex.
    //        is hex kwlr in general?  if so might want our own escaper here
    return '"'.addcslashes($str, $str_lit_esc_chars).'"';
  }
  function ___phpsh___parse_dump($x, $dump, &$pos=0, $normal_end_check=true,
      $_depth=0) {
    static $indent_str = '  ';
    $depth_str = str_repeat($indent_str, $_depth);
    // ad hoc parsing not very fun.. use lemon or something? or is that overkill
    switch ($dump[$pos]) {
    case 'N':
      ___phpsh___parse_dump_assert($dump, $pos, 'NULL');
      return 'null';
    case '&':
      $pos++;
      return '&'.___phpsh___parse_dump($x, $dump, $pos, $normal_end_check,
        $_depth);
    case 'a':
      ___phpsh___parse_dump_assert($dump, $pos, 'array');
      $arr_len = (int)___phpsh___parse_dump_delim_grab($dump, $pos, false);
      ___phpsh___parse_dump_assert($dump, $pos, " {\n");
      $arr_lines = ___phpsh___parse_dump_arr_lines($x, $dump, $pos, $arr_len,
        $_depth, $depth_str, $indent_str);
      ___phpsh___parse_dump_assert($dump, $pos, $depth_str."}",
        $normal_end_check);
      return implode("\n", array_merge(
        array('array('),
        $arr_lines,
        array($depth_str.')')
      ));
    case 'o':
      ___phpsh___parse_dump_assert($dump, $pos, 'object');
      $obj_type_str = ___phpsh___parse_dump_delim_grab($dump, $pos);
      $obj_num_str =
        ___phpsh___parse_dump_delim_grab($dump, $pos, false, '# ');
      $obj_len = (int)___phpsh___parse_dump_delim_grab($dump, $pos);
      ___phpsh___parse_dump_assert($dump, $pos, " {\n");
      $obj_lines = ___phpsh___parse_dump_obj_lines($x, $dump, $pos, $obj_len,
        $_depth, $depth_str, $indent_str);
      ___phpsh___parse_dump_assert($dump, $pos, $depth_str.'}',
        $normal_end_check);
      return implode("\n", array_merge(
        array('<object #'.$obj_num_str.' of type '.$obj_type_str.'> {'),
        $obj_lines,
        array($depth_str.'}')
      ));
    case 'b':
      ___phpsh___parse_dump_assert($dump, $pos, 'bool(');
      switch ($dump[$pos]) {
      case 'f':
        ___phpsh___parse_dump_assert($dump, $pos, 'false)', $normal_end_check);
        return 'false';
      case 't':
        ___phpsh___parse_dump_assert($dump, $pos, 'true)', $normal_end_check);
        return 'true';
      }
    case 'f':
      ___phpsh___parse_dump_assert($dump, $pos, 'float');
      return ___phpsh___parse_dump_delim_grab($dump, $pos, $normal_end_check);
    case 'i':
      ___phpsh___parse_dump_assert($dump, $pos, 'int');
      return ___phpsh___parse_dump_delim_grab($dump, $pos, $normal_end_check);
    case 'r':
      ___phpsh___parse_dump_assert($dump, $pos, 'resource');
      $rsrc_num_str = ___phpsh___parse_dump_delim_grab($dump, $pos);
      ___phpsh___parse_dump_assert($dump, $pos, ' of type ');
      $rsrc_type_str =
        ___phpsh___parse_dump_delim_grab($dump, $pos, $normal_end_check);
      return '<resource #'.$rsrc_num_str.' of type '.$rsrc_type_str.'>';
    case 's':
      ___phpsh___parse_dump_assert($dump, $pos, 'string');
      $str_len = (int)___phpsh___parse_dump_delim_grab($dump, $pos);
      ___phpsh___parse_dump_assert($dump, $pos, ' "');
      $str = substr($dump, $pos, $str_len);
      $pos += $str_len;
      ___phpsh___parse_dump_assert($dump, $pos, '"', $normal_end_check);
      return ___phpsh___str_lit($str);
    default:
      throw new Exception('parse error unrecognized type at position '.$pos.
                          ': '.substr($dump, $pos));
    }
  }
  function ___phpsh___parse_dump_arr_lines($x, $dump, &$pos, $arr_len, $depth,
      $depth_str, $indent_str) {
    $arr_lines = array();
    foreach (array_keys($x) as $key) {
      if (is_int($key)) {
        $key_str_php = (string)$key;
        $key_str_correct = $key_str_php;
      } else {
        $key_str_php = '"'.$key.'"';
        $key_str_correct = ___phpsh___str_lit($key);
      }
      ___phpsh___parse_dump_assert($dump, $pos, $depth_str.$indent_str.'['.
        $key_str_php.']=>'."\n".$depth_str.$indent_str);
      if ($dump[$pos] == '*') {
        ___phpsh___parse_dump_assert($dump, $pos, '*RECURSION*');
        $val = '*RECURSION*';
      } else {
        $val = ___phpsh___parse_dump($x[$key], $dump, $pos, false, $depth + 1);
      }
      ___phpsh___parse_dump_assert($dump, $pos, "\n");
      $arr_lines[] = $depth_str.$indent_str.$key_str_correct.' => '.$val.',';
    }
    return $arr_lines;
  }
  function ___phpsh___parse_dump_obj_lines($x, $dump, &$pos, $arr_len, $depth,
      $depth_str, $indent_str) {
    $arr_lines = array();
    // this exposes private/protected members (a hack within a hack)
    $x_arr = ___phpsh___obj_to_arr($x);
    for ($i = 0; $i < $arr_len; $i++) {
      ___phpsh___parse_dump_assert($dump, $pos, $depth_str.$indent_str.'[');
      $key = ___phpsh___parse_dump_delim_grab($dump, $pos, false, '""');
      if ($dump[$pos] == ':') {
        $key .= ':'.___phpsh___parse_dump_delim_grab($dump, $pos, false, ':]');
        $pos--;
      }
      ___phpsh___parse_dump_assert($dump, $pos, "]=>\n".$depth_str.$indent_str);
      if ($dump[$pos] == '*') {
        ___phpsh___parse_dump_assert($dump, $pos, '*RECURSION*');
        $val = '*RECURSION*';
      } else {
        $colon_pos = strpos($key, ':');
        if ($colon_pos === false) {
          $key_unannotated = $key;
        } else {
          $key_unannotated = substr($key, 0, $colon_pos);
        }
        $val = ___phpsh___parse_dump($x_arr[$key_unannotated], $dump, $pos,
          false, $depth + 1);
      }
      ___phpsh___parse_dump_assert($dump, $pos, "\n");
      $arr_lines[] = $depth_str.$indent_str.$key.' => '.$val.',';
    }
    return $arr_lines;
  }
  function ___phpsh___obj_to_arr($x) {
    if (is_object($x)) {
      $raw_array = (array)$x;
      $result = array();
      foreach ($raw_array as $key => $value) {
        $key = preg_replace('/\\000.*\\000/', '', $key);
        $result[$key] = $value;
      }
      return $result;
    }
    return (array)$x;
  }
  function ___phpsh___parse_dump_assert($dump, &$pos, $str, $end=false) {
    $len = strlen($str);
    if ($str !== '' && substr($dump, $pos, $len) !== $str) {
      // todo; own exception type?
      throw new Exception(
        "parse error looking for '".$str."' at position ".$pos.
        '; found instead: '.substr($dump, $pos));
    }
    $pos += $len;
    if ($end && strlen($dump) > $pos) {
      throw new Exception('parse error unexpected input after position '.$pos);
    }
    return true;
  }
  function ___phpsh___parse_dump_delim_grab($dump, &$pos, $end=false,
      $delims='()') {
    assert(strlen($delims) === 2);
    $pos_open_paren = $pos;
    ___phpsh___parse_dump_assert($dump, $pos, $delims[0]);
    $pos_close_paren = strpos($dump, $delims[1], $pos_open_paren + 1);
    if ($pos_close_paren === false) {
      throw new Exception(
        "parse error expecting '".$delims[1]."' after position ".$pos);
    }
    $pos = $pos_close_paren + 1;
    if ($end) {
      ___phpsh___parse_dump_assert($dump, $pos, '', true);
    }
    return substr($dump, $pos_open_paren + 1,
      $pos_close_paren - $pos_open_paren - 1);
  }
  // this is provided for our in-house unit testing and in case it's useful to
  // anyone modifying the default pretty-printer
  function ___phpsh___assert_eq(&$i, $f, $x, $y) {
    $f_of_x = $f($x);
    if ($y === $f_of_x) {
      $i++;
      return true;
    } else {
      error_log('Expected '.$f.'('.print_r($x, true).') to be '.
        print_r($y, true).', but instead got '.print_r($f_of_x, true));
      return false;
    }
  }
  function ___phpsh___assert_re(&$i, $f, $x, $re) {
    $f_of_x = $f($x);
    if (1 === preg_match($re, $f_of_x)) {
      $i++;
      return true;
    } else {
      error_log('Expected '.$f.'('.print_r($x, true).') to match re '.$re.
        ', but instead got '.print_r($f_of_x, true));
      return false;
    }
  }
  function ___phpsh___pretty_print_test() {
    $i = 0;
    assert(___phpsh___assert_eq($i, '___phpsh___pretty_print',
      null,
      'null'
    ));
    assert(___phpsh___assert_eq($i, '___phpsh___pretty_print',
      true,
      'true'
    ));
    assert(___phpsh___assert_eq($i, '___phpsh___pretty_print',
      false,
      'false'
    ));
    assert(___phpsh___assert_eq($i, '___phpsh___pretty_print',
      4,
      '4'
    ));
    assert(___phpsh___assert_eq($i, '___phpsh___pretty_print',
      3.14,
      '3.14'
    ));
    assert(___phpsh___assert_eq($i, '___phpsh___pretty_print',
      "A\\\"'\'B\nC",
      '"A\\\\\"\'\\\\\'B\\nC"'
    ));
    assert(___phpsh___assert_re($i, '___phpsh___pretty_print',
      fopen('phpshtest.deleteme', 'w'),
      '<resource #\d+ of type stream>'
    ));
    assert(___phpsh___assert_eq($i, '___phpsh___pretty_print',
      array(04 => 'lol', '04' => 'lolo'),
      "array(\n  4 => \"lol\",\n  \"04\" => \"lolo\",\n)"
    ));
    $arr = array();
    $arr['self'] = $arr;
    // note the manifested depth might actually be variable and unknowable.
    // so we may have to loosen this test..
    assert(___phpsh___assert_eq($i, '___phpsh___pretty_print', $arr,
      "array(\n  \"self\" => array(\n    \"self\" => *RECURSION*,\n  ),\n)"
    ));
    $arr = array();
    $arr['fake'] = "Array\n *RECURSION*";
    $arr['sref'] = &$arr;
    $arr['self'] = $arr;
    assert(___phpsh___assert_eq($i, '___phpsh___pretty_print', $arr,
      "array(\n  \"fake\" => \"Array\\n *RECURSION*\",\n  \"sref\" => &array(\n    \"fake\" => \"Array\\n *RECURSION*\",\n    \"sref\" => &array(\n      \"fake\" => \"Array\\n *RECURSION*\",\n      \"sref\" => *RECURSION*,\n      \"self\" => array(\n        \"fake\" => \"Array\\n *RECURSION*\",\n        \"sref\" => *RECURSION*,\n        \"self\" => array(\n          \"fake\" => \"Array\\n *RECURSION*\",\n          \"sref\" => *RECURSION*,\n          \"self\" => *RECURSION*,\n        ),\n      ),\n    ),\n    \"self\" => array(\n      \"fake\" => \"Array\\n *RECURSION*\",\n      \"sref\" => &array(\n        \"fake\" => \"Array\\n *RECURSION*\",\n        \"sref\" => *RECURSION*,\n        \"self\" => array(\n          \"fake\" => \"Array\\n *RECURSION*\",\n          \"sref\" => *RECURSION*,\n          \"self\" => *RECURSION*,\n        ),\n      ),\n      \"self\" => array(\n        \"fake\" => \"Array\\n *RECURSION*\",\n        \"sref\" => &array(\n          \"fake\" => \"Array\\n *RECURSION*\",\n          \"sref\" => *RECURSION*,\n          \"self\" => *RECURSION*,\n        ),\n        \"self\" => *RECURSION*,\n      ),\n    ),\n  ),\n  \"self\" => array(\n    \"fake\" => \"Array\\n *RECURSION*\",\n    \"sref\" => &array(\n      \"fake\" => \"Array\\n *RECURSION*\",\n      \"sref\" => &array(\n        \"fake\" => \"Array\\n *RECURSION*\",\n        \"sref\" => *RECURSION*,\n        \"self\" => array(\n          \"fake\" => \"Array\\n *RECURSION*\",\n          \"sref\" => *RECURSION*,\n          \"self\" => *RECURSION*,\n        ),\n      ),\n      \"self\" => array(\n        \"fake\" => \"Array\\n *RECURSION*\",\n        \"sref\" => &array(\n          \"fake\" => \"Array\\n *RECURSION*\",\n          \"sref\" => *RECURSION*,\n          \"self\" => *RECURSION*,\n        ),\n        \"self\" => *RECURSION*,\n      ),\n    ),\n    \"self\" => array(\n      \"fake\" => \"Array\\n *RECURSION*\",\n      \"sref\" => &array(\n        \"fake\" => \"Array\\n *RECURSION*\",\n        \"sref\" => &array(\n          \"fake\" => \"Array\\n *RECURSION*\",\n          \"sref\" => *RECURSION*,\n          \"self\" => *RECURSION*,\n        ),\n        \"self\" => *RECURSION*,\n      ),\n      \"self\" => *RECURSION*,\n    ),\n  ),\n)"
    ));
    assert(___phpsh___assert_eq($i, '___phpsh___pretty_print',
      array('a[b]c' => 4),
      "array(\n  \"a[b]c\" => 4,\n)"
    ));
    $var_to_ref = 4;
    assert(___phpsh___assert_eq($i, '___phpsh___pretty_print',
      array('hi' => &$var_to_ref),
      "array(\n  \"hi\" => &4,\n)"
    ));
    return $i;
  }
}

// This function is here just so that the debug proxy can set a
// breakpoint on something that executes right after the function being
// debugged has been evaluated. Hitting this breakpoint makes debug
// proxy remove the breakpoint it previously set on the function under
// debugging.
function ___phpsh___eval_completed() {
}

/**
 * An instance of a phpsh interactive loop
 *
 * @author     ccheever
 * @author     dcorson
 *
 * This class mostly exists as a proxy for a namespace
 */
class ___Phpsh___ {
  var $_handle = STDIN;
  var $_comm_handle;
  var $_MAX_LINE_SIZE = 262144;

  /**
   * Constructor - actually runs the interactive loop so that all we have to do
   * is construct it to run
   * @param    list     $extra_include     Extra files that we want to include
   *
   * @author   ccheever
   * @author   dcorson
   */
  function __construct($output_from_includes='', $do_color, $do_autocomplete,
      $do_undefined_function_check, $fork_every_command, $comm_filename) {
    $this->_comm_handle = fopen($comm_filename, 'w');
    $this->__send_autocomplete_identifiers($do_autocomplete);
    $this->do_color = $do_color;
    $this->do_undefined_function_check = $do_undefined_function_check;
    if (!PCNTL_EXISTS && $fork_every_command) {
      $fork_every_command = false;
      fwrite(STDERR,
             "Install pcntl to enable forking on every command.\n");
    }
    $this->fork_every_command = $fork_every_command;

    // now it's safe to send any output the includes generated
    echo $output_from_includes;
    fwrite($this->_comm_handle, "ready\n");
  }

  /**
   * Destructor - just closes the handle to STDIN
   *
   * @author    ccheever
   */
  function __destruct() {
    fclose($this->_handle);
  }

  /**
   * Sends the list of identifiers that phpsh should know to tab-complete to
   * python
   *
   * @author    ccheever
   */
  function __send_autocomplete_identifiers($do_autocomplete) {
    // send special string to signal that we're sending the autocomplete
    // identifiers
    echo "#start_autocomplete_identifiers\n";

    if ($do_autocomplete) {
      // send function names -- both user defined and built-in
      // globals, constants, classes, interfaces
      $defined_functions = get_defined_functions();
      $methods = array();
      foreach (($classes = get_declared_classes()) as $class) {
        foreach (get_class_methods($class) as $class_method) {
          $methods[] = $class_method;
        }
      }
      foreach (array_merge($defined_functions['user'],
                           $defined_functions['internal'],
                           array_keys($GLOBALS),
                           array_keys(get_defined_constants()),
                           $classes,
                           get_declared_interfaces(),
                           $methods,
                           array('instanceof')) as $identifier) {
        // exclude the phpsh internal variables from the autocomplete list
        if (strtolower(substr($identifier, 0, 11)) != '___phpsh___') {
          echo "$identifier\n";
        } else {
          unset($$identifier);
        }
      }
    }

    // string signalling the end of autocmplete identifiers
    echo "#end_autocomplete_identifiers\n";
  }

  /**
   * @param   string  $buffer  phpsh input to check function calls in
   * @return  string  name of first undefined function,
   *                  or '' if all functions exist
   */
  function undefined_function_check($buffer) {
    $toks = token_get_all('<?php '.$buffer);
    $cur_func = null;
    $ignore_next_func = false;
    foreach ($toks as $tok) {
      if (is_string($tok)) {
        if ($tok === '(') {
          if ($cur_func !== null) {
            if (!function_exists($cur_func)) {
              return $cur_func;
            }
          }
        }
        $cur_func = null;
      } elseif (is_array($tok)) {
        list($tok_type, $tok_val, $tok_line) = $tok;
        if ($tok_type === T_STRING) {
          if ($ignore_next_func) {
            $cur_func = null;
            $ignore_next_func = false;
          } else {
            $cur_func = $tok_val;
          }
        } else if (
            $tok_type === T_FUNCTION ||
            $tok_type === T_NEW ||
            $tok_type === T_OBJECT_OPERATOR ||
            $tok_type === T_DOUBLE_COLON) {
          $ignore_next_func = true;
        } else if (
            $tok_type !== T_WHITESPACE &&
            $tok_type !== T_COMMENT) {
          $cur_func = null;
          $ignore_next_func = false;
        }
      }
    }
    return '';
  }

  /**
   * The main interactive loop
   *
   * @author    ccheever
   * @author    dcorson
   */
  function interactive_loop() {
    extract($GLOBALS);

    if(PCNTL_EXISTS) {
        // python spawned-processes ignore SIGPIPE by default, this makes sure
        //  the php process exits when the terminal is closed
        pcntl_signal(SIGPIPE,SIG_DFL);
    }

    while (!feof($this->_handle)) {
      // indicate to phpsh (parent process) that we are ready for more input
      fwrite($this->_comm_handle, "ready\n");

      // multiline inputs are encoded to one line
      $buffer_enc = fgets($this->_handle, $this->_MAX_LINE_SIZE);
      $buffer = stripcslashes($buffer_enc);

      $err_msg = '';
      if ($this->do_undefined_function_check) {
        $undefd_func = $this->undefined_function_check($buffer);
        if ($undefd_func) {
          $err_msg =
            'Not executing input: Possible call to undefined function '.
            $undefd_func."()\n".
            'See /etc/phpsh/config.sample to disable UndefinedFunctionCheck.';
        }
      }
      if ($err_msg) {
        if ($this->do_color) {
          echo "\033[31m"; // red
        }
        echo $err_msg;
        if ($this->do_color) {
          echo "\033[0m";
        }
        echo "\n";
        continue;
      }

      // evaluate what the user entered
      if ($this->do_color) {
        echo "\033[33m"; // yellow
      }

      if ($this->fork_every_command) {
        $parent_pid = posix_getpid();
        $pid = pcntl_fork();
        $evalue = null;
        if ($pid) {
          pcntl_wait($status);
        } else {
          try {
            $evalue = eval($buffer);
          } catch (Exception $e) {
            // unfortunately, almost all exceptions that aren't explicitly
            // thrown by users are uncatchable :(
            fwrite(STDERR, 'Uncaught exception: '.get_class($e).': '.
              $e->getMessage()."\n".$e->getTraceAsString()."\n");
            $evalue = null;
          }

          // if we are still alive..
          $childpid = posix_getpid();
          fwrite($this->_comm_handle, "child $childpid\n");
        }
      } else {
        try {
          $evalue = eval($buffer);
        } catch (Exception $e) {
          // unfortunately, almost all exceptions that aren't explicitly thrown
          // by users are uncatchable :(
          fwrite(STDERR, 'Uncaught exception: '.get_class($e).': '.
            $e->getMessage()."\n".$e->getTraceAsString()."\n");
          $evalue = null;
        }
      }

      if ($buffer != "xdebug_break();\n") {
        ___phpsh___eval_completed();
      }

      // if any value was returned by the evaluated code, echo it
      if (isset($evalue)) {
        if ($this->do_color) {
          echo "\033[36m"; // cyan
        }
        echo ___phpsh___pretty_print($evalue);
      }
      // set $_ to be the value of the last evaluated expression
      $_ = $evalue;
      // back to normal for prompt
      if ($this->do_color) {
        echo "\033[0m";
      }
      // newline so we end cleanly
      echo "\n";
    }
  }
}

$___phpsh___ = new ___Phpsh___($___phpsh___output_from_includes,
  $___phpsh___do_color, $___phpsh___do_autocomplete,
  $___phpsh___do_undefined_function_check, $___phpsh___fork_every_command,
  $argv[1]);
unset($___phpsh___do_color);
unset($___phpsh___do_autocomplete);
unset($___phpsh___do_undefined_function_check);
unset($___phpsh___fork_every_command);
$___phpsh___->interactive_loop();

