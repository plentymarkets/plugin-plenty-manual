<?php //strict

namespace PlentyManual\Services;

use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\ConfigRepository;
use PlentyManual\Helpers\ResultsStorage;

class SearchService
{
    private $type;
    private $host;
    private $snippetLength;
    private $resultsStorage;

    public function __construct( FrontendSessionStorageFactoryContract $sessionStorage, ConfigRepository $config, ResultsStorage $resultsStorage )
    {
        $lang = $sessionStorage->getLocaleSettings()->language ?? 'de';
        $this->type = "manual_" . $lang;
        $this->host = $config->get('PlentyManual.search.es_host');
        $this->snippetLength = $config->get('PlentyManual.search.snippet_length');
        $this->resultsStorage = $resultsStorage;
    }

    /**
     * @param string $query
     * @param int $page
     * @param int $itemsPerPage
     * @param bool $conjunctive
     * @return array
     */
    public function search(
        string $query,
        int $page = 1,
        int $itemsPerPage = 3,
        bool $conjunctive = true
    )
    {
        $query = rawurldecode($query);
        $query = str_replace("+", " ", $query);

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
                            "keywords" => [ "query" => $query, "operator" => $operator ]
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
            "_source" => ["url", "title", "keywords", "description", "languageID", "position"],
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

        $requestArray = array('host' => $this->host,
            'type' => $this->type,
            'itemsPerPage' => $itemsPerPage,
            'from' => $from,
            'query' => $mainQuery);

        $result = $this->serverCall($requestArray);

        return $this->readSearchResults( $result, $page, $itemsPerPage, $query);
    }

    /**
     * @param $requestArray
     * @return mixed
     */
    private function serverCall($requestArray)
    {
        if((isset($requestArray['itemsPerPage']) && trim($requestArray['itemsPerPage']) != '') || (isset($requestArray['from']) && trim($requestArray['from']) != ''))
            $cHandle = curl_init( "{$requestArray['host']}/{$requestArray['type']}/_search?size={$requestArray['itemsPerPage']}&from={$requestArray['from']}" );
        else
            $cHandle = curl_init( "{$requestArray['host']}/{$requestArray['type']}/_search?" );

        curl_setopt( $cHandle, CURLOPT_POST, true );
        curl_setopt( $cHandle, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $cHandle, CURLOPT_POSTFIELDS, json_encode( $requestArray['query'] ) );

        $response = curl_exec( $cHandle );

        curl_close( $cHandle );

        $result = json_decode( $response, true );

        return $result;
    }

    /**
     * @param $resultData
     * @param $page
     * @param $itemsPerPage
     * @param $query
     * @return array
     */
    private function readSearchResults( $resultData, $page, $itemsPerPage, $query )
    {
        $storageArray = array();
        $suggestionsData = array();
        $totalHits = $resultData["hits"]["total"];
        $maxPages = ($totalHits - $totalHits % $itemsPerPage ) / $itemsPerPage;
        if ($totalHits == 0)
        {
            $suggestionsData = $this->suggestQuerySearch($query);
        }

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

        if (isset($suggestionsData))
        {
            $result["suggestions"] = $this->createSuggestArray($suggestionsData);
        }

        foreach( $resultData["hits"]["hits"] as $hit )
        {
            if(!isset($hit["_source"]["position"]) || trim($hit["_source"]["position"]) === '0' || trim($hit["_source"]["position"]) >= '10000')
            {
                unset($resultData["_source"]["position"]);
                continue;
            }

            $doc = [
                "title" => $hit["highlight"]["title"][0],
                "description" => $hit["highlight"]["description"][0],
                "url" => $hit["_source"]["url"],
                "sections" => [],
                "cutBefore" => false,
                "cutAfter" => false
            ];

            if ( $doc["title"] === null )
            {
                $doc["title"] = $hit["_source"]["title"];
            }
            if ( $doc["description"] === null )
            {
                $doc["description"] = $this->trim( $hit["_source"]["description"], $this->snippetLength );
            }

            if( isset($hit["_source"]["languageID"]) && trim($hit["_source"]["languageID"]) != '')
            {
                array_push($storageArray, $hit["_source"]["languageID"]);
            }

//            if ( strip_tags( $doc["description"] ) !== substr( $hit["_source"]["description"], 0, strlen( strip_tags( $doc["description"] ) ) ) )
            if ( !$this->startsWith( $doc["description"], $hit["_source"]["description"] ) )
            {
                $doc["cutBefore"] = true;
            }

//            if ( strip_tags( $doc["description"] ) !== substr( $hit["_source"]["description"], strlen($hit["_source"]["description"]) - strlen( strip_tags( $doc["description"] ) ) ) )
            if ( !$this->endsWith( $doc["description"], $hit["_source"]["description"] ) )
            {
                $doc["cutAfter"] = true;
            }



            $sections = [];
            foreach ( $hit["inner_hits"]["sections"]["hits"]["hits"] as $inner_hit )
            {
                $sec = [
                    "id" => $inner_hit["_source"]["id"],
                    "url" => $inner_hit["_source"]["url"],
                    "title" => $inner_hit["highlight"]["sections.title"][0],
                    "content" => $this->trim( $inner_hit["highlight"]["sections.content"][0], $this->snippetLength ),
                    "cutBefore" => false,
                    "cutAfter" => false
                ];

                if ( $sec["title"] === null )
                {
                    $sec["title"] = $inner_hit["_source"]["title"];
                }

                if ( $sec["content"] === null )
                {
                    $sec["content"] = $this->trim( $inner_hit["_source"]["content"], $this->snippetLength );
                }

//                if ( trim( strip_tags( $sec["content"] ) ) !== substr( trim($inner_hit["_source"]["content"]), 0, strlen( trim( strip_tags( $sec["content"] ) ) ) ) )
                if ( !$this->startsWith( $sec["content"], $inner_hit["_source"]["content"] ) )
                {
                    $sec["cutBefore"] = true;
                }

//                if ( trim( strip_tags( $sec["content"] ) ) !== substr( $inner_hit["_source"]["content"], strlen( trim( $inner_hit["_source"]["content"]) ) - strlen( trim( strip_tags( $sec["content"] ) ) ) ) )
                if ( !$this->endsWith( $sec["content"], $inner_hit["_source"]["content"] ) )
                {
                    $sec["cutAfter"] = true;
                }

                array_push( $sections, $sec );
            }

            $doc["sections"] = $sections;
            array_push( $result["hits"], $doc );
        }

        $storageArray = $this->resultsStorage->setResults($storageArray);

        return $result;
    }

