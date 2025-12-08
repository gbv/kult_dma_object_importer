<?php

class FileTokenStorage
{
    private $file;

    public function __construct($file)
    {
        $this->file = $file;
    }

    public function load()
    {
        if (!file_exists($this->file)) return null;
        return json_decode(file_get_contents($this->file), true);
    }

    public function save($data)
    {
        file_put_contents($this->file, json_encode($data));
    }
}

?>
