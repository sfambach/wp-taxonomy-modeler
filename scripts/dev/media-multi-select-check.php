<?php declare(strict_types=1);

/**
 * The media library opens with multiple selection at a multi-valued media field, and with single selection at a single one.
 *
 *     php scripts/dev/media-multi-select-check.php
 *
 * ⚠️ **[D-1077](../../docs/NewConcept/90-decision-log.md), his word:** *«mehfach auswahl bei bilder hinzufügen»*.
 * *Reads the script, needs no WordPress: the picker is decided in the browser.*
 */

$skript = (string) file_get_contents(__DIR__ . '/../../assets/admin.js');
$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$pruefe(preg_match("/multiple:\s*waehler\s*\?\s*'add'\s*:\s*false/", $skript) === 1, 'multiple selection only inside a value picker');
$pruefe(preg_match("/hasAttribute\(\s*'data-taxmod-address'\s*\)\s*\?\s*null\s*:\s*knopf\.closest\(\s*'\.taxmod-value-picker'\s*\)/", $skript) === 1, 'a single media field (named address) stays single');
$pruefe(preg_match("/get\(\s*'selection'\s*\)\.each\(/", $skript) === 1, 'every selected file is taken, not only the first');
$pruefe(preg_match("/wertZeileAnlegen\(\s*waehler,\s*'media:'\s*\+\s*gewaehlt\.id/", $skript) === 1, 'each file becomes its own row as media:<id> (D-865)');

exit($fehler === 0 ? 0 : 1);
