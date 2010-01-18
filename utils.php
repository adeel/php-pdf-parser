<?php
function read_until_whitespace($stream, $maxchars=null) {
  $txt = '';
  while (true) {
    $tok = fread($stream, 1);
    if (trim($tok) == '') {
      break;
    }
    $txt .= $tok;
    if (strlen($txt) == $maxchars) {
      break;
    }
  }
  return $txt;
}

function read_non_whitespace($stream) {
  $tok = ' ';
  while (($tok == "\n") || ($tok == "\r") || ($tok == " ") || ($tok == "\t")) {
    $tok = fread($stream, 1);
  }
  return $tok;
}

function utf16_decode($str) {
    if (strlen($str) < 2) {
      return $str;
    }
    
    $bom_be = true;
    $c0 = ord($str{0});
    $c1 = ord($str{1});
    if (($c0 == 0xfe) && ($c1 == 0xff)) {
      $str = substr($str, 2);
    } else if (($c0 == 0xff) && ($c1 == 0xfe)) {
      $str = substr($str, 2);
      $bom_be = false;
    }
    $len = strlen($str);
    $newstr = '';
    for ($i=0; $i<$len; $i+=2) {
        if ($bom_be) {
          $val = ord($str{$i}) << 4;
          $val += ord($str{$i+1});
        } else {
          $val = ord($str{$i+1}) << 4;
          $val += ord($str{$i});
        }
        $newstr .= ($val == 0x228) ? "\n" : chr($val);
    }
    return $newstr;
}

# debugging
function hi($o) {
  print _hi($o) . "\n";
}

function _hi($o) {
  if ($o === null) {
    return "None";
  } else if (is_array($o)) {
    $out = "{";
    foreach ($o as $k=>$v) {
      $out .= _hi($k) . " => " . _hi($v) . ", ";
    }
    $out .= "}";
    return $out;
  } else {
    return $o;
  }
}
?>