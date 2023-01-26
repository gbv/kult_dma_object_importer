<?php
  /*
    1. get all files from "indexed_denkxweb"-Folder and add them as *.purge - Files to the hotfolder
    --> that deletes all Files from solr and removes all images
    2. remove all files from "indexed_denkxweb"-Folder manually
  */
  $counter = 0;
  if ($handle = opendir($settings['deleter']['indexedDenkxwebFolder'])) {
      while (false !== ($filename = readdir($handle))) {
          if ($filename != "." && $filename != "..") {
              $deletionFilename = str_replace('.xml', '.purge', $filename);
              file_put_contents($settings['deleter']['hotfolder'] . $deletionFilename, 'delete');
              $counter++;
              if (file_exists($settings['deleter']['indexedDenkxwebFolder'] . $filename)) {
                //unlink($settings['deleter']['indexedDenkxwebFolder'] . $filename);
              }
          }
      }
      //$logger->info('Removed ' . $counter . ' Denkxweb-files from "' . $settings['deleter']['indexedDenkxwebFolder'] . '"');
      $logger->info('Added ' . $counter . ' deletion-files to the hotfolder. Now wait. ;-)');
      closedir($handle);
  }

 ?>
