<?php
declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$baseUrl = defined('BASE_URL') ? BASE_URL : '';
$title = isset($title) && is_string($title) && $title !== '' ? $title : 'Định tuyến SPX';
$csrfToken = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo $escape($csrfToken); ?>">
    <title><?php echo $escape($title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="<?php echo $escape($baseUrl); ?>/assets/css/app.css">
</head>
<body>
