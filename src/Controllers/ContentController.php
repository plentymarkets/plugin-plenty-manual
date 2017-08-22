<?php //strict

namespace PlentyManual\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Templates\Twig;
use PlentyManual\Services\PageService;
use IO\Services\SessionStorageService;
use PlentyManual\Services\SearchService;

class ContentController extends Controller
{
    private $twig;
    private $lang;
    private $pageService;
    private $request;
    private $sessionStorage;

    public function __construct( Twig $twig, SessionStorageService $sessionStorage, PageService $pageService, Request $request )
    {
        $this->twig = $twig;
        $this->lang = $sessionStorage->getLang();
        $this->pageService = $pageService;
        $this->request = $request;
        $this->sessionStorage = $sessionStorage;
    }

    public function showContent( string $contentPath ):string
    {
        $currentPage = $this->pageService->getPageByPath( $contentPath );
        $pages = $this->pageService->getPages( $currentPage["id"] );
        return $this->twig->render(
            "PlentyManual::Main",
            [
                "contentTemplate" => "PlentyManual::" . $this->lang . "." . implode( ".", explode( "/", $contentPath ) ),
                "currentPage" => $currentPage,
                "pages" => $pages
            ]
        );
    }

    public function showSearchResults(): string
    {
        $searchService = pluginApp( SearchService::class );
        $query = $this->request->get("q");
        $page = (int)$this->request->get("p", 1);
        $itemsPerPage = (int)$this->request->get("s", null);

        if ( !is_null( $itemsPerPage ) )
        {
            $this->sessionStorage->setSessionValue("manual_search_items_per_page", $itemsPerPage);
        }

        $itemsPerPage = (int)$this->sessionStorage->getSessionValue("manual_search_items_per_page");

        if ( is_null( $itemsPerPage ) || $itemsPerPage <= 0 )
        {
            $itemsPerPage = 25;
        }

        $results = $searchService->search( $query, $page, $itemsPerPage );
        return $this->twig->render(
            "PlentyManual::SearchResults",
            [
                "pages" => $this->pageService->getPages(),
                "query" => $query,
                "results" => $results
            ]
        );
    }

    public function showStartpage(): string
    {
        return $this->twig->render(
            "PlentyManual::Startpage",
            [
                "pages" => $this->pageService->getPages()
            ]
        );
    }
}
