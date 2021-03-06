<?php
namespace Mapbender\PrintBundle\Component;

use Mapbender\PrintBundle\Component\Export\Affine2DTransform;
use Mapbender\PrintBundle\Component\Export\Box;

/**
 * Mapbender3 Print Service.
 *
 * @author Stefan Winkelmann
 */
class PrintService extends ImageExportService
{
    /** @var PDF_Extensions|\FPDF */
    protected $pdf;
    protected $conf;

    /** @var array */
    protected $data;

    /**
     * @var array Default geometry style
     */
    protected $defaultStyle = array(
        "strokeWidth" => 1
    );

    public function doPrint($jobData)
    {
        $templateData = $this->getTemplateData($jobData);
        $this->setup($templateData, $jobData);

        $mapImageName = $this->createMapImage($templateData, $jobData);

        $pdf = $this->buildPdf($mapImageName, $templateData, $jobData);

        return $this->dumpPdf($pdf);
    }

    /**
     * @param array $jobData
     * @return array
     */
    protected function getTemplateData($jobData)
    {
        $odgParser = new OdgParser($this->container);
        return $odgParser->getConf($jobData['template']);
    }

    /**
     * @param array $templateData
     * @param array $jobData
     */
    protected function setup($templateData, $jobData)
    {
        // @todo: eliminate instance variable $this->data
        $this->data = $jobData;
        // @todo: eliminate instance variable $this->conf
        $this->conf = $templateData;
    }

    protected function getTargetBox($templateData, $jobData)
    {
        $targetWidth = round($templateData['map']['width'] / 25.4 * $jobData['quality']);
        $targetHeight = round($templateData['map']['height'] / 25.4 * $jobData['quality']);
        // NOTE: gd pixel coords are top down
        return new Box(0, $targetHeight, $targetWidth, 0);
    }

    /**
     * @param array $templateData
     * @param array $jobData
     * @return string path to stored image
     */
    private function createMapImage($templateData, $jobData)
    {
        $targetBox = $this->getTargetBox($templateData, $jobData);
        $exportJob = array_replace($jobData, array(
            'width' => $targetBox->getWidth(),
            'height' => abs($targetBox->getHeight()),
        ));
        $mapImage = $this->buildExportImage($exportJob);

        // dump to file system immediately to recoup some memory before building PDF
        $mapImageName = $this->makeTempFile('mb_print_final');
        imagepng($mapImage, $mapImageName);
        imagedestroy($mapImage);
        return $mapImageName;
    }

    /**
     * @param $templateData
     * @param $templateName
     * @return \FPDF|\FPDF_TPL|PDF_Extensions
     * @throws \Exception
     */
    protected function makeBlankPdf($templateData, $templateName)
    {
        require_once('PDF_Extensions.php');

        /** @var PDF_Extensions|\FPDF|\FPDF_TPL $pdf */
        $pdf =  new PDF_Extensions();
        $pdfFile = $this->resourceDir . '/templates/' . $templateName . '.pdf';
        $pdf->setSourceFile($pdfFile);
        $pdf->SetAutoPageBreak(false);
        if ($templateData['orientation'] == 'portrait') {
            $format = array($templateData['pageSize']['width'], $templateData['pageSize']['height']);
            $orientation = 'P';
        } else {
            $format = array($templateData['pageSize']['height'], $templateData['pageSize']['width']);
            $orientation = 'L';
        }
        $pdf->addPage($orientation, $format);
        return $pdf;
    }

