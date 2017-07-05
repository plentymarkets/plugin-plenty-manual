<?php //strict

namespace PlentyManual\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Templates\Twig;
use PlentyManual\Extensions\TwigManualExtension;

/**
 * Class IOServiceProvider
 * @package IO\Providers
 */
class PlentyManualServiceProvider extends ServiceProvider
{
    /**
     * Register the core functions
     */
    public function register()
    {
        $this->getApplication()->register(PlentyManualRouteServiceProvider::class);
    }

    /**
     * boot twig extensions and services
     * @param Twig $twig
     */
    public function boot(Twig $twig)
    {
        $twig->addExtension('Twig_Extensions_Extension_Intl');
        $twig->addExtension( TwigManualExtension::class );
    }
}