    /**
     * @param $str
     * @param $cmp
     * @return bool
     */
    function startsWith( $str, $cmp )
    {
        $str = trim( strip_tags( $str ) );
        $cmp = trim( strip_tags( $cmp ) );
        return $str === substr( $cmp, 0, strlen( $str ) );
    }

    /**
     * @param $str
     * @param $cmp
     * @return bool
     */
    function endsWith( $str, $cmp )
    {
        $str = trim( strip_tags( $str ) );
        $cmp = trim( strip_tags( $cmp ) );
        return $str === substr( $cmp, strlen( $cmp ) - strlen( $str ) );
    }

    /**
     * @param string|null $text
     * @param int $length
     * @return bool|null|string
     */
    function trim( string $text = null, int $length = 250 )
    {
        if ( $text === null )
        {
            return null;
        }
        while ( preg_match( '/^[^\wäöüÄÖÜß<>\/]/', $text ) )
        {
            $text = substr( $text, 1 );
        }
        while( preg_match( '/^[^\s]/', substr( $text, $length ) )  )
        {
            $length++;
        }

        return substr( $text, 0, $length );
    }

    /**
     * @param $query
     * @return mixed
     */
    private function suggestQuerySearch($query)
    {

        $tokens = explode(' ', $query);

        $index = 1;

        $numberOfItems = count($tokens);

        if($numberOfItems < 2)
        {
            $suggestionQuery = $this->createSuggestQuery($query,$index);

            $requestArray = array('host' => $this->host,
                'type' => $this->type,
                'query' => $suggestionQuery);

            return $this->serverCall($requestArray);

        }
        else
        {
            $result = array();

            foreach ($tokens as $token)
            {
                $tempArray = $this->createSuggestQuery($token,$index);

                $requestArray = array('host' => $this->host,
                    'type' => $this->type,
                    'query' => $tempArray);

                $queryArray = $this->serverCall($requestArray);

                if($index == 2)
                {
                    $result = array_merge($result["suggest"], $queryArray["suggest"]);
                }
                elseif($index > 2)
                {
                    $result = array_merge($result, $queryArray["suggest"]);
                }
                else
                    $result = $queryArray;


                $index++;
            }

            return $result;
        }

    }