    /**
     * @param string $mapImageName
     * @param array $templateData
     * @param array $jobData
     * @return \FPDF|\FPDF_TPL|PDF_Extensions
     * @throws \Exception
     */
    protected function buildPdf($mapImageName, $templateData, $jobData)
    {
        // @todo: eliminate instance variable $this->pdf
        $this->pdf = $pdf = $this->makeBlankPdf($templateData, $jobData['template']);
        $tplidx = $pdf->importPage(1);
        $hasTransparentBg = $this->checkPdfBackground($pdf);
        if (!$hasTransparentBg){
            $pdf->useTemplate($tplidx);
        }
        // add final map image
        $mapUlX = $this->conf['map']['x'];
        $mapUlY = $this->conf['map']['y'];
        $mapWidth = $this->conf['map']['width'];
        $mapHeight = $this->conf['map']['height'];
        $this->addImageToPdf($pdf, $mapImageName, $mapUlX, $mapUlY, $mapWidth, $mapHeight);

        // add map border (default is black)
        $pdf->Rect($mapUlX, $mapUlY, $mapWidth, $mapHeight);
        unlink($mapImageName);

        if ($hasTransparentBg) {
            $pdf->useTemplate($tplidx);
        }

        // add northarrow
        if (isset($templateData['northarrow'])) {
            $this->addNorthArrow($pdf, $templateData, $jobData);
        }

        // fill text fields
        if (isset($this->conf['fields']) ) {
            foreach ($this->conf['fields'] as $k => $v) {
                list($r, $g, $b) = CSSColorParser::parse($this->conf['fields'][$k]['color']);
                $pdf->SetTextColor($r,$g,$b);
                $pdf->SetFont('Arial', '', intval($this->conf['fields'][$k]['fontsize']));
                $pdf->SetXY($this->conf['fields'][$k]['x'] - 1,
                    $this->conf['fields'][$k]['y']);

                // continue if extent field is set
                if(preg_match("/^extent/", $k)){
                    continue;
                }

                switch ($k) {
                    case 'date' :
                        $date = new \DateTime();
                        $pdf->Cell($this->conf['fields']['date']['width'],
                            $this->conf['fields']['date']['height'],
                            $date->format('d.m.Y'));
                        break;
                    case 'scale' :
                        $pdf->Cell($this->conf['fields']['scale']['width'],
                            $this->conf['fields']['scale']['height'],
                            '1 : ' . $this->data['scale_select']);
                        break;
                    default:
                        if (isset($this->data['extra'][$k])) {
                            $pdf->MultiCell($this->conf['fields'][$k]['width'],
                                $this->conf['fields'][$k]['height'],
                                utf8_decode($this->data['extra'][$k]));
                        }

                        // fill digitizer feature fields
                        if (isset($this->data['digitizer_feature']) && preg_match("/^feature./", $k)) {
                            $dfData = $this->data['digitizer_feature'];
                            $feature = $this->getFeature($dfData['schemaName'], $dfData['id']);
                            $attribute = substr(strrchr($k, "."), 1);

                            if ($feature && $attribute) {
                                $pdf->MultiCell($this->conf['fields'][$k]['width'],
                                    $this->conf['fields'][$k]['height'],
                                    utf8_decode($feature->getAttribute($attribute)));
                            }
                        }
                        break;
                }
            }
        }

        // reset text color to default black
        $pdf->SetTextColor(0,0,0);

        // add overview map
        if (isset($this->data['overview']) && isset($this->conf['overview']) ) {
            $this->addOverviewMap($pdf, $templateData, $jobData);
        }

        // add scalebar
        if (isset($this->conf['scalebar']) ) {
            $this->addScaleBar($pdf, $templateData, $jobData);
        }

        // add coordinates
        if (isset($this->conf['fields']['extent_ur_x']) && isset($this->conf['fields']['extent_ur_y'])
                && isset($this->conf['fields']['extent_ll_x']) && isset($this->conf['fields']['extent_ll_y']))
        {
            $this->addCoordinates();
        }

        // add dynamic logo
        if (!empty($this->conf['dynamic_image']) && !empty($this->data['dynamic_image'])) {
            $this->addDynamicImage();
        }

        // add dynamic text
        if (!empty($this->conf['fields']['dynamic_text']) && !empty($this->data['dynamic_text'])) {
            $this->addDynamicText();
        }

        // add legend
        if (isset($this->data['legends']) && !empty($this->data['legends'])){
            $this->addLegend();
        }
        return $pdf;
    }

    /**
     * @param PDF_Extensions|\FPDF $pdf
     * @return string
     */
    protected function dumpPdf($pdf)
    {
        return $pdf->Output(null, 'S');
    }

