<?php
include_once 'filters.php';
include_once 'utils.php';

function read_object($stream, $pdf) {
  $tok = fread($stream, 1);
  fseek($stream, -1, 1);
  if (($tok == 't') || ($tok == 'f')) {
    return BooleanObject::read_from_stream($stream);
  } else if ($tok == '(') {
    return read_string_from_stream($stream);
  } else if ($tok == '/') {
    return NameObject::read_from_stream($stream);
  } else if ($tok == '[') {
    return ListObject::read_from_stream($stream, $pdf);
  } else if ($tok == 'n') {
    return NullObject::read_from_stream($stream);
  } else if ($tok == '<') {
    $peek = fread($stream, 2);
    fseek($stream, -2, 1);
    if ($peek == '<<') {
      $r = DictionaryObject::read_from_stream($stream, $pdf);
      return $r;
    } else {
      return read_hex_string_from_stream($stream);
    }
  } else if ($tok == '%') {
    while (($tok != "\r") && ($tok != "\n")) {
      $tok = fread($stream, 1);
    }
    $tok = read_non_whitespace($stream);
    fseek($stream, -1, 1);
    return read_object($stream, $pdf);
  } else {
    if (($tok == '+') || ($tok == '-')) {
      return NumberObject::read_from_stream($stream);
    }
    $peek = fread($stream, 20);
    fseek($stream, -strlen($peek), 1);
    if (preg_match("/^(\d+)\s(\d+)\sR[^a-zA-Z]/", $peek)) {
      return IndirectObject::read_from_stream($stream, $pdf);
    } else {
      return NumberObject::read_from_stream($stream);
    }
  }
}

$name_delimiters = array("(", ")", "<", ">", "[", "]", "{", "}", "/", "%");

class NameObject extends Object {
  function NameObject($data) {
    $this->data = $data;
  }
  
  function read_from_stream($stream) {
    global $name_delimiters;
    
    $name = fread($stream, 1);
    if ($name != '/') {
      die("Error reading PDF: name read error.");
    }
    while (true) {
      $tok = fread($stream, 1);
      if ((trim($tok) == '') || (in_array($tok, $name_delimiters))) {
        fseek($stream, -1, 1);
        break;
      }
      $name .= $tok;
    }
    return $name;
  }
}

class DictionaryObject extends Object {
  function DictionaryObject($data=array()) {
    $this->data = $data;
  }
  
  function read_from_stream($stream, $pdf) {
    $tmp = fread($stream, 2);
    if ($tmp != '<<') {
      die("Error reading PDF: dictionary read error.");
    }
    $data = array();
    while (true) {
      $tok = read_non_whitespace($stream);
      if ($tok == '>') {
        fread($stream, 1);
        break;
      }
      fseek($stream, -1, 1);
      $key = read_object($stream, $pdf);
      $tok = read_non_whitespace($stream);
      fseek($stream, -1, 1);
      $value = read_object($stream, $pdf);
      if (in_array($key, array_keys($data))) {
        die("Error reading PDF: multiple definitions in dictionary.");
      }
      $data[$key] = $value;
    }
    
    $pos = ftell($stream);
    $s = read_non_whitespace($stream);
    if (($s == 's') && (fread($stream, 5) == 'tream')) {
      $eol = fread($stream, 1);
      while ($eol == ' ') {
        $eol = fread($stream, 1);
      }
      assert(($eol == "\n") || ($eol == "\r"));
      if ($eol == "\r") {
        fread($stream, 1);
      }
      $length = $data['/Length'];
      if (is_a($length, 'IndirectObject')) {
        $t = ftell($stream);
        $length = $pdf->get_object($length);
        fseek($stream, $t, 0);
      }
      $data['__streamdata__'] = fread($stream, $length);
      $e = read_non_whitespace($stream);
      $ndstream = fread($stream, 8);
      if (($e + $ndstream) != "endstream") {
        $pos = ftell($stream);
        fseek($stream, -10, 1);
        $end = fread($stream, 9);
        if ($end == "endstream") {
          $data['__streamdata__'] = substr($data['__streamdata__'], 0, -1);
        } else {
          fseek($stream, $pos, 0);
          die("Error reading PDF: Unable to find 'endstream' marker after stream.");
        }
      }
    } else {
      fseek($stream, $pos, 0);
    }
    if (in_array('__streamdata__', array_keys($data))) {
      return StreamObject::init_from_dict($data);
    } else {
      return $data;
    }
  }
  
}

