<?php //strict

namespace PlentyManual\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;

class PlentyManualRouteServiceProvider extends RouteServiceProvider
{
    public function register()
    {
    }

    public function map( Router $router )
    {
        $router->get('', 'PlentyManual\Controllers\ContentController@showStartpage');

        $router->get('search', 'PlentyManual\Controllers\ContentController@showSearchResults');

        $router->get('{contentPath}', 'PlentyManual\Controllers\ContentController@showContent')
               ->where('contentPath', '.*');

        $router->get('sls', 'PlentyManual\Controllers\ContentController@showSWLanguageSearch');

        $router->get('slp/{contentPath}', 'PlentyManual\Controllers\ContentController@showSWLanguagePage')
            ->where('contentPath', '.*');
    }
}