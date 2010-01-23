<?php
error_reporting(E_ERROR | E_WARNING | E_PARSE);
ini_set('display_errors', 1);
include_once 'generic.php';
include_once 'utils.php';

class PdfFileReader {
  
  function PdfFileReader($stream) {
    # make sure stream is not empty.
    if (trim(fread($stream, 4096)) == '') {
      die("Error reading PDF: PDF is empty.");
    }
    fseek($stream, 0);
    $this->stream = $stream;
    $this->resolved_objects = array();
    $this->read($stream);
    $this->page_count = $this->get_page_count();
  }
  
  function get_document_info() {
    if (!in_array('/Info', array_keys($this->trailer))) {
      return null;
    }
    $info = $this->trailer['/Info'];
    $info = $info->get_object();
    return new DocumentInformation($info);
  }
  
  function get_page_count($pages=null) {
    if (!$pages) {
      $catalog = $this->trailer['/Root']->get_object();
      $pages = $catalog['/Pages']->get_object();
    }
    $t = $pages['/Type'];
    if ($t == '/Pages') {
      if (in_array('/Kids', array_keys($pages['/Kids'][0]->get_object()))) {
        $sum = 0;
        foreach ($pages['/Kids'] as $page) {
          $page = $page->get_object();
          $sum += $this->get_page_count($page);
        }
        return $sum;
      } else {
        return count($pages['/Kids']);
      }
    }
  }
  
  function get_object($indirect_ref) {
    $retval = $this->resolved_objects[$indirect_ref->generation];
    if ($retval) {
      $retval = $retval[$indirect_ref->idnum];
      if ($retval) {
        return $retval;
      }
    }
    
    if (($indirect_ref->generation === 0)
    and in_array($indirect_ref->idnum, array_keys($this->xref_obj_stm))) {
      $stm_num = $this->xref_obj_stm[$indirect_ref->idnum][0];
      $idx = $this->xref_obj_stm[$indirect_ref->idnum][1];
      
      $o = new IndirectObject($stm_num, 0, $this);
      $obj_stm = $o->get_object();
      $stream_data = tmpfile();
      fwrite($stream_data, $obj_stm->get_data());
      fseek($stream_data, 0); # !!!
      fseek($stream_data, 0);
      for ($i=0; $i<$obj_stm->data['/N']; $i++) {
        $obj_num = NumberObject::read_from_stream($stream_data);
        read_non_whitespace($stream_data);
        fseek($stream_data, -1, 1);
        $offset = NumberObject::read_from_stream($stream_data);
        read_non_whitespace($stream_data);
        fseek($stream_data, -1, 1);
        $t = ftell($stream_data);
        fseek($stream_data, $obj_stm->data['/First'] + $offset, 0);
        $obj = read_object($stream_data, $this);
        $this->resolved_objects[0][$obj_num] = $obj;
        fseek($stream_data, $t, 0);
      }
      
      fclose($stream_data);
      return $this->resolved_objects[0][$indirect_ref->idnum];
    }

    $start = $this->xref[$indirect_ref->generation][$indirect_ref->idnum];
    fseek($this->stream, $start, 0);
    $header = $this->read_object_header($this->stream);
    $idnum = $header[0];
    $generation = $header[1];
    $retval = read_object($this->stream, $this);
    
    $this->cache_indirect_object($generation, $idnum, $retval);
    return $retval;
  }
    
  function read_object_header($stream) {
    read_non_whitespace($stream);
    fseek($stream, -1, 1);
    $idnum = read_until_whitespace($stream);
    $generation = read_until_whitespace($stream);
    
    $obj = fread($stream, 3);
    read_non_whitespace($stream);
    fseek($stream, -1, 1);
    return array((int) $idnum, (int) $generation);
  }
  
  function cache_indirect_object($generation, $idnum, $obj) {
    if (!in_array($generation, array_keys($this->resolved_objects))) {
      $this->resolved_objects[$generation] = array();
    }
    $this->resolved_objects[$generation][$idnum] = $obj;
  }
  