    protected function addNorthArrow($pdf, $templateData, $jobData)
    {
        $northarrow = $this->resourceDir . '/images/northarrow.png';
        $rotation = intval($jobData['rotation']);

        $region = $templateData['northarrow'];
        if ($rotation != 0) {
            $image = imagecreatefrompng($northarrow);
            $transColor = imagecolorallocatealpha($image, 255, 255, 255, 0);
            $rotatedImage = imagerotate($image, $rotation, $transColor);
            $srcSize = array(imagesx($image), imagesy($image));
            $destSize = array(imagesx($rotatedImage), imagesy($rotatedImage));
            $x = abs(($srcSize[0] - $destSize[0]) / 2);
            $y = abs(($srcSize[1] - $destSize[1]) / 2);
            $northarrow = $this->cropImage($rotatedImage, $x, $y, $srcSize[0], $srcSize[1]);
        }
        $this->addImageToPdf($pdf, $northarrow, $region['x'], $region['y'], $region['width'], $region['height']);
    }

    /**
     * @param PDF_Extensions|\FPDF $pdf
     * @param array $templateData
     * @param array $jobData
     */
    protected function addOverviewMap($pdf, $templateData, $jobData)
    {
        $ovData = $jobData['overview'];
        $region = $templateData['overview'];
        $quality = $jobData['quality'];
        // calculate needed image size
        $ovImageWidth = round($region['width'] / 25.4 * $quality);
        $ovImageHeight = round($region['height'] / 25.4 * $quality);
        // gd pixel coords are top down!
        $ovPixelBox = new Box(0, $ovImageHeight, $ovImageWidth, 0);
        // fix job data to be compatible with image export:
        // 1: 'changeAxis' is only on top level, not per layer
        // 2: Layer type is missing, we only have a URL
        // 3: opacity is missing
        // 4: pixel width is inferred from height + template region aspect ratio
        $layerDefs = array();
        foreach ($ovData['layers'] as $layerUrl) {
            $layerDefs[] = array(
                'url' => $layerUrl,
                'type' => 'wms',        // HACK (same behavior as old code)
                'changeAxis' => $ovData['changeAxis'],
                'opacity' => 1,
            );
        }
        $cnt = $ovData['center'];
        $ovWidth = $ovData['height'] * $region['width'] / $region['height'];
        $ovExtent = Box::fromCenterAndSize($cnt['x'], $cnt['y'], $ovWidth, $ovData['height']);
        $image = $this->buildExportImage(array(
            'layers' => $layerDefs,
            'width' => $ovImageWidth,
            'height' => $ovImageHeight,
            'extent' => array(
                'width' => $ovExtent->getWidth(),
                'height' => $ovExtent->getHeight(),
            ),
            'center' => $ovExtent->getCenterXy(),
        ));

        $ovTransform = Affine2DTransform::boxToBox($ovExtent, $ovPixelBox);
        $red = imagecolorallocate($image,255,0,0);
        // GD imagepolygon expects a flat, numerically indexed, 1d list of concatenated coordinates,
        // and we have 2D sub-arrays with 'x' and 'y' keys. Convert.
        $flatPoints = call_user_func_array('array_merge', array_map('array_values', array(
            $ovTransform->transformXy($jobData['extent_feature'][0]),
            $ovTransform->transformXy($jobData['extent_feature'][3]),
            $ovTransform->transformXy($jobData['extent_feature'][2]),
            $ovTransform->transformXy($jobData['extent_feature'][1]),
        )));
        imagepolygon($image, $flatPoints, 4, $red);

        $this->addImageToPdf($pdf, $image, $region['x'], $region['y'], $region['width'], $region['height']);
        imagecolordeallocate($image, $red);
        imagedestroy($image);
        // draw border rectangle
        $pdf->Rect($region['x'], $region['y'], $region['width'], $region['height']);
    }

    /**
     * @param PDF_Extensions|\FPDF $pdf
     * @param array $templateData
     * @param array $jobData
     */
    protected function addScaleBar($pdf, $templateData, $jobData)
    {
        $region = $templateData['scalebar'];
        $totalWidth = $region['width'];
        $pdf->SetFont('arial', '', 10 );

        $length = 0.01 * $jobData['scale_select'] * 5;
        $suffix = 'm';

        $pdf->Text($region['x'] , $region['y'] - 1 , '0' );
        $pdf->Text($region['x'] + $totalWidth - 7, $region['y'] - 1 , $length . '' . $suffix);

        $nSections = 5;
        $sectionWidth = $totalWidth / $nSections;

        $pdf->SetLineWidth(0.1);
        $pdf->SetDrawColor(0, 0, 0);
        for ($i = 0; $i < $nSections; ++$i) {
            if ($i & 1) {
                $pdf->SetFillColor(255, 255, 255);
            } else {
                $pdf->SetFillColor(0, 0, 0);
            }
            $pdf->Rect($region['x'] + round($i * $sectionWidth), $region['y'], $sectionWidth, 2, 'FD');
        }
    }

