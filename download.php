<?php
/* μlogger
 *
 * Copyright(C) 2017 Bartek Fabiszewski (www.fabiszewski.net)
 *
 * This is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, see <http://www.gnu.org/licenses/>.
 */
 
require_once("auth.php"); // sets $mysqli, $user
require_once("helpers/position.php");

function addStyle($xml, $name, $url) {
  $xml->startElement("Style");
  $xml->writeAttribute("id", $name."Style");
    $xml->startElement("IconStyle");
    $xml->writeAttribute("id", $name."Icon");
      $xml->startElement("Icon");
        $xml->writeElement("href", $url);
      $xml->endElement();
    $xml->endElement();
  $xml->endElement();
}

function toHMS($s) {
  $d = floor($s / 86400);
  $h = floor(($s % 86400) / 3600);
  $m = floor((($s % 86400) % 3600) / 60);
  $s = (($s % 86400) % 3600) % 60;
  return (($d > 0) ? ($d." d ") : "").(substr("00".$h, -2)).":".(substr("00".$m, -2)).":".(substr("00".$s, -2));
}

$type = isset($_REQUEST["type"]) ? $_REQUEST["type"] : "kml";
$userId = (isset($_REQUEST["userid"]) && is_numeric($_REQUEST["userid"])) ? $_REQUEST["userid"] : NULL;
$trackId = (isset($_REQUEST["trackid"]) && is_numeric($_REQUEST["trackid"])) ? $_REQUEST["trackid"] : NULL;

if ($config::$units == "imperial") {
  $factor_kmh = 0.62; //to mph
  $unit_kmh = "mph";
  $factor_m = 3.28; // to feet
  $unit_m = "ft";
  $factor_km = 0.62; // to miles
  $unit_km = "mi";
} else {
  $factor_kmh = 1;
  $unit_kmh = "km/h";
  $factor_m = 1;
  $unit_m = "m";
  $factor_km = 1;
  $unit_km = "km";
}

