# WP-CLI Backup to FTP

Plugin WordPress ajoutant une commande WP-CLI pour sauvegarder automatiquement votre site (base de données et/ou fichiers) vers un serveur FTP/FTPS.

## 🎯 Fonctionnalités

- Export de la base de données
- Sauvegarde des fichiers wp-content
- Création d'une archive ZIP unique horodatée
- Support FTP et FTPS 
- Mode de connexion actif/passif
- Rotation des sauvegardes (conservation d'un nombre limité)
- Envoi d'un rapport par email

## 📋 Prérequis

- WordPress 5.0+
- WP-CLI installé et configuré
- PHP 7.4+ avec extensions ZIP et cURL

## 🚀 Installation

1. Téléchargez le plugin
2. Créez le dossier wp-content/mu-plugins/ s'il n'existe pas
3. Installez-le dans wp-content/mu-plugins/
4. Activez-le depuis l'administration WordPress

## 📖 Utilisation

Commande de base :
```bash
wp backup:ftp --ftp-host=monserveur.com --ftp-user=login --ftp-pass=password
```

### Options disponibles

- `--ftp-host` : Hôte FTP (requis)
- `--ftp-user` : Nom d'utilisateur FTP (requis)
- `--ftp-pass` : Mot de passe FTP (requis)
- `--ftp-port` : Port FTP (défaut: 21)
- `--remote` : Dossier distant (défaut: /backups)
- `--what` : Type de sauvegarde : all|db|files (défaut: all)
- `--ftps` : Utiliser FTPS (optionnel)
- `--active` : Mode de connexion actif (défaut: passif)
- `--keep` : Nombre de sauvegardes à conserver (défaut: 0 = illimité)
- `--mailto` : Email pour le rapport (défaut: email admin)

### Exemples

Sauvegarde complète en FTPS :
```bash
wp backup:ftp --ftp-host=ftp.exemple.fr --ftp-user=user --ftp-pass=pass --ftps
```

Sauvegarde DB uniquement :
```bash
wp backup:ftp --ftp-host=ftp.exemple.fr --ftp-user=user --ftp-pass=pass --what=db
```

## 📝 Notes

- Les fichiers de sauvegarde sont nettoyés après l'envoi FTP
- Les dossiers cache, node_modules et autres fichiers temporaires sont exclus
- Le rapport email inclut les détails de la sauvegarde

## 🛟 Support

Pour toute question ou problème, merci d'ouvrir une issue sur GitHub.

## 📜 Licence

Ce plugin est sous licence GPLv2 ou supérieure.

