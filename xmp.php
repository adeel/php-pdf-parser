<?php
##
# not finished
##

include_once 'generic.php';

$RDF_NAMESPACE = "http://www.w3.org/1999/02/22-rdf-syntax-ns#";
$DC_NAMESPACE = "http://purl.org/dc/elements/1.1/";
$XMP_NAMESPACE = "http://ns.adobe.com/xap/1.0/";
$PDF_NAMESPACE = "http://ns.adobe.com/pdf/1.3/";
$XMPMM_NAMESPACE = "http://ns.adobe.com/xap/1.0/mm/";
$PDFX_NAMESPACE = "http://ns.adobe.com/pdfx/1.3/";

$iso8601 = "/(?P<year>[0-9]{4})(-(?P<month>[0-9]{2})(-(?P<day>[0-9]+)(T(?P<hour>[0-9]{2}):(?P<minute>[0-9]{2})(:(?P<second>[0-9]{2}(.[0-9]+)?))?(?P<tzd>Z|[-+][0-9]{2}:[0-9]{2}))?)?)?";

class XmpInformation extends Object {
  
  function XmpInformation($stream) {
    $this->stream = $stream;

    $doc_root = new DOMDocument();
    $doc_root->loadXML($this->stream->get_data());
    $rdf_els = $doc_root->getElementsByTagNameNS($RDF_NAMESPACE, 'RDF');
    $this->rdf_root = $rdf_els[0];
    
    $this->cache = array();
  }

  function get_element($about_uri, $ns, $name) {
    $retval = array();
    
    $descs = $this->rdf_root->getElementsByTagNameNS($RDF_NAMESPACE, 'Description');
    foreach ($descs as $desc) {
      if ($desc->getAttributeNS($RDF_NAMESPACE, 'about') == $about_uri) {
        $attr = $desc->getAttributeNodeNS($ns, $name);
        if ($attr) {
          $retval[] = $attr;
        }
        foreach ($desc->getElementsByTagNameNS($ns, $name) as $el) {
          $retval[] = $el;
        }
      }
    }
    
    return $retval;
  }
  
  function get_nodes_in_ns($about_uri, $ns) {
    $retval = array();
    
    $descs = $this->rdf_root->getElementsByTagNameNS($RDF_NAMESPACE, 'Description');
    foreach ($descs as $desc) {
      if ($desc->getAttributeNS($RDF_NAMESPACE, 'about') == $about_uri) {
        for ($i=0; $i<$desc->attributes->length; $i++) {
          $attr = $desc->attributes->item($i);
          if ($attr->namespaceURI == $ns) {
            $retval[] = $attr;
          }
        }
        
        foreach ($desc->childNodes as $child) {
          if ($child->namespaceURI == $ns) {
            $retval[] = $child;
          }
        }
      }
    }
    
    return $retval;
  }
  
  function _get_text($element) {
    $text = '';
    foreach ($element->childNodes as $child) {
      if ($child->nodeType == XML_TEXT_NODE) {
        $text .= $child->data;
      }
    }
    
    return $text;
  }
  
  function _converter_string($value) {
    return $value;
  }
  
  function _converter_date($value) {
    // $m = array();
    // preg_match($iso8601, $value, &$m);
    // $year = (int) $m['year'];
    // if (!$m['month']) {
    //   $m['month'] = 1;
    // }
    // $month = (int) $m['month'];
    // if (!$m['day']) {
    //   $m['day'] = 1;
    // }
    // $day = (int) $m['day'];
    // if (!$m['hour']) {
    //   $m['hour'] = 0;
    // }
    // $hour = (int) $m['hour'];
    // if (!$m['minute']) {
    //   $m['minute'] = 0;
    // }
    // $minute = (int) $m['minute'];
    // if (!$m['second']) {
    //   $m['second'] = 0;
    // }
    // $second = (float) $m['second'];
    // $seconds = floor($second);
    // $milliseconds = ($second - $seconds) * 1000000;
    // if (!$m['tzd']) {
    //   $m['tzd'] = 'Z';
    // }
    // $tzd = $m['tzd'];
    
    sscanf($tstamp, "%u-%u-%uT%u:%u:%uZ", $year, $month, $day, $hour, $min, $sec);
    return mktime($hour, $min, $sec, $month, $day, $year);
  }
  
}