    /**
     * @param $suggestArrayData
     * @return array
     */
    private function createSuggestArray($suggestArrayData)
    {

        $suggestArray = array();

        if(isset($suggestArrayData["suggest"]))
        {
            $result = array();

            $searchQuery = '';

            foreach ($suggestArrayData["suggest"]["suggestion_content_1"][0]["options"] as $suggestion)
            {
                array_push($suggestArray, $suggestion["text"]);
            }

            foreach ($suggestArrayData["suggest"]["suggestion_description_1"][0]["options"] as $suggestion)
            {
                array_push($suggestArray, $suggestion["text"]);
            }

            foreach ($suggestArrayData["suggest"]["suggestion_title_content_1"][0]["options"] as $suggestion)
            {
                array_push($suggestArray, $suggestion["text"]);
            }

            foreach ($suggestArrayData["suggest"]["suggestion_title_1"][0]["options"] as $suggestion)
            {
                array_push($suggestArray, $suggestion["text"]);
            }

            $resultTemp = array_unique($suggestArray);

            foreach ($resultTemp as $key => $value)
                $searchQuery .= $value." ";

            $result = $this->searchForWords($searchQuery);

            return $result;
        }
        else
        {
            $itemsNb = count($suggestArrayData) / 4;
            return  $this->createArrayForSuggestion($suggestArrayData, $itemsNb);
        }


    }

    /**
     * @param $query
     * @param $index
     * @return array
     */
    private function createSuggestQuery($query, $index)
    {

        $queryArray = [
            "suggest" => [
                "text" => $query,
                "suggestion_content_".$index => [
                    "phrase" => [
                        "field" => "sections.content",
                        "real_word_error_likelihood" => 0.75,
                        "max_errors" => 0.5,
                        "confidence" => 0,
                        "size" => 3,
                        "shard_size" => 1,
                        "highlight" => [
                            "pre_tag" => "<em>",
                            "post_tag" => "</em>"
                        ],
                        "collate" => [
                            "query" => [
                                "inline" => [
                                    "match" => [
                                        "" => ""
                                    ]
                                ]
                            ],
                            "params" => [
                                "field_name" => "sections.content"
                            ],
                            "prune" => true
                        ]
                    ]
                ],
                "suggestion_description_".$index => [
                    "phrase" => [
                        "field" => "description",
                        "real_word_error_likelihood" => 0.75,
                        "max_errors" => 0.5,
                        "confidence" => 0,
                        "size" => 4,
                        "shard_size" => 1,
                        "highlight" => [
                            "pre_tag" => "<em>",
                            "post_tag" => "</em>"
                        ],
                        "collate" => [
                            "query" => [
                                "inline" => [
                                    "match" => [
                                        "" => ""
                                    ]
                                ]
                            ],
                            "params" => [
                                "field_name" => "description"
                            ],
                            "prune" => true
                        ]
                    ]
                ],
                "suggestion_title_content_".$index => [
                    "phrase" => [
                        "field" => "sections.title",
                        "real_word_error_likelihood" => 0.75,
                        "max_errors" => 0.5,
                        "confidence" => 0,
                        "size" => 4,
                        "shard_size" => 1,
                        "highlight" => [
                            "pre_tag" => "<em>",
                            "post_tag" => "</em>"
                        ],
                        "collate" => [
                            "query" => [
                                "inline" => [
                                    "match" => [
                                        "" => ""
                                    ]
                                ]
                            ],
                            "params" => [
                                "field_name" => "sections.title"
                            ],
                            "prune" => true
                        ]
                    ]
                ],
                "suggestion_title_".$index => [
                    "phrase" => [
                        "field" => "title",
                        "real_word_error_likelihood" => 0.75,
                        "max_errors" => 0.5,
                        "confidence" => 0,
                        "size" => 4,
                        "shard_size" => 1,
                        "highlight" => [
                            "pre_tag" => "<em>",
                            "post_tag" => "</em>"
                        ],
                        "collate" => [
                            "query" => [
                                "inline" => [
                                    "match" => [
                                        "" => ""
                                    ]
                                ]
                            ],
                            "params" => [
                                "field_name" => "title"
                            ],
                            "prune" => true
                        ]
                    ]
                ]

            ]
        ];

        return $queryArray;

    }

