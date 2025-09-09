<?php
/**
 * Plugin Name: WP-CLI Backup to FTP
 * Author: Julien Pipier
 * Description: Commande WP-CLI pour sauvegarder DB + fichiers, créer un ZIP unique horodaté et l'envoyer vers un FTP/FTPS.
 */

if (defined('WP_CLI') && WP_CLI) {
    class Backup_FTP_Command {
        public function __invoke($args, $assoc_args) {
            $host       = $assoc_args['ftp-host'] ?? null;
            $user       = $assoc_args['ftp-user'] ?? null;
            $pass       = $assoc_args['ftp-pass'] ?? null;
            $port       = (int)($assoc_args['ftp-port'] ?? 21);
            $remote_dir = rtrim($assoc_args['remote'] ?? '/backups', '/');
            $ftps       = isset($assoc_args['ftps']);
            $active     = isset($assoc_args['active']);
            $what       = $assoc_args['what'] ?? 'all';
            $keep       = (int)($assoc_args['keep'] ?? 0);
            $mailto     = $assoc_args['mailto'] ?? get_option('admin_email');

            if (!$host || !$user || !$pass) {
                \WP_CLI::error("Arguments requis: --ftp-host= --ftp-user= --ftp-pass=");
            }

            $ts         = date('Ymd-His');
            $backup_dir = WP_CONTENT_DIR . '/backups';
            if (!is_dir($backup_dir) && !wp_mkdir_p($backup_dir)) {
                \WP_CLI::error("Impossible de créer $backup_dir");
            }

            $site_slug  = preg_replace('~[^a-z0-9-_]~i','_', parse_url(home_url(), PHP_URL_HOST) ?: 'site');
            $db_file    = "$backup_dir/{$site_slug}-db-$ts.sql";
            $files_zip  = "$backup_dir/{$site_slug}-files-$ts.zip";
            $bundle_zip = "$backup_dir/{$site_slug}-backup-$ts.zip";

            // Export DB
            if ($what === 'all' || $what === 'db') {
                \WP_CLI::log("▶ Export DB → $db_file");
                $res = \WP_CLI::runcommand("db export " . escapeshellarg($db_file), ['return' => true, 'exit_error' => false]);
                if (!file_exists($db_file) || filesize($db_file) === 0) {
                    \WP_CLI::error("Export DB échoué (fichier introuvable ou vide). Sortie: $res");
                }
                \WP_CLI::success("DB export OK (" . size_format(filesize($db_file)) . ")");
            }

            // Archive fichiers (wp-content)
            if ($what === 'all' || $what === 'files') {
                \WP_CLI::log("▶ Archive fichiers (wp-content) → $files_zip");
                if (!$this->zip_wp_content($files_zip)) {
                    \WP_CLI::error("Création de l’archive fichiers échouée.");
                }
                \WP_CLI::success("Archive fichiers OK (" . size_format(filesize($files_zip)) . ")");
            }

            // Créer le ZIP final unique (contient ce qui a été demandé)
            \WP_CLI::log("▶ Création du ZIP final → $bundle_zip");
            $zip = new \ZipArchive();
            if ($zip->open($bundle_zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                \WP_CLI::error("Impossible de créer l'archive finale.");
            }
            if (($what === 'all' || $what === 'db') && file_exists($db_file)) {
                $zip->addFile($db_file, basename($db_file));
            }
            if (($what === 'all' || $what === 'files') && file_exists($files_zip)) {
                $zip->addFile($files_zip, basename($files_zip));
            }
            $zip->close();
            \WP_CLI::success("ZIP final OK (" . size_format(filesize($bundle_zip)) . ")");

            // S'assurer que le dossier distant existe
            $this->ftp_ensure_remote_dir($host, $port, $user, $pass, $remote_dir, $ftps, $active);

            // Upload UNIQUEMENT le ZIP final
            $remote_bundle = "$remote_dir/" . basename($bundle_zip);
            $this->ftp_upload_file($host, $port, $user, $pass, $remote_bundle, $bundle_zip, $ftps, $active);

            \WP_CLI::success("Upload terminé: " . basename($bundle_zip));

            // Rotation locale éventuelle (sur fichiers intermédiaires si tu décides de les garder un jour)
            if ($keep > 0) {
                \WP_CLI::log("▶ Rotation locale: garder $keep jeu(x)");
                $this->rotate_local($backup_dir, $site_slug, $keep);
            }

            \WP_CLI::success("Sauvegarde terminée ✅");

            // Nettoyage local : supprimer tout le dossier backups
            $this->rmdir_recursive($backup_dir);
            \WP_CLI::success("Dossier local $backup_dir supprimé ✅");

            // Rapport email
            $subject = "Rapport de sauvegarde WordPress";
            $body = "Bonjour,\n\nVoici le rapport de sauvegarde :\n\n"
                  . "- Site : " . home_url() . "\n"
                  . "- Date : " . date('Y-m-d H:i:s') . "\n"
                  . "- Archive envoyée : " . basename($bundle_zip) . " (" . size_format(filesize($bundle_zip)) . ")\n"
                  . "- Destination FTP : ftp://{$host}:{$port}{$remote_dir}\n\n"
                  . "Cordialement,\nVotre WordPress";
            $headers = ["Content-Type: text/plain; charset=UTF-8"];
            if (wp_mail($mailto, $subject, $body, $headers)) {
                \WP_CLI::success("Rapport envoyé à $mailto");
            } else {
                \WP_CLI::warning("Impossible d’envoyer le rapport à $mailto");
            }
        }

        // Zip wp-content
        private function zip_wp_content($zip_path) {
            $src = WP_CONTENT_DIR;
            $exclude = [
                'cache','advanced-cache.php','w3tc-config','ewww',
                'ai1wm-backups','backup','backups','updraft',
                'cache-page','acf-json','debug.log','.DS_Store','node_modules','vendor'
            ];
            $zip = new \ZipArchive();
            if ($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) return false;
            $srcLen = strlen($src) + 1;

            $rii = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($rii as $file) {
                $path = $file->getPathname();
                $rel  = substr($path, $srcLen);
                foreach ($exclude as $ex) { if (stripos($rel, $ex) !== false) continue 2; }
                if ($file->isDir()) $zip->addEmptyDir($rel);
                else $zip->addFile($path, $rel);
            }
            return $zip->close();
        }

        // Réglages cURL (FTP passif robuste par défaut)
        private function curl_common_setopts($ch, $host, $port, $user, $pass, $ftps, $active) {
            curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            curl_setopt($ch, CURLOPT_PORT, $port);

            // Favoriser PASSIF 
            if (defined('CURLOPT_FTP_SKIP_PASV_IP')) curl_setopt($ch, CURLOPT_FTP_SKIP_PASV_IP, true);
            if (defined('CURLOPT_FTP_USE_EPRT'))     curl_setopt($ch, CURLOPT_FTP_USE_EPRT, false);
            if (defined('CURLOPT_FTP_USE_EPSV'))     curl_setopt($ch, CURLOPT_FTP_USE_EPSV, false);

            if ($active) { curl_setopt($ch, CURLOPT_FTPPORT, '-'); }

            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);

            // FTPS explicite si demandé
            if ($ftps) {
                curl_setopt($ch, CURLOPT_FTP_SSL, CURLFTPSSL_ALL);
                curl_setopt($ch, CURLOPT_FTPSSLAUTH, CURLFTPAUTH_TLS);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }
        }

        private function ftp_upload_file($host, $port, $user, $pass, $remote_path, $local_path, $ftps, $active) {
            $url = "ftp://{$host}{$remote_path}";
            $fp  = fopen($local_path, 'rb');
            if (!$fp) \WP_CLI::error("Ouverture impossible: $local_path");

            $ch = curl_init($url);
            $this->curl_common_setopts($ch, $host, $port, $user, $pass, $ftps, $active);
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $fp);
            curl_setopt($ch, CURLOPT_INFILESIZE, filesize($local_path));
            curl_setopt($ch, CURLOPT_FTP_CREATE_MISSING_DIRS, 1);

            $out  = curl_exec($ch);
            $err  = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            fclose($fp);
            curl_close($ch);

            if ($out === false) {
                \WP_CLI::error("Upload échoué vers $remote_path : $err (code FTP: $code)");
            } else {
                \WP_CLI::log("✓ Upload OK → " . basename($remote_path) . " (" . size_format(filesize($local_path)) . ")");
            }
        }

        private function ftp_delete($host, $port, $user, $pass, $remote_path, $ftps, $active) {
            $url = "ftp://{$host}{$remote_path}";
            $ch  = curl_init($url);
            $this->curl_common_setopts($ch, $host, $port, $user, $pass, $ftps, $active);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELE');
            curl_exec($ch);
            curl_close($ch);
        }

        // Crée le dossier distant s'il n'existe pas
        private function ftp_ensure_remote_dir($host, $port, $user, $pass, $remote_dir, $ftps, $active) {
            $tmp = tmpfile(); $meta = stream_get_meta_data($tmp); $tmp_path = $meta['uri'];
            file_put_contents($tmp_path, "ping");
            $probe = rtrim($remote_dir, '/') . '/.__mkdir_probe__';
            $this->ftp_upload_file($host, $port, $user, $pass, $probe, $tmp_path, $ftps, $active);
            $this->ftp_delete($host, $port, $user, $pass, $probe, $ftps, $active);
            fclose($tmp);
        }

        private function rotate_local($backup_dir, $site_slug, $keep) {
            $files = glob("$backup_dir/{$site_slug}-db-*.sql");
            $files = array_merge($files ?: [], glob("$backup_dir/{$site_slug}-files-*.zip") ?: []);
            $files = array_merge($files ?: [], glob("$backup_dir/{$site_slug}-backup-*.zip") ?: []);
            if (!$files) return;
            usort($files, fn($a,$b) => filemtime($b) <=> filemtime($a));
            $to_keep = array_slice($files, 0, $keep * 3);
            foreach ($files as $f) { if (!in_array($f, $to_keep, true)) @unlink($f); }
        }
        // Suppression récursive d'un dossier
        private function rmdir_recursive($dir) {
            if (!is_dir($dir)) return;
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                if ($item->isDir()) @rmdir($item->getPathname());
                else @unlink($item->getPathname());
            }
            @rmdir($dir);
        }
    }

    \WP_CLI::add_command('backup:ftp', 'Backup_FTP_Command');
}