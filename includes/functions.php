<?php
require_once 'db.php';

function getUserSignaturePath($user_id) {
    $path = "../signatures/signature_user_" . $user_id . ".png";
    return file_exists($path) ? $path : null;
}

function getProjectData($project_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function fillTemplate($template, $data, $signaturePath = null, $checkboxes = []) {
    $temp_file = tempnam(sys_get_temp_dir(), 'word');

    copy($template, $temp_file);

    $zip = new ZipArchive;
    if ($zip->open($temp_file) === true) {
        $content = $zip->getFromName('word/document.xml');

        foreach ($data as $key => $value) {
            $content = str_replace("{{" . $key . "}}", htmlspecialchars($value), $content);
        }

        foreach ($checkboxes as $key => $checked) {
            $symbol = $checked ? '☑' : '☐';
            $content = str_replace("[[" . $key . "]]", $symbol, $content);
        }

        $zip->addFromString('word/document.xml', $content);
        $zip->close();
    }

    return $temp_file;
}
