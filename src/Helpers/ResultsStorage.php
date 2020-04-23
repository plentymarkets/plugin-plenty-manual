<?php

namespace PlentyManual\Helpers;

class ResultsStorage
{

    private static $storageArray = null;

    public function __construct()
    {
    }

    public function deleteResults()
    {
        foreach (self::$storageArray as $key => $value)
            unset(self::$storageArray[$key]);
        return true;
    }

    public function setResults($arr)
    {
        $deleteFlag = false;
        if(self::$storageArray === null)
        {
            self::$storageArray = $arr;
        }
        else
        {
            $deleteFlag = $this->deleteResults();
            self::$storageArray = $arr;
        }

        return $arr;
    }

    public function getResults()
    {
        return self::$storageArray;
    }
}