    /**
     * @param $suggestionArray
     * @param $itemsNb
     * @return array
     */
    private function createArrayForSuggestion($suggestionArray, $itemsNb)
    {

        $arrayTemp = array();

        $suggestedArrayContentTemp = array();

        $suggestedArrayDescriptionTemp = array();

        $suggestedArrayTitleContentTemp = array();

        $suggestedArrayTitleTemp = array();


        for($index = 1; $index <= $itemsNb; $index++)
        {
            if(empty($suggestionArray["suggestion_content_".$index][0]["options"]) && empty($suggestionArray["suggestion_description_".$index][0]["options"]) && empty($suggestionArray["suggestion_title_content_".$index][0]["options"]) && empty($suggestionArray["suggestion_title_".$index][0]["options"]))
            {
                array_push($arrayTemp, $suggestionArray["suggestion_content_".$index][0]["text"]);

                $suggestedArrayContentTemp[$index] = $arrayTemp;

                $suggestedArrayDescriptionTemp[$index] = $arrayTemp;

                $suggestedArrayTitleContentTemp[$index] = $arrayTemp;

                $suggestedArrayTitleTemp[$index] =   $arrayTemp;

                $arrayTemp = array();

            }
            else
            {

                $suggestionMissing = array();

                foreach ($suggestionArray["suggestion_content_".$index][0]["options"] as $suggestion)
                {
                    array_push($arrayTemp, $suggestion["text"]);
                }

                if(empty($suggestionMissing) && !empty($arrayTemp))
                {
                    $suggestionMissing = $arrayTemp;
                }

                $suggestedArrayContentTemp[$index] = $arrayTemp;

                $arrayTemp = array();

                foreach ($suggestionArray["suggestion_description_".$index][0]["options"] as $suggestion)
                {
                    array_push($arrayTemp, $suggestion["text"]);
                }

                if(empty($suggestionMissing) && !empty($arrayTemp))
                {
                    $suggestionMissing = $arrayTemp;
                }

                $suggestedArrayDescriptionTemp[$index] = $arrayTemp;

                $arrayTemp = array();

                foreach ($suggestionArray["suggestion_title_content_".$index][0]["options"] as $suggestion)
                {
                    array_push($arrayTemp, $suggestion["text"]);
                }

                if(empty($suggestionMissing) && !empty($arrayTemp))
                {
                    $suggestionMissing = $arrayTemp;
                }

                $suggestedArrayTitleContentTemp[$index] = $arrayTemp;

                $arrayTemp = array();

                foreach ($suggestionArray["suggestion_title_".$index][0]["options"] as $suggestion)
                {
                    array_push($arrayTemp, $suggestion["text"]);
                }

                if(empty($suggestionMissing) && !empty($arrayTemp))
                {
                    $suggestionMissing = $arrayTemp;
                }

                $suggestedArrayTitleTemp[$index] = $arrayTemp;

                $arrayTemp = array();

                if(empty($suggestionArray["suggestion_content_".$index][0]["options"]))
                {
                    $suggestedArrayContentTemp[$index] = $suggestionMissing;
                }

                if(empty($suggestionArray["suggestion_description_".$index][0]["options"]))
                {
                    $suggestedArrayDescriptionTemp[$index] = $suggestionMissing;

                }

                if(empty($suggestionArray["suggestion_title_content_".$index][0]["options"]))
                {
                    $suggestedArrayTitleContentTemp[$index] = $suggestionMissing;
                }

                if(empty($suggestionArray["suggestion_title_".$index][0]["options"]))
                {
                    $suggestedArrayTitleTemp[$index] = $suggestionMissing;
                }

            }

        }

        $suggestions = array();

        $suggestionArrayTemp = $this->createCombinedSuggestionArray($suggestedArrayTitleTemp, $suggestedArrayDescriptionTemp, $suggestedArrayContentTemp, $suggestedArrayTitleContentTemp);

        $itNb = count($suggestionArrayTemp);

        $suggestionsTemp = $this->searchForWords($suggestionArrayTemp[$itNb-1]);

        unset($suggestionArrayTemp[$itNb-1]);

        for($i = 0; $i < count($suggestionArrayTemp); $i++)
        {
            $suggestions[$i] = $this->checkForWords($suggestionArrayTemp[$i], $suggestionsTemp);
        }

        $suggestions = array_intersect_key($suggestions, array_unique(array_map('serialize', $suggestions)));

        $suggestionArray = $this->createCombinedArray($suggestions[0], $suggestions[1]);

        for($i = 2; $i < count($suggestions); $i++)
        {
            $suggestionArray = $this->createCombinedArray($suggestionArray, $suggestions[$i]);
        }

        return $suggestionArray;

    }

    /**
     * @param $firstArray
     * @param $secondArray
     * @return array
     */
    private function createCombinedArray($firstArray, $secondArray)
    {
        $resultArray = array();

        $index = 0;

        for($i = 0; $i < count($firstArray); $i++)
        {
            for($j = 0; $j < count($secondArray); $j++)
            {
                $resultArray[$index] = $firstArray[$i]. " " . $secondArray[$j];
                $index++;
            }
        }

        return $resultArray;

    }

