<?php
declare(strict_types=1);
/**
 * Bridge file:
 * Wenn nginx wegen /public/install/ Directory auf diese Datei geht,
 * routen wir sauber über den Frontcontroller (/public/index.php),
 * damit dein Installer-Routing greift.
 */

$_SERVER['REQUEST_URI'] = '/install';
require __DIR__ . '/../index.php';