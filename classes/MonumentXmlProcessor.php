<?php
namespace Denkmalatlas;

use Denkmalatlas\SettingsManager;
class MonumentXmlProcessor
{
    private $settings;

    public function __construct(SettingsManager $settings)
    {
        $this->settings = $settings;
    }

    public function transformMonumentXml(\SimpleXMLElement $monument, string $id): ?string
    {
        $xmlStrMonument = $monument->asXML();
        $xmlStrMonument = str_replace('gml:id', 'gml_id', $xmlStrMonument);
        $xmlStrMonument = str_replace(
            '<monument ',
            $this->settings->xmlHeader . '<monuments ' . $this->settings->monumentsNameSpace . '><monument ',
            $xmlStrMonument
        );
        $xmlStrMonument = preg_replace('/<monument\\b[^>]*>/', '<monument>', $xmlStrMonument);
        $xmlStrMonument = str_replace('</monument>', '</monument></monuments>', $xmlStrMonument);
        $monument = simplexml_load_string($xmlStrMonument);
        if ($monument !== false) {
            unset($monument->attributes()->{'gml_id'});
            $xmlStrMonument = $monument->asXML();
            $xmlStrMonument = str_replace('gml_id', 'gml:id', $xmlStrMonument);
            return $xmlStrMonument;
        }
        return null;
    }
}
