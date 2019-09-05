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
        if(empty($this->storageArray))
            return true;
        else
            return false;
    }

    public function setResults($arr)
    {
        $result = true;
        if(!empty($this->storageArray))
            $result = $this->deleteResults();
        $this->storageArray = $arr;
        return $arr;
    }

    public function getResults()
    {
        return $this->storageArray;
    }
}