    /**
     * @param $suggestedArrayTitleTemp
     * @param $suggestedArrayDescriptionTemp
     * @param $suggestedArrayContentTemp
     * @param $suggestedArrayTitleContentTemp
     * @return array
     */
    private function createCombinedSuggestionArray($suggestedArrayTitleTemp, $suggestedArrayDescriptionTemp, $suggestedArrayContentTemp, $suggestedArrayTitleContentTemp)
    {

        $suggestArray = array();

        foreach($suggestedArrayTitleTemp as $key => $value)
        {
            array_push($suggestArray, $value[0]);
        }

        foreach($suggestedArrayDescriptionTemp as $key => $value)
        {
            array_push($suggestArray, $value[0]);
        }

        foreach($suggestedArrayContentTemp as $key => $value)
        {
            array_push($suggestArray, $value[0]);
        }

        foreach($suggestedArrayTitleContentTemp as $key => $value)
        {
            array_push($suggestArray, $value[0]);
        }

        $resultTemp = array_unique($suggestArray);

        $numItems = count($resultTemp);

        $result = array();

        foreach ($resultTemp as $key => $value)
            array_push($result, $value);

        $searchQuery = '';

        foreach($result as $res)
            $searchQuery .= $res." ";

        $result[$numItems] = $searchQuery;

        return $result;
    }

    /**
     * @param $query
     * @return array
     */
    private function searchForWords($query)
    {
        $conjunctive = true;
        $operator = $conjunctive ? "or" : "and";
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
                        "pre_tags" => ["<p>"],
                        "post_tags" => ["</p>"],
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
                "pre_tags" => ["<p>"],
                "post_tags" => ["</p>"],
                "fields" => [
                    "title" => ["number_of_fragments" => 0],
                    "description" => ["number_of_fragments" => 1, "fragment_size" => $this->snippetLength]
                ]
            ]
        ];


        $requestArray = array('host' => $this->host,
            'type' => $this->type,
            'query' => $mainQuery);

        $resultTemp = $this->serverCall($requestArray);

        return $this->extractHighlights($resultTemp);

    }

    /**
     * @param $resultData
     * @return array
     */
    private function extractHighlights($resultData)
    {
        $pattern = "/<p>(.*?)<\/p>/";

        $highlights = array();

        $result = array();

        foreach( $resultData["hits"]["hits"] as $hit )
        {

            if(isset($hit["highlight"]["title"]))
            {
                foreach ($hit["highlight"]["title"] as $title)
                {
                    if(preg_match_all($pattern,$title,$matches))
                    {
                        foreach ($matches[1] as $key => $value)
                        {
                            $tempVariable = $this->removeHighlight($value);
                            array_push($highlights, $tempVariable);
                        }
                    }
                }
            }

            if(isset($hit["highlight"]["description"]))
            {
                foreach ($hit["highlight"]["description"] as $description)
                {
                    if(preg_match_all($pattern,$description,$matches))
                    {
                        foreach ($matches[1] as $key => $value)
                        {
                            $tempVariable = $this->removeHighlight($value);
                            array_push($highlights, $tempVariable);
                        }
                    }
                }
            }


            foreach($hit["inner_hits"]["sections"]["hits"]["hits"] as $innerHit)
            {
                if(isset($innerHit["highlight"]["sections.content"]))
                {
                    foreach ($innerHit["highlight"]["sections.content"] as $content)
                    {
                        if(preg_match_all($pattern,$content,$matches))
                        {
                            foreach ($matches[1] as $key => $value)
                            {
                                $tempVariable = $this->removeHighlight($value);
                                array_push($highlights, $tempVariable);
                            }
                        }
                    }
                }

                if(isset($innerHit["highlight"]["sections.title"]))
                {
                    foreach ($innerHit["highlight"]["sections.title"] as $titleContent)
                    {
                        if(preg_match_all($pattern,$titleContent,$matches))
                        {
                            foreach ($matches[1] as $key => $value)
                            {
                                $tempVariable = $this->removeHighlight($value);
                                array_push($highlights, $tempVariable);
                            }
                        }
                    }
                }
            }

        }

        $resultTemp = array_unique($highlights);

        foreach($resultTemp as $value)
        {
            array_push($result, $value);
        }

        return $result;
    }

    /**
     * @param $value
     * @return string
     */
    private function removeHighlight($value)
    {
        $result = str_replace("<p>","",$value);
        $result = str_replace("</p>","", $result);
        $result = str_replace("’","", $result);
        return strtolower($result);
    }

    /**
     * @param $word
     * @param $suggestionArray
     * @return array
     */
    private function checkForWords($word, $suggestionArray)
    {
        $suggestions = array();

        if(strlen($word) > 3)
            $word = substr($word, 0 , -1);

        foreach ($suggestionArray as $value)
        {
            if(strpos($value, $word) !== false)
                array_push($suggestions, $value);
        }

        return $suggestions;
    }

}
