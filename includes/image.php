<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function ensure_upload_directories(): void
{
    $folders = ['staff', 'gallery', 'committee', 'winners', 'videos', 'lectures', 'monthly_reports', 'annual_events', 'reports'];
    foreach ($folders as $folder) {
        $path = UPLOAD_PATH . '/' . $folder;
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create upload directory: ' . $folder);
        }
    }
}

function upload_monthly_report_file(array $file, int $maxBytes = 52428800): ?array
{
    return upload_department_report_file($file, 'monthly_reports', $maxBytes);
}

function upload_annual_event_file(array $file, int $maxBytes = 52428800): ?array
{
    return upload_department_report_file($file, 'annual_events', $maxBytes);
}

function upload_department_report_file(array $file, string $targetFolder, int $maxBytes): ?array
{
    ensure_upload_directories();

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload failed. Please try again.');
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $fileSize = is_file($tmpPath) ? (int) filesize($tmpPath) : 0;
    if ($fileSize <= 0) {
        throw new RuntimeException('Uploaded file is empty.');
    }
    if ($fileSize > $maxBytes) {
        throw new RuntimeException('File is too large. Maximum size is 50MB.');
    }

    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Invalid uploaded file.');
    }

    $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'webp'];
    $originalName = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'report')));
    $originalExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($originalExtension, $allowedExtensions, true)) {
        throw new RuntimeException('Allowed file types are PDF, Word (DOC/DOCX), Excel (XLS/XLSX), PowerPoint (PPT/PPTX), or Images (JPG/PNG).');
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $originalExtension;
    if (!in_array($targetFolder, ['monthly_reports', 'annual_events'], true)) {
        throw new InvalidArgumentException('Invalid report upload folder.');
    }
    $destinationPath = UPLOAD_PATH . '/' . $targetFolder . '/' . $fileName;

    if (!move_uploaded_file($tmpPath, $destinationPath)) {
        throw new RuntimeException('Unable to save uploaded report file.');
    }

    return [
        'filename' => $fileName,
        'original_name' => $originalName,
    ];
}

function delete_monthly_report_file(?string $fileName): void
{
    delete_department_report_file($fileName, 'monthly_reports');
}

function delete_annual_event_file(?string $fileName): void
{
    delete_department_report_file($fileName, 'annual_events');
}

function delete_department_report_file(?string $fileName, string $targetFolder): void
{
    if (!$fileName || basename($fileName) !== $fileName || !in_array($targetFolder, ['monthly_reports', 'annual_events'], true)) {
        return;
    }

    $path = UPLOAD_PATH . '/' . $targetFolder . '/' . $fileName;
    if (is_file($path)) {
        @unlink($path);
    }
}

function upload_lecture_file(array $file, string $targetFolder = 'lectures', int $maxBytes = 52428800): ?string
{
    ensure_upload_directories();

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Lecture file upload failed. Please try again.');
    }

    $fileSize = (int) ($file['size'] ?? 0);
    if ($fileSize <= 0) {
        throw new RuntimeException('Uploaded lecture file is empty.');
    }
    if ($fileSize > $maxBytes) {
        throw new RuntimeException('Lecture file is too large. Maximum size is 50MB.');
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Invalid uploaded lecture file.');
    }

    $allowedExtensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx'];
    $originalName = strtolower((string) ($file['name'] ?? ''));
    $originalExtension = pathinfo($originalName, PATHINFO_EXTENSION);
    $originalExtension = is_string($originalExtension) ? strtolower($originalExtension) : '';
    if (!in_array($originalExtension, $allowedExtensions, true)) {
        throw new RuntimeException('Allowed lecture file types are PDF, DOC, DOCX, PPT, and PPTX.');
    }

    $targetFolder = trim($targetFolder, '/');
    $fileName = bin2hex(random_bytes(16)) . '.' . $originalExtension;
    $destinationPath = UPLOAD_PATH . '/' . $targetFolder . '/' . $fileName;

    if (!move_uploaded_file($tmpPath, $destinationPath)) {
        throw new RuntimeException('Unable to save uploaded lecture file.');
    }

    return $fileName;
}

