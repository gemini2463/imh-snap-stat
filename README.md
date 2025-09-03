# SnapStat (imh-snap-stat), v0.1.8

sys-snap and sysstat Web Interface for cPanel/WHM and CWP

- cPanel/WHM path: `/usr/local/cpanel/whostmgr/docroot/cgi/imh-snap-stat/index.php`
- CWP path: `/usr/local/cwpsrv/htdocs/resources/admin/modules/imh-snap-stat.php`

# Installation

- Run as the Root user: `curl -fsSL https://raw.githubusercontent.com/gemini2463/imh-snap-stat/master/install.sh | sh`

# Files

## Shell installer

- install.sh

## Main script

- index.php - Identical to `imh-snap-stat.php`.
- index.php.sha256 - `sha256sum index.php > index.php.sha256`
- imh-snap-stat.php - Identical to `index.php`.
- imh-snap-stat.php.sha256 - `sha256sum imh-snap-stat.php > imh-snap-stat.php.sha256`

## Javascript

- imh-snap-stat.js - Bundle React or any other javascript in this file.
- imh-snap-stat.js.sha256 - `sha256sum imh-snap-stat.js > imh-snap-stat.js.sha256`

## Icon

- imh-snap-stat.png - [48x48 png image](https://api.docs.cpanel.net/guides/guide-to-whm-plugins/guide-to-whm-plugins-plugin-files/#icons)
- imh-snap-stat.png.sha256 - `sha256sum imh-snap-stat.png > imh-snap-stat.png.sha256`

## cPanel conf

- imh-snap-stat.conf - [AppConfig Configuration File](https://api.docs.cpanel.net/guides/guide-to-whm-plugins/guide-to-whm-plugins-appconfig-configuration-file)
- imh-snap-stat.conf.sha256 - `sha256sum imh-snap-stat.conf > imh-snap-stat.conf.sha256`

## CWP include

- imh-plugins.php - [CWP include](https://wiki.centos-webpanel.com/how-to-build-a-cwp-module)
- imh-plugins.php.sha256 - `sha256sum imh-plugins.php > imh-plugins.php.sha256`

## sha256 one-liner

- `for file in index.php imh-plugins.php imh-snap-stat.conf imh-snap-stat.js imh-snap-stat.php imh-snap-stat.png; do sha256sum "$file" > "$file.sha256"; done`
