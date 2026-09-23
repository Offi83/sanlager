<?php

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * Gleiche Zeitzone wie in public/index.php, damit "heute" in den Tests
 * genauso berechnet wird wie in der Anwendung.
 */
date_default_timezone_set('Europe/Berlin');
