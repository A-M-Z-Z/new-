<?php
session_start();

// Verify user is logged in
if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Session expired, please login again.']);
    exit();
}

// Database Connection
$host = 'localhost';
$user = 'root';
$pass = 'root';
$dbname = 'cloudbox';
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]);
    exit();
}

$userid = $_SESSION['user_id'];
$fileId = intval($_POST['file_id']);
$folderId = isset($_POST['folder_id']) && $_POST['folder_id'] !== 'root' ? intval($_POST['folder_id']) : null;

// Check if file belongs to user
$checkFile = $conn->prepare("SELECT id, folder_id FROM files WHERE id = ? AND user_id = ?");
$checkFile->bind_param("ii", $fileId, $userid);
$checkFile->execute();
$result = $checkFile->get_result();

if ($result->num_rows === 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'File not found or you don\'t have permission.']);
    exit();
}

$fileData = $result->fetch_assoc();
$currentFolderId = $fileData['folder_id'];

// Don't move if already in the target location
if (($currentFolderId === $folderId) || 
    ($currentFolderId === null && $folderId === null)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'File is already in this location.']);
    exit();
}

// Check if target folder belongs to user (if not root)
if ($folderId !== null) {
    $checkFolder = $conn->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ?");
    $checkFolder->bind_param("ii", $folderId, $userid);
    $checkFolder->execute();
    $folderResult = $checkFolder->get_result();
    
    if ($folderResult->num_rows === 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Target folder not found or you don\'t have permission.']);
        exit();
    }
}

// Check for file name conflicts in target location
$fileNameQuery = $conn->prepare("SELECT filename FROM files WHERE id = ?");
$fileNameQuery->bind_param("i", $fileId);
$fileNameQuery->execute();
$fileNameResult = $fileNameQuery->get_result();
$fileNameData = $fileNameResult->fetch_assoc();
$fileName = $fileNameData['filename'];

// Check if file with same name exists in target folder
$conflictQuery = $conn->prepare("SELECT id FROM files WHERE filename = ? AND user_id = ? AND folder_id " . 
                             ($folderId === null ? "IS NULL" : "= ?") . " AND id != ?");

if ($folderId === null) {
    $conflictQuery->bind_param("sii", $fileName, $userid, $fileId);
} else {
    $conflictQuery->bind_param("siii", $fileName, $userid, $folderId, $fileId);
}

$conflictQuery->execute();
$conflictResult = $conflictQuery->get_result();

if ($conflictResult->num_rows > 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'A file with the same name already exists in the target folder.']);
    exit();
}

// Move the file
$updateQuery = $conn->prepare("UPDATE files SET folder_id = " . 
                            ($folderId === null ? "NULL" : "?") . 
                            " WHERE id = ? AND user_id = ?");

if ($folderId === null) {
    $updateQuery->bind_param("ii", $fileId, $userid);
} else {
    $updateQuery->bind_param("iii", $folderId, $fileId, $userid);
}

if ($updateQuery->execute()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'File moved successfully.']);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error moving file: ' . $conn->error]);
}

// Close the connection
$conn->close();
?>
