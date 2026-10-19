<?php
header('Content-Type: application/json');

$targetDir = "../";

// Ensure folder exists and writable
if (!is_dir($targetDir)) {
    if (!mkdir($targetDir, 0777, true)) {
        echo json_encode(["success" => false, "message" => "Failed to create uploads directory."]);
        exit;
    }
}
if (!is_writable($targetDir)) {
    chmod($targetDir, 0777);
}

if (isset($_FILES['files'])) {
    $responses = [];

    foreach ($_FILES['files']['name'] as $index => $name) {
        $fileName = basename($name);
        $targetFile = $targetDir . $fileName;

        $tmpFile = $_FILES['files']['tmp_name'][$index];

        // Validate JSON
        $uploadedContent = file_get_contents($tmpFile);
        $uploadedData = json_decode($uploadedContent, true);

        if ($uploadedData === null) {
            $responses[] = ["file" => $fileName, "success" => false, "message" => "Invalid JSON."];
            continue;
        }

        if (file_exists($targetFile)) {
            // Append to existing
            $existingContent = file_get_contents($targetFile);
            $existingData = json_decode($existingContent, true);
            if ($existingData === null) $existingData = [];

            if (is_array($existingData) && is_array($uploadedData)) {
                $mergedData = array_merge($existingData, $uploadedData);
            } else {
                $mergedData = $uploadedData;
            }

            if (file_put_contents($targetFile, json_encode($mergedData, JSON_PRETTY_PRINT))) {
                chmod($targetFile, 0777);
                $responses[] = ["file" => $fileName, "success" => true, "message" => "Appended to existing file."];
            } else {
                $responses[] = ["file" => $fileName, "success" => false, "message" => "Failed to save merged file."];
            }
        } else {
            // Save new file
            if (move_uploaded_file($tmpFile, $targetFile)) {
                chmod($targetFile, 0777);
                $responses[] = ["file" => $fileName, "success" => true, "message" => "Uploaded new file."];
            } else {
                $responses[] = ["file" => $fileName, "success" => false, "message" => "Failed to upload file."];
            }
        }
    }

    echo json_encode(["success" => true, "results" => $responses]);
} else {
    echo json_encode(["success" => false, "message" => "No files uploaded."]);
}