    private function addCoordinates()
    {
        $pdf = $this->pdf;

        $corrFactor = 2;
        $precision = 2;
        // correction factor and round precision if WGS84
        if($this->data['extent']['width'] < 1){
             $corrFactor = 3;
             $precision = 6;
        }

        // upper right Y
        $pdf->SetFont('Arial', '', intval($this->conf['fields']['extent_ur_y']['fontsize']));
        $pdf->Text($this->conf['fields']['extent_ur_y']['x'] + $corrFactor,
                    $this->conf['fields']['extent_ur_y']['y'] + 3,
                    round($this->data['extent_feature'][2]['y'], $precision));

        // upper right X
        $pdf->SetFont('Arial', '', intval($this->conf['fields']['extent_ur_x']['fontsize']));
        $pdf->TextWithDirection($this->conf['fields']['extent_ur_x']['x'] + 1,
                    $this->conf['fields']['extent_ur_x']['y'],
                    round($this->data['extent_feature'][2]['x'], $precision),'D');

        // lower left Y
        $pdf->SetFont('Arial', '', intval($this->conf['fields']['extent_ll_y']['fontsize']));
        $pdf->Text($this->conf['fields']['extent_ll_y']['x'],
                    $this->conf['fields']['extent_ll_y']['y'] + 3,
                    round($this->data['extent_feature'][0]['y'], $precision));

        // lower left X
        $pdf->SetFont('Arial', '', intval($this->conf['fields']['extent_ll_x']['fontsize']));
        $pdf->TextWithDirection($this->conf['fields']['extent_ll_x']['x'] + 3,
                    $this->conf['fields']['extent_ll_x']['y'] + 30,
                    round($this->data['extent_feature'][0]['x'], $precision),'U');
    }

    private function addDynamicImage()
    {
        $dynImage = $this->resourceDir . '/' . $this->data['dynamic_image']['path'];
        if(file_exists ($dynImage)){
            $this->pdf->Image($dynImage,
                            $this->conf['dynamic_image']['x'],
                            $this->conf['dynamic_image']['y'],
                            0,
                            $this->conf['dynamic_image']['height'],
                            'png');
            return;
        }

    }

    private function addDynamicText()
    {
        $this->pdf->SetFont('Arial', '', $this->conf['fields']['dynamic_text']['fontsize']);
        $this->pdf->MultiCell($this->conf['fields']['dynamic_text']['width'],
                $this->conf['fields']['dynamic_text']['height'],
                utf8_decode($this->data['dynamic_text']['text']));
    }

    private function getFeature($schemaName, $featureId)
    {
        $featureTypeService = $this->container->get('features');
        $featureType = $featureTypeService->get($schemaName);
        $feature = $featureType->get($featureId);
        return $feature;
    }

    protected function getResizeFactor()
    {
        if ($this->data['quality'] != 72) {
            return $this->data['quality'] / 72;
        } else {
            return 1;
        }
    }

    private function drawPolygon($geometry, $image)
    {
        $resizeFactor = $this->getResizeFactor();
        $style = $this->getStyle($geometry);
        foreach($geometry['coordinates'] as $ring) {
            if(count($ring) < 3) {
                continue;
            }

            $points = array();
            foreach($ring as $c) {
                $p = $this->featureTransform->transformPair($c);
                $points[] = floatval($p[0]);
                $points[] = floatval($p[1]);
            }
            imagesetthickness($image, 0);

            if($style['fillOpacity'] > 0){
                $color = $this->getColor(
                    $style['fillColor'],
                    $style['fillOpacity'],
                    $image);
                imagefilledpolygon($image, $points, count($ring), $color);
            }
            // Border
            if ($style['strokeWidth'] > 0) {
                $color = $this->getColor(
                    $style['strokeColor'],
                    $style['strokeOpacity'],
                    $image);
                imagesetthickness($image, $style['strokeWidth'] * $resizeFactor);
                imagepolygon($image, $points, count($ring), $color);
            }
        }
    }

