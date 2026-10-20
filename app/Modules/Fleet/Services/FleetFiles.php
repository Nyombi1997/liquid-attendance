<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Services;

use App\Core\Config;

/**
 * Fichiers du parc automobile, hors du dossier public (<storage>/fleet/…) :
 *   ordres/     ordres de mission joints aux demandes
 *   documents/  assurance, contrôle technique… des véhicules
 *   missions/   photos du compteur (<id>-depart.jpg, <id>-retour.jpg)
 * Servis par les contrôleurs après contrôle des droits (send()).
 */
final class FleetFiles
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const PHOTO_MAX_BYTES = 2_000_000;

    /**
     * Fichier envoyé par un formulaire (PDF ou image, 5 Mo maximum).
     *
     * @return array{0:?array{stored_name:string, file_name:string, file_mime:string, file_size:int}, 1:?string} [fichier, erreur]
     *         [null, null] si aucun fichier n'a été choisi
     */
    public static function upload(string $field, string $dir): array
    {
        $f = $_FILES[$field] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }
        if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) $f['size'] > self::MAX_BYTES) {
            return [null, 'Fichier trop lourd (5 Mo maximum).'];
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return [null, 'Envoi du fichier interrompu, merci de recommencer.'];
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset(self::TYPES[$mime])) {
            return [null, 'Format non accepté : PDF, JPEG, PNG ou WebP uniquement.'];
        }
        $stored = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        $path = self::path($dir, $stored);
        self::ensureDir(dirname($path));
        if (!move_uploaded_file($f['tmp_name'], $path)) {
            return [null, 'Enregistrement du fichier impossible.'];
        }
        $name = preg_replace('/[^\pL\pN ._()-]+/u', '_', basename((string) $f['name'])) ?: 'fichier';
        return [[
            'stored_name' => $stored,
            'file_name'   => mb_substr($name, 0, 160),
            'file_mime'   => $mime,
            'file_size'   => (int) $f['size'],
        ], null];
    }

    /**
     * Photo du compteur envoyée par l'écran mission (data:image/jpeg;base64,…),
     * déjà réduite par le navigateur (app.js).
     *
     * @return array{0:?string, 1:?string} [contenu JPEG, erreur]
     */
    public static function decodePhoto(string $data): array
    {
        if ($data === '') {
            return [null, null];
        }
        if (!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $data, $m)) {
            return [null, 'Format de photo non reconnu.'];
        }
        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) > self::PHOTO_MAX_BYTES) {
            return [null, 'Photo trop lourde (2 Mo maximum).'];
        }
        $info = @getimagesizefromstring($bin);
        if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] > 4000 || $info[1] > 4000) {
            return [null, 'Photo invalide.'];
        }
        return [$bin, null];
    }

    public static function savePhoto(int $requestId, string $moment, string $jpeg): void
    {
        $path = self::photoPath($requestId, $moment);
        self::ensureDir(dirname($path));
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $jpeg) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Écriture de la photo impossible : $path");
        }
    }

    /** $moment : depart ou retour */
    public static function photoPath(int $requestId, string $moment): string
    {
        return self::path('missions', $requestId . '-' . ($moment === 'retour' ? 'retour' : 'depart') . '.jpg');
    }

    public static function path(string $dir, string $stored): string
    {
        return rtrim((string) Config::get('storage_path'), '/') . '/fleet/' . $dir . '/' . basename($stored);
    }

    public static function delete(string $dir, ?string $stored): void
    {
        if ($stored) {
            @unlink(self::path($dir, $stored));
        }
    }

    /**
     * Envoie un fichier au navigateur. Les images s'affichent ; les PDF sont
     * téléchargés (pas d'exécution d'un PDF dans la page).
     */
    public static function send(string $path, string $name, string $mime): void
    {
        session_write_close();
        if (!is_file($path)) {
            http_response_code(404);
            echo 'Fichier introuvable.';
            return;
        }
        $inline = str_starts_with($mime, 'image/');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name));
        header('Cache-Control: private, no-store');
        readfile($path);
    }

    /** « 1,2 Mo » */
    public static function sizeLabel(?int $bytes): string
    {
        if (!$bytes) {
            return '';
        }
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', ' ') . ' Mo' : max(1, (int) round($bytes / 1024)) . ' Ko';
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Dossier inaccessible : $dir");
        }
    }
}
