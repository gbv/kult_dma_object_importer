<?php

  // common values
  // one place to define them

  $monumentsXmlHeader  = '<?xml version="1.0" encoding="utf-8" ?>';

  $monumentsNameSpace  =   'xmlns:gml="http://www.opengis.net/gml/3.2" ';
  $monumentsNameSpace .=   'xmlns:wfs="http://www.opengis.net/wfs/2.0" ';
  $monumentsNameSpace .=   'xmlns:xlink="http://www.w3.org/1999/xlink" ';
  $monumentsNameSpace .=   'xmlns="http://www.rjm.de/denkxweb/denkxml" ';
  $monumentsNameSpace .=   'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ';
  $monumentsNameSpace .=   'xsi:schemaLocation="';
  $monumentsNameSpace .=     'http://www.rjm.de/denkxweb/denkxml ';
  $monumentsNameSpace .=     'http://geoportal.geodaten.niedersachsen.de/adabweb/schema/denkgml/0.9/denkgml.xsd ';
  $monumentsNameSpace .=     'http://www.opengis.net/wfs/2.0 ';
  $monumentsNameSpace .=     'http://geoportal.geodaten.niedersachsen.de/adabweb/schema/ogc/wfs/2.0/wfs.xsd ';
  $monumentsNameSpace .=     'http://www.opengis.net/gml/3.2 ';
  $monumentsNameSpace .=     'http://geoportal.geodaten.niedersachsen.de/adabweb/schema/ogc/gml/3.2.1/gml.xsd';
  $monumentsNameSpace .=   '"';

  // make an key value list for those settings
  $common_settings = [
    'xmlHeader' => $monumentsXmlHeader,
    'monumentsNameSpace' => $monumentsNameSpace
  ];

?>