class NullObject extends Object {
  function read_from_stream($stream) {
    $nulltxt = fread($stream, 4);
    if ($nulltxt != "null") {
      die("Error reading PDF: error reading null object.");
    }
    return new NullObject();
  }
}

class BooleanObject extends Object {
  function BooleanObject($value) {
    $this->value = $value;
  }
  
  function read_from_stream($stream) {
    $word = fread($stream, 4);
    if ($word == "true") {
      return new BooleanObject(true);
    } else if ($word == "fals") {
      fread($stream, 1);
      return new BooleanObject(false);
    }
    assert(false);
  }
}

class ListObject extends Object {
  function read_from_stream($stream, $pdf) {
    $arr = array();
    $tmp = fread($stream, 1);
    if ($tmp != '[') {
      die("Error reading PDF: error reading array.");
    }
    while (true) {
      $tok = fread($stream, 1);
      while (trim($tok) == '') {
        $tok = fread($stream, 1);
      }
      fseek($stream, -1, 1);
      $peekahead = fread($stream, 1);
      if ($peekahead == ']') {
        break;
      }
      fseek($stream, -1, 1);
      $arr[] = read_object($stream, $pdf);
    }
    return $arr;
  }
}

class IndirectObject extends Object {
  function IndirectObject($idnum, $generation, $pdf) {
    $this->idnum = $idnum;
    $this->generation = $generation;
    $this->pdf = $pdf;
  }
  
  function get_object() {
    return $this->pdf->get_object($this);
  }
  
  function read_from_stream($stream, $pdf) {
    $idnum = '';
    while (true) {
      $tok = fread($stream, 1);
      if (trim($tok) == '') {
        break;
      }
      $idnum .= $tok;
    }
    $generation = '';
    while (true) {
      $tok = fread($stream, 1);
      if (trim($tok) == '') {
        break;
      }
      $generation .= $tok;
    }
    $r = fread($stream, 1);
    if ($r != "R") {
      die("Error reading PDF: error reading indirect object reference.");
    }
    return new IndirectObject((int) $idnum, (int) $generation, $pdf);
  }
  
  function __toString() {
    return "IndirectObject({$this->idnum}, {$this->generation})";
  }
}

class NumberObject extends Object {
  function NumberObject($value) {
    $this->value = $value;
  }
  
  function read_from_stream($stream) {
    $name = '';
    while (true) {
      $tok = fread($stream, 1);
      if (($tok != '+') && ($tok != '-') && ($tok != '.') && (!ctype_digit($tok))) {
        fseek($stream, -1, 1);
        break;
      }
      $name .= $tok;
    }
    if (strpos($name, '.') !== false) {
      return (float) $name;
    } else {
      return (int) $name;
    }
  }
}

class StreamObject extends Object {
  function StreamObject() {
    $this->stream = null;
    $this->data = array();
  }
  
  function init_from_dict($dict) {
    if (in_array('/Filter', array_keys($dict))) {
      $retval = new EncodedStreamObject();
    } else {
      $retval = new DecodedStreamObject();
    }
    $retval->stream = $dict['__streamdata__'];
    unset($dict['__streamdata__']);
    unset($dict['/Length']);
    foreach ($dict as $key=>$val) {
      $retval->data[$key] = $val;
    }
    return $retval;
  }
  
