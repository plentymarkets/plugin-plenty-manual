<?php //strict

namespace PlentyManual\Extensions;

use Plenty\Plugin\Templates\Extensions\Twig_Extension;
use PlentyManual\Services\PageService;

class TwigManualExtension extends Twig_Extension
{
    public function getName():string
    {
        return "Manual_Extension_TwigManualExtensions";
    }

    /**
     * Return a list of filters to add.
     *
     * @return array The list of filters to add.
     */
    public function getFilters():array
    {
        return [];
    }

    /**
     * Return a list of functions to add.
     *
     * @return array the list of functions to add.
     */
    public function getFunctions():array
    {
        return [];
    }

    /**
     * Return a list of global variables
     * @return array
     */
    public function getGlobals():array
    {
        return [
            "pageService" => pluginApp( PageService::class )
        ];
    }
}