    private function drawMultiPolygon($geometry, $image)
    {
        $resizeFactor = $this->getResizeFactor();
        $style = $this->getStyle($geometry);
        foreach($geometry['coordinates'] as $element) {
            foreach($element as $ring) {
                if(count($ring) < 3) {
                    continue;
                }

                $points = array();
                foreach($ring as $c) {
                    $p = $this->featureTransform->transformPair($c);
                    $points[] = floatval($p[0]);
                    $points[] = floatval($p[1]);
                }
                imagesetthickness($image, 0);
                // Filled area
                if($style['fillOpacity'] > 0){
                    $color = $this->getColor(
                        $style['fillColor'],
                        $style['fillOpacity'],
                        $image);
                    imagefilledpolygon($image, $points, count($ring), $color);
                }
                // Border
                if ($style['strokeWidth'] > 0) {
                    $color = $this->getColor(
                        $style['strokeColor'],
                        $style['strokeOpacity'],
                        $image);
                    imagesetthickness($image, $style['strokeWidth'] * $resizeFactor);
                    imagepolygon($image, $points, count($ring), $color);
                }
            }
        }
    }

    private function drawLineString($geometry, $image)
    {
        $resizeFactor = $this->getResizeFactor();
        $style = $this->getStyle($geometry);
        $color = $this->getColor(
            $style['strokeColor'],
            $style['strokeOpacity'],
            $image);
        if ($style['strokeWidth'] == 0) {
            return;
        }
        imagesetthickness($image, $style['strokeWidth'] * $resizeFactor);

        for($i = 1; $i < count($geometry['coordinates']); $i++) {
            $from = $this->featureTransform->transformPair($geometry['coordinates'][$i - 1]);
            $to = $this->featureTransform->transformPair($geometry['coordinates'][$i]);

            imageline($image, $from[0], $from[1], $to[0], $to[1], $color);
        }
    }

    private function drawMultiLineString($geometry, $image)
    {
        $resizeFactor = $this->getResizeFactor();
        $style = $this->getStyle($geometry);
        $color = $this->getColor(
            $style['strokeColor'],
            $style['strokeOpacity'],
            $image);
        if ($style['strokeWidth'] == 0) {
            return;
        }
        imagesetthickness($image, $style['strokeWidth'] * $resizeFactor);

        foreach($geometry['coordinates'] as $coords) {
            for($i = 1; $i < count($coords); $i++) {
                $from = $this->featureTransform->transformPair($coords[$i - 1]);
                $to = $this->featureTransform->transformPair($coords[$i]);
                imageline($image, $from[0], $from[1], $to[0], $to[1], $color);
            }
        }
    }

    private function drawPoint($geometry, $image)
    {
        $style = $this->getStyle($geometry);
        $resizeFactor = $this->getResizeFactor();

        $p = $this->featureTransform->transformPair($geometry['coordinates']);

        if(isset($style['label'])){
            // draw label with halo
            $color = $this->getColor($style['fontColor'], 1, $image);
            $bgcolor = $this->getColor($style['labelOutlineColor'], 1, $image);
            $fontPath = $this->resourceDir.'/fonts/';
            $font = $fontPath . 'OpenSans-Bold.ttf';

            $fontSize = 10 * $resizeFactor;
            imagettftext($image, $fontSize, 0, $p[0], $p[1]+$resizeFactor, $bgcolor, $font, $geometry['style']['label']);
            imagettftext($image, $fontSize, 0, $p[0], $p[1]-$resizeFactor, $bgcolor, $font, $geometry['style']['label']);
            imagettftext($image, $fontSize, 0, $p[0]-$resizeFactor, $p[1], $bgcolor, $font, $geometry['style']['label']);
            imagettftext($image, $fontSize, 0, $p[0]+$resizeFactor, $p[1], $bgcolor, $font, $geometry['style']['label']);
            imagettftext($image, $fontSize, 0, $p[0], $p[1], $color, $font, $style['label']);
        }

        $radius = $resizeFactor * $style['pointRadius'];
        // Filled circle
        if($style['fillOpacity'] > 0){
            $color = $this->getColor(
                $style['fillColor'],
                $style['fillOpacity'],
                $image);
            imagesetthickness($image, 0);
            imagefilledellipse($image, $p[0], $p[1], 2 * $radius, 2 * $radius, $color);
        }
        // Circle border
        if ($style['strokeWidth'] > 0 && $style['strokeOpacity'] > 0) {
            $color = $this->getColor(
                $style['strokeColor'],
                $style['strokeOpacity'],
                $image);
            imagesetthickness($image, $style['strokeWidth'] * $resizeFactor);
            imageellipse($image, $p[0], $p[1], 2 * $radius, 2 * $radius, $color);
        }
    }