if ($trackId && $userId) {
  $position = new uPosition();
  $positionsArr = [];
  $positionsArr = $position->getAll($userId, $trackId);
  if (empty($positionsArr)) {
    $mysqli->close();
    exit();
  }

  switch ($type) {
    case "kml":
    default:
      header("Content-type: application/vnd.google-earth.kml+xml");
      header("Content-Disposition: attachment; filename=\"track" . $positionsArr[0]->trackId . ".kml\"");
      $xml = new XMLWriter();
      $xml->openURI("php://output");
      $xml->setIndent(true);
      $xml->startDocument("1.0", "utf-8");
      $xml->startElement("kml");
      $xml->writeAttributeNs('xsi', 'schemaLocation', NULL, "http://www.opengis.net/kml/2.2 http://schemas.opengis.net/kml/2.2.0/ogckml22.xsd");
      $xml->writeAttributeNs('xmlns', 'xsi', NULL, 'http://www.w3.org/2001/XMLSchema-instance');
      $xml->writeAttribute("xmlns", "http://www.opengis.net/kml/2.2");
      $xml->startElement("Document");
      $xml->writeElement("name", $positionsArr[0]->trackName);
      // line style
      $xml->startElement("Style");
        $xml->writeAttribute("id", "lineStyle");
        $xml->startElement("LineStyle");
          $xml->writeElement("color", "7f0000ff");
          $xml->writeElement("width", "4");
        $xml->endElement();
      $xml->endElement();
      // marker styles
      addStyle($xml, "red", "http://maps.google.com/mapfiles/markerA.png");
      addStyle($xml, "green", "http://maps.google.com/mapfiles/marker_greenB.png");
      addStyle($xml, "gray", "http://maps.gstatic.com/mapfiles/ridefinder-images/mm_20_gray.png");
      $style = "#redStyle"; // for first element
      $i = 0;
      $totalMeters = 0;
      $totalSeconds = 0;
      foreach ($positionsArr as $position) {
        $distance = isset($prevPosition) ? $position->distanceTo($prevPosition) : 0;
        $seconds = isset($prevPosition) ? $position->secondsTo($prevPosition) : 0;
        $prevPosition = $position;
        $totalMeters += $distance;
        $totalSeconds += $seconds;

        if(++$i == count($positionsArr)) { $style = "#greenStyle"; } // last element
        $xml->startElement("Placemark");
        $xml->writeAttribute("id", "point_" . $position->id);
          $description =
          "<div style=\"font-weight: bolder;padding-bottom: 10px;border-bottom: 1px solid gray;\">".
          $lang["user"].": ".strtoupper($position->userLogin)."<br />".$lang["track"].": ".strtoupper($position->trackName).
          "</div>".
          "<div>".
          "<div style=\"padding-top: 10px;\"><b>".$lang["time"].":</b> ".$position->time."<br />".
          (!is_null($position->speed) ? "<b>".$lang["speed"].":</b> ".round($position->speed * 3.6 * $factor_kmh, 2)." ".$unit_kmh."<br />" : "").
          (!is_null($position->altitude) ? "<b>".$lang["altitude"].":</b> ".round($position->altitude * $factor_m)." ".$unit_m."<br />" : "").
          "<b>".$lang["ttime"].":</b> ".toHMS($totalSeconds)."<br />".
          "<b>".$lang["aspeed"].":</b> ".(($totalSeconds != 0) ? round($totalMeters / $totalSeconds * 3.6 * $factor_kmh, 2) : 0)." ".$unit_kmh."<br />".
          "<b>".$lang["tdistance"].":</b> ".round($totalMeters / 1000 * $factor_km, 2)." ".$unit_km."<br />"."</div>".
          "<div style=\"font-size: smaller;padding-top: 10px;\">".$lang["point"]." ".$i." ".$lang["of"]." ".count($positionsArr)."</div>".
          "</div>";
          $xml->startElement("description");
            $xml->writeCData($description);
          $xml->endElement();
          $xml->writeElement("styleUrl", $style);
          $xml->startElement("Point");
            $coordinate[$i] = $position->longitude.",".$position->latitude.(!is_null($position->altitude) ? ",".$position->altitude : "");
            $xml->writeElement("coordinates", $coordinate[$i]);
          $xml->endElement();
        $xml->endElement();
        $style = "#grayStyle"; // other elements
      }
      $coordinates = implode("\n", $coordinate);
      $xml->startElement("Placemark");
      $xml->writeAttribute("id", "lineString");
        $xml->writeElement("styleUrl", "#lineStyle");
        $xml->startElement("LineString");
          $xml->writeElement("coordinates", $coordinates);
        $xml->endElement();
      $xml->endElement();

      $xml->endElement();
      $xml->endElement();
      $xml->endDocument();
      $xml->flush();

      break;

    case "gpx":
      header("Content-type: application/application/gpx+xm");
      header("Content-Disposition: attachment; filename=\"track" . $positionsArr[0]->trackId . ".gpx\"");
      $xml = new XMLWriter();
      $xml->openURI("php://output");
      $xml->setIndent(true);
      $xml->startDocument("1.0", "utf-8");
      $xml->startElement("gpx");
      $xml->writeAttributeNs('xsi', 'schemaLocation', NULL, "http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd");
      $xml->writeAttributeNs('xmlns', 'xsi', NULL, 'http://www.w3.org/2001/XMLSchema-instance');
      $xml->writeAttribute("xmlns", "http://www.topografix.com/GPX/1/1");
      $xml->writeAttribute("creator", "μlogger");
      $xml->writeAttribute("version", "1.1");
      $xml->startElement("metadata");
        $xml->writeElement("name", $positionsArr[0]->trackName);
        $xml->writeElement("time", str_replace(" ", "T", $positionsArr[0]->time));
      $xml->endElement();
      $xml->startElement("trk");
        $xml->writeElement("name", $positionsArr[0]->trackName);
        $xml->startElement("trkseg");
        $i = 0;
        $totalMeters = 0;
        $totalSeconds = 0;
        foreach ($positionsArr as $position) {
          $distance = isset($prevPosition) ? $position->distanceTo($prevPosition) : 0;
          $seconds = isset($prevPosition) ? $position->secondsTo($prevPosition) : 0;
          $prevPosition = $position;
          $totalMeters += $distance;
          $totalSeconds += $seconds;
          $xml->startElement("trkpt");
            $xml->writeAttribute("lat", $position->latitude);
            $xml->writeAttribute("lon", $position->longitude);
            if (!is_null($position->altitude)) { $xml->writeElement("ele", $position->altitude); }
            $xml->writeElement("time", str_replace(" ", "T", $position->time));
            $xml->writeElement("name", ++$i);
            $xml->startElement("desc");
              $description =
              $lang["user"].": ".strtoupper($position->userLogin)." ".$lang["track"].": ".strtoupper($position->trackName).
              " ".$lang["time"].": ".$position->time.
              (!is_null($position->speed) ? " ".$lang["speed"].": ".round($position->speed * 3.6 * $factor_kmh, 2)." ".$unit_kmh : "").
              (!is_null($position->altitude) ? " ".$lang["altitude"].": ".round($position->altitude * $factor_m)." ".$unit_m : "").
              " ".$lang["ttime"].": ".toHMS($totalSeconds)."".
              " ".$lang["aspeed"].": ".(($totalSeconds != 0) ? round($totalMeters / $totalSeconds * 3.6 * $factor_kmh, 2) : 0)." ".$unit_kmh.
              " ".$lang["tdistance"].": ".round($totalMeters / 1000 * $factor_km, 2)." ".$unit_km.
              " ".$lang["point"]." ".$i." ".$lang["of"]." ".count($positionsArr);
              $xml->writeCData($description);
            $xml->endElement();
          $xml->endElement();
        }
        $xml->endElement();
      $xml->endElement();
      $xml->endElement();
      $xml->endDocument();
      $xml->flush();

      break;
  }

}
$mysqli->close();
?>
