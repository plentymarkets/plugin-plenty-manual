<?php

namespace PlentyManual\Helpers;

class ResultsStorage
{

    private $storageArray = array();

    public function __construct(array $storageArray = [])
    {
        $this->storageArray = $storageArray;
    }

    public function deleteResults()
    {
        foreach ($this->storageArray as $key => $value)
            unset($this->storageArray[$key]);
        return true;
    }

    public function setResults($arr)
    {
        $deleteFlag = false;
        if(!empty($this->storageArray))
            $deleteFlag = $this->deleteResults();
        $this->storageArray = $arr;

        return $arr;
    }

    public function getResults()
    {
        return $this->storageArray;
    }
}