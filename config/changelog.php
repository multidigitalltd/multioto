<?php

/*
|--------------------------------------------------------------------------
| Application changelog
|--------------------------------------------------------------------------
|
| The "מה חדש" feed for the עדכונים screen. The release data lives in the
| sibling changelog.json (newest first) — a NON-executable format on purpose:
| the deploy watcher reads an INCOMING build's changelog before an admin
| approves the update, so it must never execute that file. Keep editing the
| data in changelog.json; this file only loads it.
|
| Each entry: version (semver string), date (Y-m-d), title, highlights (list).
|
*/

$releases = json_decode((string) @file_get_contents(__DIR__.'/changelog.json'), true);

return [
    'releases' => is_array($releases) ? $releases : [],
];
