=== InnovGeek Backup Studio ===
Contributors: innovgeek
Tags: backup, full site backup, scheduled backup, restore, database
Requires at least: 5.9
Requires PHP: 7.4
Stable tag: 1.0
License: GPLv2 or later

Full-site WordPress backup/restore plugin, English & French. Extension de sauvegarde/restauration WordPress, français et anglais.

== Description ==
Full-site backup in the native .igbk format, scheduled daily/weekly full backups, resumable import, SHA-256 verification, fast deletion and a full-site restore of wp-content and WordPress-prefixed database tables. No plugin-imposed total archive size limit; hosting and PHP limits apply. WordPress core and wp-config.php are not automatically restored. Not compatible with .wpress.

Sauvegarde complète du site au format .igbk, programmation quotidienne ou hebdomadaire, importation avec reprise, vérification SHA-256, suppression rapide et restauration de wp-content et des tables SQL préfixées WordPress. Aucun plafond total imposé par le plugin ; les limites de l'hébergement et de PHP restent applicables. Le cœur WordPress et wp-config.php ne sont pas restaurés automatiquement. Format .wpress non compatible.

The interface language follows the administrator's WordPress profile language (English or French). La langue de l'interface suit la langue du profil utilisateur WordPress (anglais ou français).

== Installation ==
Upload the ZIP under Plugins > Add Plugin > Upload Plugin. Replace the existing plugin, without deleting private archives.
Téléverser l'archive ZIP sous Extensions > Ajouter une extension > Téléverser. Remplacer l'ancienne extension sans supprimer le stockage privé.

== Changelog ==
= 1.0 =
* First bilingual French/English edition.
* UI localization and version label only; backup, restore, import, deletion and scheduling engines remain unchanged.
