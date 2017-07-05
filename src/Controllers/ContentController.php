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

    public function __construct( Twig $twig, SessionStorageService $sessionStorage, PageService $pageService, Request $request )
    {
        $this->twig = $twig;
        $this->lang = $sessionStorage->getLang();
        $this->pageService = $pageService;
        $this->request = $request;
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
        $itemsPerPage = (int)$this->request->get("s", 10);
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
            "PlentyManual::StartPage",
            [
                "pages" => $this->pageService->getPages()
            ]
        );
    }
}