  function flate_encode() {
    if (in_array('/Filter', array_keys($this->data))) {
      $f = $this->data['/Filter'];
      if (is_array($f)) {
        array_unshift($f, new NameObject('/FlateDecode'));
      } else {
        $newf = array();
        $newf[] = new NameObject('/FlateDecode');
        $newf[] = $f;
        $f = $newf;
      }
    } else {
      $f = new NameObject('/FlateDecode');
    }
    
    $retval = new EncodedStreamObject();
    $filter = new NameObject('/Filter');
    $retval[$filter] = $f;
    $retval->stream = FlateDecode::encode($this->stream);
    return $retval;
  }
}

class DecodedStreamObject extends StreamObject {
  function get_data() {
    return $this->stream;
  }
  
  function set_data($data) {
    $this->stream = $data;
  }
}

class EncodedStreamObject extends StreamObject {
  function EncodedStreamObject() {
    $this->decoded_self = null;
  }
  
  function get_data() {
    if ($this->decoded_self) {
      return $this->decoded_self->get_data();
    }
    
    $decoded = new DecodedStreamObject();
    $decoded->stream = decode_stream_data($this);
    foreach ($this->data as $key=>$value) {
      if (!in_array($key, array("/Length", "/Filter", "/DecodeParms"))) {
        $decoded->data[$key] = $value;
      }
    }
    $this->decoded_self = $decoded;
    return $decoded->stream;
  }
  
  function set_data($data) {
    $this->stream = $data;
  }
}

function create_string_object($string) {
  // UTF16_BIG_ENDIAN_BOM
  if (substr($string, 0, 2) == chr(0xFE) . chr(0xFF)) {
    return utf16_decode($string);
  }
  
  return $string;
}

function read_hex_string_from_stream($stream) {
  fread($stream, 1);
  $txt = '';
  $x = '';
  while (true) {
    $tok = read_non_whitespace($stream);
    if ($tok == '>') {
      break;
    }
    $x .= $tok;
    if (strlen($x) == 2) {
      $txt .= chr(base_convert($x, 16, 10));
      $x = '';
    }
  }
  if (strlen($x) == 1) {
    $x .= '0';
  }
  if (strlen($x) == 2) {
    $txt .= chr(base_convert($x, 16, 10));
  }
  
  return create_string_object($txt);
}

function read_string_from_stream($stream) {
  $tok = fread($stream, 1);
  $parens = 1;
  $txt = '';
  while (true) {
    $tok = fread($stream, 1);
    if ($tok == '(') {
      $parens += 1;
    } else if ($tok == ')') {
      $parens -= 1;
      if ($parens == 0) {
        break;
      }
    } else if ($tok == '\\') {
      $tok = fread($stream, 1);
      if ($tok == 'n') {
        $tok = "\n";
      } else if ($tok == 'r') {
        $tok = "\r";
      } else if ($tok == 't') {
        $tok = "\t";
      } else if ($tok == 'b') {
        $tok = "\b";
      } else if ($tok == 'f') {
        $tok = "\f";
      } else if ($tok == '(') {
        $tok = '(';
      } else if ($tok == ')') {
        $tok = ')';
      } else if ($tok == '\\') {
        $tok = "\\";
      } else if (ctype_digit($tok)) {
        for ($i=0; $i<2; $i++) {
          $ntok = fread($stream, 1);
          if (ctype_digit($ntok)) {
            $tok += $ntok;
          } else {
            break;
          }
        }
        $tok = chr(base_convert($tok, 8, 10));
      } else if (($tok == "\n") || ($tok == "\r") || ($tok == "\n\r")) {
        $tok = fread($stream, 1);
        if (!(($tok == "\n") || ($tok == "\r") || ($tok == "\n\r"))) {
          fseek($stream, -1, 1);
        }
        $tok = '';
      } else {
        die("Error reading PDF: unexpected escaped string.");
      }
    }
    $txt .= $tok;
  }
  return create_string_object($txt);
}

class Object {
  function get_object() {
    return $this;
  }
}

?>