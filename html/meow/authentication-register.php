<?php
// User management now lives inside the dashboard shell at index.php?page=register
// (see mx_user_management.php), so this standalone page just redirects there.
header('location: index.php?page=register');
exit;
?>
