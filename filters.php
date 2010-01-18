<?php
include_once 'generic.php';

function decompress($data) {
  return gzuncompress($data);
}

function compress($data) {
  return gzcompress($data);
}

class FlateDecode {
  function decode($data, $decode_parms) {
    $data = decompress($data);

    $predictor = 1;
    if ($decode_parms) {
      $predictor = $decode_parms['/Predictor'];
    }
    if (!$predictor) {
      $predictor = 1;
    }

    if ($predictor != 1) {
      $columns = $decode_parms['/Columns'];

      if (($predictor >= 10) && ($predictor <= 15)) {
        $output = '';
        
        $rowlength = $columns + 1;
        assert((strlen($data) % $rowlength) == 0);
        $prev_rowdata = array_fill(0, $rowlength, 0);
        for ($row=0; $row<strlen($data)/$rowlength; $row++) {
          $rowdata = array();
          $k = 0;
          for ($j=$row*$rowlength; $j<($row+1)*$rowlength; $j++) {
            $rowdata[$k] = ord($data[$j]);
            $k += 1;
          }
          $filter_byte = $rowdata[0];
          if ($filter_byte == 0) {
          } else if ($filter_byte == 1) {
            for ($j=2; $j<$rowlength; $j++) {
              $rowdata[$j] = ($rowdata[$j] + $rowdata[$j-1]) % 256;
            }
          } else if ($filter_byte == 2) {
            for ($j=1; $j<$rowlength; $j++) {
              $rowdata[$j] = ($rowdata[$j] + $prev_rowdata[$j]) % 256;
            }
          } else {
            die("Error reading PDF: unsupported PNG filter $filter_byte.");
          }
          $prev_rowdata = $rowdata;
          for ($j=1; $j<count($rowdata); $j++) {
            $output .= chr($rowdata[$j]);
          }
        }
        
        $data = $output;
      } else {
        die("Error reading PDF: unsupported flatedecode predictor $predictor.");
      }
    }
    
    return $data;
  }
  
  function encode($data) {
    return compress($data);
  }
}

function decode_stream_data($stream) {
  $filters = $stream->data['/Filter'];
  if (!$filters) {
    $filters = array();
  }
  
  if (count($filters) && !is_a($filters[0], 'NameObject')) {
    $filters = array($filters);
  }
  
  $data = $stream->stream;
  foreach ($filters as $filter_type) {
    if ($filter_type == '/FlateDecode') {
      $data = FlateDecode::decode($data, $stream->data['/DecodeParms']);
    }
  }
  return $data;
}
?>