function upload_and_resize_image(array $file, string $targetFolder, int $maxWidth = 900, int $maxHeight = 900): ?string
{
    ensure_upload_directories();

    $allowedFolders = ['staff', 'gallery', 'committee', 'winners'];
    if (!in_array($targetFolder, $allowedFolders, true)) {
        throw new InvalidArgumentException('Invalid image upload destination.');
    }
    if ($maxWidth < 1 || $maxHeight < 1) {
        throw new InvalidArgumentException('Image resize dimensions must be positive.');
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed. Please try again.');
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $fileSize = is_file($tmpPath) ? (int) filesize($tmpPath) : 0;
    if ($fileSize <= 0 || $fileSize > 20971520) {
        throw new RuntimeException('Image file must be between 1 byte and 20MB.');
    }
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Invalid uploaded image.');
    }

    $imageInfo = @getimagesize($tmpPath);
    if (!$imageInfo) {
        throw new RuntimeException('Only valid image files are allowed.');
    }

    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 10000 || $height > 10000 || ($width * $height) > 16000000) {
        throw new RuntimeException('Image dimensions are too large. The maximum image area is 16 megapixels.');
    }

    $mime = $imageInfo['mime'] ?? '';
    $supportedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($supportedMimes[$mime])) {
        throw new RuntimeException('Allowed image types are JPG, PNG, and WEBP.');
    }

    $extension = $supportedMimes[$mime];
    $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
    $destinationPath = UPLOAD_PATH . '/' . $targetFolder . '/' . $fileName;

    $resizeHandled = false;
    if (function_exists('imagecreatetruecolor')) {
        $resizeHandled = resize_and_save_image($tmpPath, $mime, $destinationPath, $maxWidth, $maxHeight);
    }

    if (!$resizeHandled) {
        if (is_file($destinationPath)) {
            @unlink($destinationPath);
        }
        if (!move_uploaded_file($tmpPath, $destinationPath)) {
            throw new RuntimeException('Unable to save uploaded image.');
        }
    }

    return $fileName;
}

function resize_and_save_image(string $sourcePath, string $mime, string $destinationPath, int $maxWidth, int $maxHeight): bool
{
    $size = @getimagesize($sourcePath);
    if (!$size) {
        return false;
    }
    [$width, $height] = $size;

    $ratio = min($maxWidth / $width, $maxHeight / $height, 1);
    $newWidth = (int) round($width * $ratio);
    $newHeight = (int) round($height * $ratio);

    $sourceImage = null;
    if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        $sourceImage = imagecreatefromjpeg($sourcePath);
    } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
        $sourceImage = imagecreatefrompng($sourcePath);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $sourceImage = imagecreatefromwebp($sourcePath);
    }

    if (!$sourceImage) {
        return false;
    }

    $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($resizedImage, false);
        imagesavealpha($resizedImage, true);
        $transparent = imagecolorallocatealpha($resizedImage, 0, 0, 0, 127);
        imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
    }

    imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    $saved = false;
    if ($mime === 'image/jpeg' && function_exists('imagejpeg')) {
        $saved = imagejpeg($resizedImage, $destinationPath, 85);
    } elseif ($mime === 'image/png' && function_exists('imagepng')) {
        $saved = imagepng($resizedImage, $destinationPath, 7);
    } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
        $saved = imagewebp($resizedImage, $destinationPath, 85);
    }

    imagedestroy($sourceImage);
    imagedestroy($resizedImage);

    return $saved;
}

function delete_image_file(string $folder, ?string $fileName): void
{
    $allowedFolders = ['staff', 'gallery', 'committee', 'winners', 'videos'];
    if (!$fileName || !in_array($folder, $allowedFolders, true) || basename($fileName) !== $fileName) {
        return;
    }

    $path = UPLOAD_PATH . '/' . $folder . '/' . $fileName;
    if (is_file($path)) {
        unlink($path);
    }
}

function upload_video_clip(array $file, string $targetFolder = 'videos', int $maxBytes = 26214400): ?string
{
    ensure_upload_directories();

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Video upload failed. Please try again.');
    }

    $fileSize = (int) ($file['size'] ?? 0);
    if ($fileSize <= 0) {
        throw new RuntimeException('Uploaded video file is empty.');
    }

    if ($fileSize > $maxBytes) {
        $maxMb = (int) round($maxBytes / (1024 * 1024));
        throw new RuntimeException('Video is too large. Maximum size is ' . $maxMb . 'MB.');
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Invalid uploaded video.');
    }

    $allowedMimes = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/ogg' => 'ogv',
        'video/quicktime' => 'mov',
    ];
    $allowedExtensions = ['mp4', 'webm', 'ogv', 'mov'];

    $mime = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $tmpPath) ?: null;
            finfo_close($finfo);
        }
    }

    $originalName = strtolower((string) ($file['name'] ?? ''));
    $originalExtension = pathinfo($originalName, PATHINFO_EXTENSION);
    $originalExtension = is_string($originalExtension) ? strtolower($originalExtension) : '';

    $extension = null;
    if ($mime && isset($allowedMimes[$mime])) {
        $extension = $allowedMimes[$mime];
    } elseif (in_array($originalExtension, $allowedExtensions, true)) {
        $extension = $originalExtension;
    }

    if ($extension === null) {
        throw new RuntimeException('Allowed video types are MP4, WEBM, OGV, and MOV.');
    }

    $targetFolder = trim($targetFolder, '/');
    $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
    $destinationPath = UPLOAD_PATH . '/' . $targetFolder . '/' . $fileName;

    if (!move_uploaded_file($tmpPath, $destinationPath)) {
        throw new RuntimeException('Unable to save uploaded video.');
    }

    return $fileName;
}
