<?php

/*
 * Daten für die Seite: Kategorien (Bearbeiten).
 * Wird von public/index.php eingebunden, bevor HTML ausgegeben wird
 * (Weiterleitungen sind hier also noch möglich). Alle hier gesetzten
 * Variablen stehen der Vorlage templates/pages/ zur Verfügung.
 */

$categoryList = $categories->all();

$editCategory = null;

if (isset($_GET['edit'])) {

    $editCategoryId = (int) $_GET['edit'];

    if ($editCategoryId > 0) {
        $editCategory = $categories->find($editCategoryId);
    }
}
