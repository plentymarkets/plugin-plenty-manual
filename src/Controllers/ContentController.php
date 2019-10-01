<?php //strict

namespace PlentyManual\Controllers;

use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Templates\Twig;
use PlentyManual\Services\PageService;
use PlentyManual\Services\PlentyManualChangelog;
use PlentyManual\Services\SearchService;
use Plenty\Modules\System\Contracts\WebstoreConfigurationRepositoryContract;
use Plenty\Plugin\Application;
use PlentyManual\Services\SwitchLanguageService;


class ContentController extends Controller
{
    private $twig;
    private $lang;
    private $pageService;
    private $request;
    private $sessionStorage;
    private $searchUrl = "";
    private $switchLanguageService;

    public function __construct( 
        Twig $twig, 
        FrontendSessionStorageFactoryContract $sessionStorage,
        PageService $pageService, 
        Request $request, 
        WebstoreConfigurationRepositoryContract $webstoreConfigRepo, 
        Application $app,
        SwitchLanguageService $switchLanguageService )
    {
        $this->twig = $twig;
        $this->lang = $sessionStorage->getLocaleSettings()->language ?? 'de';
        $this->pageService = $pageService;
        $this->request = $request;
        $this->sessionStorage = $sessionStorage;
        $this->switchLanguageService = $switchLanguageService;

        $defaultLanguage = $webstoreConfigRepo->findByPlentyId($app->getPlentyId())->defaultLanguage;

        $this->searchUrl = "/search/";
        if ( $this->lang !== $defaultLanguage )
        {
            $this->searchUrl = "/" . $this->lang . "/search/";
        }
        parent::__construct();
    }

    public function showContent( string $contentPath ):string
    {
        if(strpos($contentPath, "sls/") !== false)
        {
            return $this->showSWLanguageSearch();
        }
        elseif (strpos($contentPath, "slp/") !== false)
        {
            $content = explode("slp/", $contentPath);
            return $this->showSWLanguagePage($content[1]);
        }
        else
        {
            $contentPathAndPage = $this->pageService->getPathByUrl($contentPath);
            if(is_array($contentPathAndPage) && isset($contentPathAndPage))
            {
                $contentPath = $contentPathAndPage["path"];
                $currentPage = $contentPathAndPage["page"];
            }
            else
            {
                $currentPage = $this->pageService->getPageByPath( $contentPath );
            }
            $pages = $this->pageService->getPages( $currentPage["id"] );
            return $this->twig->render(
                "PlentyManual::Main",
                [
                    "contentTemplate"   => "PlentyManual::" . $this->lang . "." . implode( ".", explode( "/", $contentPath ) ),
                    "currentPage"       => $currentPage,
                    "pages"             => $pages,
                    "lang"              => $this->lang,
                    "searchUrl"         => $this->searchUrl
                ]
            );
        }
    }

    public function showSearchResults(): string
    {
        $searchService = pluginApp( SearchService::class );
        $query = $this->request->get("q", "");
        $page = (int)$this->request->get("p", 1);
        $itemsPerPage = (int)$this->request->get("s", null);

        if ( !is_null( $itemsPerPage ) )
        {
            $this->sessionStorage->getPlugin()->setValue("manual_search_items_per_page", $itemsPerPage);
        }

        $itemsPerPage = (int)$this->sessionStorage->getPlugin()->getValue("manual_search_items_per_page");

        if ( is_null( $itemsPerPage ) || $itemsPerPage <= 0 )
        {
            $itemsPerPage = 25;
        }

        $results = $searchService->search( $query, $page, $itemsPerPage );
        return $this->twig->render(
            "PlentyManual::SearchResults",
            [
                "pages"     => $this->pageService->getPages(),
                "query"     => $query,
                "results"   => $results,
                "lang"      => $this->lang,
                "searchUrl" => $this->searchUrl
            ]
        );
    }

    public function showSWLanguageSearch(): string
    {
        $query = $this->request->get("q", "");
        $page = (int)$this->request->get("p", 1);
        $itemsPerPage = (int)$this->request->get("s", null);

        if ( !is_null( $itemsPerPage ) )
        {
            $this->sessionStorage->getPlugin()->setValue("manual_search_items_per_page", $itemsPerPage);
        }

        $itemsPerPage = (int)$this->sessionStorage->getPlugin()->getValue("manual_search_items_per_page");

        if ( is_null( $itemsPerPage ) || $itemsPerPage <= 0 )
        {
            $itemsPerPage = 25;
        }

        $results = $this->switchLanguageService->sLSearch();
        return $this->twig->render(
            "PlentyManual::SearchResults",
            [
                "pages"     => $this->pageService->getPages(),
                "query"     => $query,
                "results"   => $results,
                "lang"      => $this->lang,
                "searchUrl" => $this->searchUrl
            ]
        );

    }

    public function showSWLanguagePage(string $contentPath ):string
    {
        if($this->lang === "de")
            $contentPath = 'en/'.$contentPath;
        $pathAndPage = $this->pageService->getPathByUrl($contentPath, true);
        if(is_array($pathAndPage) && isset($pathAndPage))
        {
            $currentPage = $pathAndPage["page"];
        }
        else
        {
            $currentPage = $this->pageService->getPageByPath( $contentPath, true );
        }

        $currentPageLanguageID = $currentPage["languageID"];

        $pageURL = $this->switchLanguageService->sLPage($currentPageLanguageID);

        $pageURL = ltrim($pageURL, '/');

        $contentPathAndPage = $this->pageService->getPathByUrl($pageURL);
        if(is_array($contentPathAndPage) && isset($contentPathAndPage))
        {
            $contentPath = $contentPathAndPage["path"];
            $switchPage = $contentPathAndPage["page"];
        }
        else
        {
            $switchPage = $this->pageService->getPageByPath( $contentPath );
        }

        $pages = $this->pageService->getPages( $switchPage["id"] );
        return $this->twig->render(
            "PlentyManual::Main",
            [
                "contentTemplate"   => "PlentyManual::" . $this->lang . "." . implode( ".", explode( "/", $contentPath ) ),
                "currentPage"       => $switchPage,
                "pages"             => $pages,
                "lang"              => $this->lang,
                "searchUrl"         => $this->searchUrl
            ]
        );
    }

    public function showStartpage(PlentyManualChangelog $plentyManualChangelog): string
    {
        $changelogEntries = $plentyManualChangelog->getChangelog();

        return $this->twig->render(
            "PlentyManual::Startpage",
            [
                "pages"     => $this->pageService->getPages(),
                "lang"      => $this->lang,
                "searchUrl" => $this->searchUrl,
                'changelog' => $changelogEntries,
            ]
        );
    }
}