    /**
     * Seemingly redundant override is necessary because the drawSomething methods are all private...
     *
     * @param resource $image
     * @param mixed[] $vectorLayers
     */
    protected function drawFeatures($image, $vectorLayers)
    {
        imagesavealpha($image, true);
        imagealphablending($image, true);

        foreach ($vectorLayers as $layer) {
            foreach ($layer['geometries'] as $geometry) {
                $renderMethodName = 'draw' . $geometry['type'];
                if (!method_exists($this, $renderMethodName)) {
                    continue;
                }
                $this->$renderMethodName($geometry, $image);
            }
        }
    }

    private function addLegend()
    {
        if(isset($this->conf['legend']) && $this->conf['legend']){
          // print legend on first
          $height = $this->conf['legend']['height'];
          $width = $this->conf['legend']['width'];
          $xStartPosition = $this->conf['legend']['x'];
          $yStartPosition = $this->conf['legend']['y'];
          $x = $xStartPosition + 5;
          $y = $yStartPosition + 5;
          $legendConf = true;
        }else{
          // print legend on second page
          $this->pdf->addPage('P');
          $this->pdf->SetFont('Arial', 'B', 11);
          $x = 5;
          $y = 10;
          $height = $this->pdf->getHeight();
          $width = $this->pdf->getWidth();
          $legendConf = false;
          $this->addLegendPageImage($this->pdf, $this->conf, $this->data);
          $xStartPosition = 0;
          $yStartPosition = 0;
        }

        foreach ($this->data['legends'] as $idx => $legendArray) {
            $c         = 1;
            $arraySize = count($legendArray);
            foreach ($legendArray as $title => $legendUrl) {

                if (preg_match('/request=GetLegendGraphic/i', urldecode($legendUrl)) === 0) {
                    continue;
                }

                $image = $this->getLegendImage($legendUrl);
                if (!$image) {
                    continue;
                }
                $size  = getimagesize($image);
                $tempY = round($size[1] * 25.4 / 96) + 10;

                if ($c > 1) {
                    // print legend on second page
                    if($y + $tempY + 10 > ($this->pdf->getHeight()) && $legendConf == false){
                        $x += 105;
                        $y = 10;
                        $this->addLegendPageImage($this->pdf, $this->conf, $this->data);
                        if($x + 20 > ($this->pdf->getWidth())){
                            $this->pdf->addPage('P');
                            $x = 5;
                            $y = 10;
                            $this->addLegendPageImage($this->pdf, $this->conf, $this->data);
                        }
                    }


                    // print legend on first page
                    if($legendConf == true){
                        if(($y-$yStartPosition) + $tempY + 10 > $height && $width > 100){
                            $x += $x + 105;
                            $y = $yStartPosition + 5;
                            if($x - $xStartPosition + 20 > $width){
                                $this->pdf->addPage('P');
                                $x = 5;
                                $y = 10;
                                $legendConf = false;
                                $this->addLegendPageImage($this->pdf, $this->conf, $this->data);

                            }
                        }else if (($y-$yStartPosition) + $tempY + 10 > $height){
                                $this->pdf->addPage('P');
                                $x = 5;
                                $y = 10;
                                $legendConf = false;
                                $this->addLegendPageImage($this->pdf, $this->conf, $this->data);
                        }
                    }
                }


                if ($legendConf == true) {
                    // add legend in legend region on first page
                    // To Be doneCell(0,0,  utf8_decode($title));
                    $this->pdf->SetXY($x,$y);
                    $this->pdf->Cell(0,0,  utf8_decode($title));
                    $this->pdf->Image($image,
                                $x,
                                $y +5 ,
                                ($size[0] * 25.4 / 96), ($size[1] * 25.4 / 96), 'png', '', false, 0);

                        $y += round($size[1] * 25.4 / 96) + 10;
                        if(($y - $yStartPosition + 10 ) > $height && $width > 100){
                            $x +=  105;
                            $y = $yStartPosition + 10;
                        }
                        if(($x - $xStartPosition + 10) > $width && $c < $arraySize ){
                            $this->pdf->addPage('P');
                            $x = 5;
                            $y = 10;
                            $this->pdf->SetFont('Arial', 'B', 11);
                            $height = $this->pdf->getHeight();
                            $width = $this->pdf->getWidth();
                            $legendConf = false;
                            $this->addLegendPageImage($this->pdf, $this->conf, $this->data);
                        }

                  }else{
                      // print legend on second page
                      $this->pdf->SetXY($x,$y);
                      $this->pdf->Cell(0,0,  utf8_decode($title));
                      $this->pdf->Image($image, $x, $y + 5, ($size[0] * 25.4 / 96), ($size[1] * 25.4 / 96), 'png', '', false, 0);

                      $y += round($size[1] * 25.4 / 96) + 10;
                      if($y > ($this->pdf->getHeight())){
                          $x += 105;
                          $y = 10;
                      }
                      if($x + 20 > ($this->pdf->getWidth()) && $c < $arraySize){
                          $this->pdf->addPage('P');
                          $x = 5;
                          $y = 10;
                          $this->addLegendPageImage($this->pdf, $this->conf, $this->data);
                      }

                  }

                unlink($image);
                $c++;
            }
        }
    }


