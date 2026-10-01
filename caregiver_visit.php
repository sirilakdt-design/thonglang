<?php
require_once __DIR__ . '/connect.php';
requireRole('caregiver');
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
$location = appUrl('caregiver/caregiver_visit.php') . ($query !== '' ? '?' . $query : '');
header('Location: ' . $location, true, 302);
exit;