  function read($stream) {
    fseek($stream, -1, 2);
    $line = '';
    while (!$line) {
      $line = $this->read_next_end_line($stream);
    }
    if (substr($line, 0, 5) != '%%EOF') {
      die("Error reading PDF: EOF marker not found.");
    }
    
    $line = $this->read_next_end_line($stream);
    $startxref = (int) $line;
    $line = $this->read_next_end_line($stream);
    if (substr($line, 0, 9) != 'startxref') {
      die("Error reading PDF: startxref not found.");
    }
    
    $this->xref = array();
    $this->xref_obj_stm = array();
    $this->trailer = array();
    while (1) {
      fseek($stream, $startxref, 0);
      $x = fread($stream, 1);
      if ($x == "x") {
        $ref = fread($stream, 4);
        if (substr($ref, 0, 3) != 'ref') {
          die("Error reading PDF: xref table read error.");
        }
        read_non_whitespace($stream);
        fseek($stream, -1, 1);
        while (1) {
          $num = read_object($stream, $this);
          read_non_whitespace($stream);
          fseek($stream, -1, 1);
          $size = read_object($stream, $this);
          read_non_whitespace($stream);
          fseek($stream, -1, 1);
          $cnt = 0;
          while ($cnt < $size) {
            $line = fread($stream, 20);
            
            if (in_array(substr($line, -1, 1), array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 't'))) {
              fseek($stream, -1, 1);
            }
            $tmp = explode(' ', substr($line, 0, 16));
            $offset = (int) $tmp[0];
            $generation = (int) $tmp[1];
            if (!in_array($generation, array_keys($this->xref))) {
              $this->xref[$generation] = array();
            }
            if (!$this->xref[$generation][$num]) {
              $this->xref[$generation][$num] = $offset;
            }
            $cnt += 1;
            $num += 1;
          }
          read_non_whitespace($stream);
          fseek($stream, -1, 1);
          $trailertag = fread($stream, 7);
          if ($trailertag != 'trailer') {
            fseek($stream, -7, 1);
          } else {
            break;
          }
        }
        read_non_whitespace($stream);
        fseek($stream, -1, 1);
        $new_trailer = read_object($stream, $this);
        foreach ($new_trailer as $key=>$value) {
          if (!in_array($key, array_keys($this->trailer))) {
            $this->trailer[$key] = $value;
          }
        }
        if (in_array('/Prev', array_keys($new_trailer))) {
          $startxref = $new_trailer['/Prev'];
        } else {
          break;
        }
      } else if (ctype_digit($x)) {
        fseek($stream, -1, 1);
        $hdr = $this->read_object_header($stream);
        $idnum = $hdr[0];
        $generation = $hdr[1];
        $xrefstream = read_object($stream, $this);
        assert($xrefstream->data['/Type'] == '/XRef');
        $this->cache_indirect_object($generation, $idnum, $xrefstream);
        $stream_data = $xrefstream->get_data();
        $cursor = 0;
        $idx_pairs = $xrefstream->data['/Index'];
        if (!$idx_pairs) {
          $idx_pairs = array(0, $xrefstream->data['/Size']);
        }
        $entry_sizes = $xrefstream->data['/W'];
        foreach ($this->_pairs($idx_pairs) as $pair) {
          $num = $pair[0];
          $size = $pair[1];
          $cnt = 0;
          while ($cnt < $size) {
            for ($i=0; $i<count($entry_sizes); $i++) {
              $d = substr($stream_data, $cursor, $entry_sizes[$i]);
              $cursor += $entry_sizes[$i];
              $di = convert_to_int($d, $entry_sizes[$i]);
              if ($i == 0) {
                $xref_type = $di;
              } else if ($i == 1) {
                if ($xref_type == 0) {
                  $next_free_object = $di;
                } else if ($xref_type == 1) {
                  $byte_offset = $di;
                } else if ($xref_type == 2) {
                  $objstr_num = $di;
                }
              } else if ($i == 2) {
                if ($xref_type == 0) {
                  $next_generation = $di;
                } else if ($xref_type == 1) {
                  $generation = $di;
                } else if ($xref_type == 2) {
                  $objstr_idx = $di;
                }
              }
            }
            if ($xref_type == 0) {
            } else if ($xref_type == 1) {
              if (!in_array($generation, array_keys($this->xref))) {
                $this->xref[$generation] = array();
              }
              if (!in_array($num, array_keys($this->xref[$generation]))) {
                $this->xref[$generation][$num] = $byte_offset;
              }
            } else if ($xref_type == 2) {
              if (!in_array($num, array_keys($this->xref_obj_stm))) {
                $this->xref_obj_stm[$num] = array($objstr_num, $objstr_idx);
              }
            }
            $cnt += 1;
            $num += 1;
          }
        }
        
        $trailer_keys = array('/Root', '/Info', '/ID');
        foreach ($trailer_keys as $key) {
          if ((in_array($key, array_keys($xrefstream->data)))
          and (!in_array($key, array_keys($this->trailer)))) {
            $this->trailer[$key] = $xrefstream->data[$key];
          }
        }
        
        if (in_array('/Prev', array_keys($xrefstream->data))) {
          $startxref = $xrefstream->data['/Prev'];
        } else {
          break;
        }
      } else {
        fseek($stream, -11, 1);
        $tmp = fread($stream, 20);
        $xref_loc = strpos($tmp, 'xref');
        if ($xref_loc !== -1) {
          $startxref -= (10 - $xref_loc);
          continue;
        } else {
          assert(false);
          break;
        }
      }
    }
    
    // var_dump($this->get_xmp_metadata());
  }
  
  function get_xmp_metadata() {
    $root = $this->trailer['/Root']->get_object();
    $metadata = $root['/Metadata'];
    if (!$metadata) {
      return null;
    }
    
    $metadata = $metadata->get_object();
    // var_dump($metadata);
    return $metadata;
  }
  
  function _pairs($array) {
    $i = 0;
    $retval = array();
    while (true) {
      $retval[] = array($array[$i], $array[$i+1]);
      $i += 2;
      if ($i+1 >= count($array)) {
        break;
      }
    }
    return $retval;
  }
  
  function read_next_end_line($stream) {
    $line = '';
    while (true) {
      $x = fread($stream, 1);
      fseek($stream, -2, SEEK_CUR);
      if (($x == "\n") || ($x == "\r")) {
        while (($x == "\n") || ($x == "\r")) {
          $x = fread($stream, 1);
          fseek($stream, -2, SEEK_CUR);
        }
        fseek($stream, 1, SEEK_CUR);
        break;
      } else {
        $line = $x . $line;
      }
    }
    return $line;
  }
  
}

$doc_info_keys = array(
    'Title' => 'title',
    'Author' => 'author',
    'Keywords' => 'keywords',
    'Pages' => 'pages',
    'Subject' => 'subject',
    'Creator' => 'creator',
    'Producer' => 'producer',
    'CreationDate' => 'creation_date',
    'ModDate' => 'mod_date');

class DocumentInformation {
  
  function __construct($info) {
    global $doc_info_keys;

    $data = array();
    foreach ($info as $key=>$value) {
      $key = str_replace('/', '', $key);
      $data[$doc_info_keys[$key]] = $value;
    }
    $this->data = $data;
  }
}

function convert_to_int($d, $size) {
  $out = bin2hex($d);
  if ($out) {
    $out = base_convert($out, 16, 10);
  }
  return (int) $out;
}
?>