<?php
// $data_all = file_get_contents('../data/mcf_default.json');
$data_all = file_get_contents('./data/mcf_default.json');
$data_all = $data_all != "" ? json_decode($data_all,true) : array();
$deviceSN_f = isset($data_all['devcieSN']) ? $data_all['devcieSN']: "";


$files = ['./config/dvl.json', './config/rgl.json', './config/cml.json','./data/mcf_default.json','./data/mcf_network.json']; // files to read
$fileContents = [];

// Step 1: Read files into an array
foreach ($files as $file) {
    if (file_exists($file)) {
        // echo $file."\r\n";
        $fileContents[$file] = file_get_contents($file); // store content keyed by filename
    } else {
        echo "File $file does not exist.<br>";
    }
}

// Step 2: Create a zip file
$zip = new ZipArchive();
// $tmpFile = tempnam(sys_get_temp_dir(), 'zip'); // temporary file
$zipFilename = $deviceSN_f.'_config.zip';

if ($zip->open($zipFilename, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    foreach ($fileContents as $filename => $content) {
        // echo basename($filename);
        // $zip->addFromString($filename, $content); // add file content to zip
        // $zip->addFromString(basename($filename), $content); // add file content to zip
        $zip->addFromString(str_replace("./config/","",$filename), $content); // add file content to zip
    }
    $zip->close();
    
     // Send headers to force download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.$zipFilename.'"');
    header('Content-Length: ' . filesize($tmpFile));

    // Output the zip file to the client
    readfile($zipFilename);

    // Remove temporary file
    unlink($zipFilename);
    // exit;
    echo "Zip file '$zipFilename' created successfully!";
} else {
    echo "Failed to create zip file.";
}
?>
