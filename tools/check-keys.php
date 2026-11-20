<?php

/**
 * Vérifie que chaque clé de traduction employée dans le code existe dans les
 * seize dictionnaires. Une clé absente ne casse pas la page — elle retombe sur
 * le français, ou s'affiche telle quelle — et c'est précisément pour cela
 * qu'elle passerait inaperçue sans ce contrôle.
 *
 *     php tools/check-keys.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\I18n;

$used = [];
$directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_DIR));
foreach ($directory as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/locales/')) {
        continue;
    }
    $code = (string) file_get_contents($file->getPathname());
    if (preg_match_all("/\bt\(\s*'([^']+)'/", $code, $matches)) {
        foreach ($matches[1] as $key) {
            $used[$key][] = str_replace(APP_DIR . '/', '', $file->getPathname());
        }
    }
}
ksort($used);

$reference = I18n::dictionary('fr');
$missing = array_diff(array_keys($used), array_keys($reference));

echo count($used), " clés employées, ", count($reference), " clés au dictionnaire.\n";
if ($missing === []) {
    echo "Aucune clé manquante.\n";
    exit(0);
}
echo count($missing), " clé(s) absente(s) du français :\n";
foreach ($missing as $key) {
    echo "  $key  (" . implode(', ', array_unique($used[$key])) . ")\n";
}
exit(1);
