<?php
  function exception_routine($exception) {
    echo PHP_EOL . '----------------------!!!----------------------' . PHP_EOL;
    echo "Error/Exception::::>" . PHP_EOL;
    echo $exception->getMessage();
    exit;
  }
?>