    private function getLegendImage($url)
    {
        $imagename = $this->makeTempFile('mb_printlegend');
        $image = $this->downloadImage($url);
        if ($image) {
            imagepng($image, $imagename);
            imagedestroy($image);
            return $imagename;
        } else {
            return null;
        }
    }

    /**
     * @param PDF_Extensions|\FPDF $pdf
     * @param array $templateData
     * @param array $jobData
     */
    protected function addLegendPageImage($pdf, $templateData, $jobData)
    {
        if (empty($templateData['legendpage_image']) || empty($jobData['legendpage_image'])) {
            return;
        }
        $sourcePath = $this->resourceDir . '/' . $jobData['legendpage_image']['path'];
        $region = $templateData['legendpage_image'];
        if (file_exists($sourcePath)) {
            $this->addImageToPdf($pdf, $sourcePath, $region['x'], $region['y'], 0, $region['height']);
        } else {
            $defaultPath = $this->resourceDir . '/images/legendpage_image.png';
            if ($defaultPath !== $sourcePath && file_exists($defaultPath)) {
                $this->addImageToPdf($pdf, $defaultPath, $region['x'], $region['y'], 0, $region['height']);
            }
        }
    }

    /**
     * Get geometry style
     *
     * @param mixed[] $geometry
     * @return array
     */
    private function getStyle($geometry)
    {
        return array_merge($this->defaultStyle, $geometry['style']);
    }

    private function checkPdfBackground($pdf) {
        $pdfArray = (array) $pdf;
        $pdfFile = $pdfArray['currentFilename'];
        $pdfSubArray = (array) $pdfArray['parsers'][$pdfFile];
        $prefix = chr(0) . '*' . chr(0);
        $pdfSubArray2 = $pdfSubArray[$prefix . '_root'][1][1];

        if (sizeof($pdfSubArray2) > 0 && !array_key_exists('/Outlines', $pdfSubArray2)) {
            return true;
        }

        return false;
    }

    /**
     * @param PDF_Extensions|\FPDF $pdf
     * @param resource|string $gdResOrPath
     * @param int $xOffset
     * @param int $yOffset
     * @param int $width optional, to rescale image
     * @param int $height optional, to rescale image
     */
    protected function addImageToPdf($pdf, $gdResOrPath, $xOffset, $yOffset, $width=0, $height=0)
    {
        if (is_resource($gdResOrPath)) {
            $imageName = $this->makeTempFile('mb_print_pdfbuild');
            imagepng($gdResOrPath, $imageName);
            $this->addImageToPdf($pdf, $imageName, $xOffset, $yOffset, $width, $height);
            unlink($imageName);
        } else {
            $pdf->Image($gdResOrPath, $xOffset, $yOffset, $width, $height, 'png', '', false, 0);
        }
    }
}
