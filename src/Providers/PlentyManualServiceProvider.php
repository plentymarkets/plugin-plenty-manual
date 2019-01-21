<?php //strict

namespace PlentyManual\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Templates\Twig;
use PlentyManual\Extensions\TwigManualExtension;
use Plenty\Modules\Cron\Services\CronContainer;
use PlentyManual\Cron\BrokenLinksCron;

/**
 * Class PlentyManualServiceProvider
 * @package PlentyManual\Providers
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
    public function boot(Twig $twig, CronContainer $cronContainer)
    {
        $twig->addExtension('Twig_Extensions_Extension_Intl');
        $twig->addExtension( TwigManualExtension::class );

        $cronContainer->add( CronContainer::DAILY, BrokenLinksCron::class);
    }
}
