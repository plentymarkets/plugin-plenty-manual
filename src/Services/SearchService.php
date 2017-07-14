<?php //strict

namespace PlentyManual\Services;

use Plenty\Plugin\ConfigRepository;
use IO\Services\SessionStorageService;

class SearchService
{
    private $type;
    private $host;
    private $snippetLength;

    public function __construct( SessionStorageService $sessionStorage, ConfigRepository $config )
    {
        $this->type = "manual_" . $sessionStorage->getLang();
        $this->host = $config->get('PlentyManual.search.es_host');
        $this->snippetLength = $config->get('PlentyManual.search.snippet_length');
    }

    public function search(
        string $query,
        int $page = 1,
        int $itemsPerPage = 3,
        bool $conjunctive = true
    )
    {
        $operator = $conjunctive ? "and" : "or";
        $sectionQuery = [
            "nested" => [
                "path" => "sections",
                "query" => [
                    "dis_max" => [
                        "queries" => [
                            [
                                "match" => [
                                    "sections.title" => [ "query" => $query, "operator" => $operator ]
                                ]
                            ],
                            [
                                "match" => [
                                    "sections.content" => [ "query" => $query, "operator" => $operator ]
                                ]
                            ]
                        ],
                        "tie_breaker" => 0.3
                    ]
                ],
                "inner_hits" => [
                    "_source" => [
                        "includes" => [ "id", "url", "title", "content" ]
                    ],
                    "highlight" => [
                        "pre_tags" => ["<em class=\"search-item-keyword\">"],
                        "post_tags" => ["</em>"],
                        "fields" => [
                            "sections.title" => ["number_of_fragments" => 0],
                            "sections.content" => ["number_of_fragments" => 1, "fragment_size" => $this->snippetLength]
                        ]
                    ]
                ]
            ]
        ];

        $documentQuery = [
            "dis_max" => [
                "queries" => [
                    [
                        "match" => [
                            "title" => [ "query" => $query, "operator" => $operator ]
                        ]
                    ],
                    [
                        "match" => [
                            "description" => [ "query" => $query, "operator" => $operator ]
                        ]
                    ]
                ],
                "tie_breaker" => 0.3
            ],
        ];

        $mainQuery = [
            "_source" => ["url", "title", "description"],
            "query" => [
                "dis_max" => [
                    "queries" => [
                        $sectionQuery,
                        $documentQuery
                    ],
                    "tie_breaker" => 0.3
                ]
            ],
            "highlight" => [
                "pre_tags" => ["<em class=\"search-item-keyword\">"],
                "post_tags" => ["</em>"],
                "fields" => [
                    "title" => ["number_of_fragments" => 0],
                    "description" => ["number_of_fragments" => 1, "fragment_size" => $this->snippetLength]
                ]
            ]
        ];

        $from = ($page - 1) * $itemsPerPage ;
        $cHandle = curl_init( "{$this->host}/{$this->type}/_search?size={$itemsPerPage}&from{$from}" );
        curl_setopt( $cHandle, CURLOPT_POST, true );
        curl_setopt( $cHandle, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $cHandle, CURLOPT_POSTFIELDS, json_encode( $mainQuery ) );

        $response = curl_exec( $cHandle );

        curl_close( $cHandle );

        $result = json_decode( $response, true );
        return $this->readSearchResults( $result, $page, $itemsPerPage );
    }

    private function readSearchResults( $resultData, $page, $itemsPerPage )
    {
        $totalHits = $resultData["hits"]["total"];
        $maxPages = ($totalHits - $totalHits % $itemsPerPage ) / $itemsPerPage;
        if ( $totalHits % $itemsPerPage > 0 )
        {
            $maxPages++;
        }
        $hitsEnd = ($page * $itemsPerPage);
        if ( $hitsEnd > $totalHits )
        {
            $hitsEnd = $totalHits;
        }
        $result = [
            "currentPage" => $page,
            "itemsPerPage" => $itemsPerPage,
            "maxPages" => $maxPages,
            "hitsStart" => ($page - 1) * $itemsPerPage + 1,
            "hitsEnd" => $hitsEnd,
            "hitsTotal" => $totalHits,
            "hits" => []
        ];

        foreach( $resultData["hits"]["hits"] as $hit )
        {
            $doc = [
                "title" => $hit["highlight"]["title"][0],
                "description" => $hit["highlight"]["description"][0],
                "url" => $hit["_source"]["url"],
                "sections" => []
            ];

            if ( $doc["title"] === null )
            {
                $doc["title"] = $hit["_source"]["title"];
            }
            if ( $doc["description"] === null )
            {
                $doc["description"] = $this->trim( $hit["_source"]["description"], $this->snippetLength );
            }

            $sections = [];
            foreach ( $hit["inner_hits"]["sections"]["hits"]["hits"] as $inner_hit )
            {
                $sec = [
                    "id" => $inner_hit["_source"]["id"],
                    "url" => $inner_hit["_source"]["url"],
                    "title" => $inner_hit["highlight"]["sections.title"][0],
                    "content" => $this->trim( $inner_hit["highlight"]["sections.content"][0], $this->snippetLength )
                ];

                if ( $sec["title"] === null )
                {
                    $sec["title"] = $inner_hit["_source"]["title"];
                }
                if ( $sec["content"] === null )
                {
                    $sec["content"] = $this->trim( $inner_hit["_source"]["content"], $this->snippetLength );
                }
                array_push( $sections, $sec );
            }

            $doc["sections"] = $sections;
            array_push( $result["hits"], $doc );
        }

        return $result;
    }

    function trim( string $text = null, int $length = 250 )
    {
        if ( $text === null )
        {
            return null;
        }
        while ( preg_match( '/^[^\w<>\/]/', $text ) )
        {
            $text = substr( $text, 1 );
        }
        while( preg_match( '/^[^\s]/', substr( $text, $length ) )  )
        {
            $length++;
        }

        return substr( $text, 0, $length );
    }
}