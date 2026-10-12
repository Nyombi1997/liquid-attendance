<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Photo d'un agent (storage/photos/<id>.jpg, hors du dossier public).
 * Partagée par la page Agents (AgentController) et la fiche employé
 * (EmployeeController) : même recadrage dans le navigateur (createCropper
 * dans app.js), mêmes contrôles ici.
 */
final class AgentPhoto
{
    private const MAX_BYTES = 1_500_000;
    private const SIZE = [128, 1024]; // côté de l'image recadrée, en pixels

    /**
     * Applique les champs photo_data (nouvelle photo) et photo_remove d'un formulaire.
     *
     * @param array $emp ligne employees (id, photo_updated_at)
     * @return array{0:?string, 1:?string} [changement : 'nouvelle' | 'retirée' | null, erreur]
     */
    public static function fromForm(array $emp): array
    {
        $data = (string) ($_POST['photo_data'] ?? '');
        if ($data !== '') {
            [$jpeg, $error] = self::decode($data);
            if ($error !== null) {
                return [null, $error];
            }
            self::save((int) $emp['id'], $jpeg);
            return ['nouvelle', null];
        }
        if (isset($_POST['photo_remove']) && $emp['photo_updated_at']) {
            self::remove((int) $emp['id']);
            return ['retirée', null];
        }
        return [null, null];
    }

    /**
     * Contrôle sans enregistrer : à appeler avant toute écriture en base.
     */
    public static function check(): ?string
    {
        $data = (string) ($_POST['photo_data'] ?? '');
        return $data === '' ? null : self::decode($data)[1];
    }

    /**
     * Photo envoyée par le recadrage du navigateur (data:image/jpeg;base64,…).
     * Le navigateur a déjà redessiné l'image (métadonnées EXIF supprimées) ;
     * on vérifie ici que c'est bien un JPEG carré de taille raisonnable.
     *
     * @return array{0:?string, 1:?string} [contenu JPEG, erreur]
     */
    public static function decode(string $data): array
    {
        if (!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $data, $m)) {
            return [null, 'Format de photo non reconnu.'];
        }
        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) > self::MAX_BYTES) {
            return [null, 'Photo trop lourde (1,5 Mo maximum).'];
        }
        $info = @getimagesizefromstring($bin);
        [$min, $max] = self::SIZE;
        if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] !== $info[1] || $info[0] < $min || $info[0] > $max) {
            return [null, 'Photo invalide.'];
        }
        return [$bin, null];
    }

    public static function save(int $id, string $jpeg): void
    {
        $file = self::path($id);
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Dossier des photos inaccessible : $dir");
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $jpeg) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Écriture de la photo impossible : $file");
        }
        Database::run('UPDATE employees SET photo_updated_at = NOW() WHERE id = ?', [$id]);
    }

    public static function remove(int $id): void
    {
        @unlink(self::path($id));
        Database::run('UPDATE employees SET photo_updated_at = NULL WHERE id = ?', [$id]);
    }

    public static function path(int $id): string
    {
        return rtrim((string) Config::get('storage_path'), '/') . '/photos/' . $id . '.jpg';
    }
}
