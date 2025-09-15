<?php
/* ===== Jogosultság ===== */
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'felmero') {
    header("Location: ../index.php");  exit();
}

require_once '../includes/db.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;
use PhpOffice\PhpWord\Settings;

/* ===== Projekt lekérés ===== */
$projectID = (int)($_GET['id'] ?? 0);
$userID    = $_SESSION['user']['id'];

$stmt = $conn->prepare(
  "SELECT * FROM projects WHERE id = ? AND created_by = ?");
$stmt->bind_param("ii", $projectID, $userID);
$stmt->execute();
$project = $stmt->get_result()->fetch_assoc();
if (!$project) exit('Projekt nem található vagy nincs jogosultság.');

/* ===== Tulajdonosok ===== */
$owners = $conn->query(
  "SELECT owner_name, id_card, signature
     FROM project_owners
    WHERE project_id = $projectID
    ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

/* ===== Melyik sablon? ===== */
$templateFile = ($project['template_type']=='Homlokzat szigetelés')
    ? '../templates/Megallapodas.docx'
    : '../templates/Árajánlat.docx';

/* ===== TemplateProcessor ===== */
$tpl = new TemplateProcessor($templateFile);

/* --- mezők kötőjellel, ha üres --- */
$tpl->setValue('ugyfel_neve',  $project['client_name']   ?: '-');
$tpl->setValue('ugyfel_cime',  $project['address']       ?: '-');
$tpl->setValue('projekt_nev',  $project['project_name']  ?: '-');
$tpl->setValue('munka_targya', $project['munka_targya']  ?: '-');
$tpl->setValue('hatarido',     $project['hatarido']      ?: '-');
$tpl->setValue('datum',        date('Y.m.d', strtotime($project['created_at'])));

/* --- pipák --- */
$allFlags = [
  'flag_villany','flag_burkolas','flag_futes','flag_festes',
  'flag_anyag_biztositas','flag_hatarido_elfogadva'
];
$flags = $project['flags'] ? json_decode($project['flags'], true) : [];
foreach ($allFlags as $fl) {
    $tpl->setValue($fl, !empty($flags[$fl]) ? '☒' : '☐');
}

/* --- tulajdonos blokk klónozása --- */
$tpl->cloneBlock('owner_block', count($owners), true, true);
foreach ($owners as $i=>$o) {
    $n = $i+1;
    $tpl->setValue("tulaj_nev#$n",  $o['owner_name'] ?: '-');
    $tpl->setValue("tulaj_szig#$n", $o['id_card']    ?: '-');

    /* aláírás PNG ideiglenes fájlba */
    $tmpSig = sys_get_temp_dir()."/sig_{$projectID}_$n.png";
    file_put_contents($tmpSig, $o['signature']);
    $tpl->setImageValue("tulaj_sig#$n", [
        'path'=>$tmpSig,'width'=>120,'height'=>60,'ratio'=>true
    ]);
}

/* ===== DOCX → PDF (Dompdf) ===== */
Settings::setDefaultFontName('DejaVu Sans');
Settings::setPdfRendererName(Settings::PDF_RENDERER_DOMPDF);
Settings::setPdfRendererPath('../vendor/dompdf/dompdf');

$tmpDocx = sys_get_temp_dir()."/proj{$projectID}.docx";
$tpl->saveAs($tmpDocx);

$phpWord = \PhpOffice\PhpWord\IOFactory::load($tmpDocx);
$pdfPath = "../uploads/pdfs/{$projectID}.pdf";
if (!is_dir(dirname($pdfPath))) mkdir(dirname($pdfPath),0775,true);

\PhpOffice\PhpWord\IOFactory::createWriter($phpWord,'PDF')->save($pdfPath);
// unlink($tmpDocx);          // ha nem kell DOCX, vedd ki a kommentet

/* ===== Letöltés ===== */
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="projekt_'.$projectID.'.pdf"');
readfile($pdfPath);
exit;
