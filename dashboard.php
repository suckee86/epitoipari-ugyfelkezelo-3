<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

$role = $_SESSION['user']['role'];

switch ($role) {
    case 'admin':
        header("Location: admin/dashboard.php");
        break;
    case 'felmero':
        header("Location: felmero/project_list.php");
        break;
    case 'kivitelezo':
        header("Location: kivitelezo/dashboard_kivitelezo.php");
        break;
    case 'ellenorzo':
        header("Location: ellenorzo/dashboard_ellenorzo.php");
        break;
    case 'auditor':
        header("Location: auditor/dashboard_auditor.php");
        break;
    default:
        echo "Ismeretlen szerepkör.";
        break;
}
exit();
