<?php //strict

namespace PlentyManual\Services;

use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use PlentyManual\Helpers\Metadata;

class PageService
{
    private $lang = "de";

    public function __construct( FrontendSessionStorageFactoryContract $sessionStorage )
    {
        $this->lang = $sessionStorage->getLocaleSettings()->language ?? 'de';
    }

    public function getPages( string $activePageId = null )
    {
        if ( $this->lang === "de" )
        {
            $pages = Metadata::DE;
        }
        else
        {
            $pages = Metadata::EN;
        }

        if ( $activePageId !== null )
        {
            return $this->markActive( $pages, $activePageId );
        }

        return $pages;
    }

    private function markActive( $pageList, $activePageId )
    {
        for( $i = 0; $i < count($pageList); $i++ )
        {
            $pageList[$i]["isActive"] = $pageList[$i]["id"] === $activePageId;
            $pageList[$i]["isOpen"] = $this->hasChild( $pageList[$i], $activePageId );
            $pageList[$i]["children"] = $this->markActive( $pageList[$i]["children"], $activePageId );
        }

        return $pageList;
    }

    private function hasChild( $page, $childId )
    {
        foreach( $page["children"] as $child )
        {
            if ( $child["id"] === $childId || $this->hasChild( $child, $childId ) )
            {
                return true;
            }
        }

        return false;
    }

    public function getPathByUrl(string $path)
    {
        $pathRes = null;
        $path = "/".$path;
        $pages = $this->getPages();
        for ($i = 0; $i < count($pages); $i++ )
        {
            if(is_array($pathRes))
                continue;
            else
                $pathRes = $this->searchPage($pages[$i], $path, $pathRes);

        }

        return $pathRes;
    }

    private function searchPage($page, $string, $pathRes)
    {
        if($page["url"] === $string)
        {
            $pathRes["path"] = $page["path"];
            $pathRes["page"] = $page;
            return $pathRes;
        }
        elseif($page["hasChildren"] === true)
        {
            for ($i = 0; $i < count($page["children"]); $i++)
            {
                if(is_array($pathRes))
                    continue;
                else
                    $pathRes = $this->searchPage($page["children"][$i], $string, $pathRes);
            }
        }
        return $pathRes;
    }

    public function getPageByPath( string $path )
    {
        $levels = explode( "/", $path );
        $pages = $this->getPages();
        $page = null;
        foreach( $levels as $lvl )
        {
            foreach( $pages as $p )
            {
                $page = null;
                if( $p["urlName"] === $lvl )
                {
                    $page = $p;
                    $pages = $p["children"];
                    break;
                }
            }
        }

        return $page;
    }

    public function getHierarchy( string $path )
    {
        $levels = explode( "/", $path );
        $pages = $this->getPages();
        $hierarchy = [];
        foreach( $levels as $lvl )
        {
            foreach( $pages as $p )
            {
                if( $p["urlName"] === $lvl )
                {
                    array_push( $hierarchy, $p );
                    $pages = $p["children"];
                    break;
                }
            }
        }

        return $hierarchy;
    }

}