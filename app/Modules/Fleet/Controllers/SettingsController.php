<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Fleet\Services\Fleet;
use App\Modules\Fleet\Services\FleetWorkflow;

/**
 * Paramètres du circuit des demandes (administrateur) : seuils de la
 * validation de la direction, ordre de mission, rappel avant le départ,
 * alerte avant expiration. Enregistrés dans la table settings (fleet_*).
 */
final class SettingsController extends Controller
{
    public function index(): void
    {
        $this->render('Fleet:settings', ['title' => 'Paramètres du parc automobile', 's' => Fleet::settings()]);
    }

    public function update(): void
    {
        $values = [
            'fleet_city'                  => mb_substr(trim((string) $this->input('fleet_city', '')), 0, 60),
            'fleet_direction_out_of_town' => isset($_POST['fleet_direction_out_of_town']) ? '1' : '0',
            'fleet_direction_max_days'    => (string) (int) $this->input('fleet_direction_max_days', 2),
            'fleet_direction_weekend'     => isset($_POST['fleet_direction_weekend']) ? '1' : '0',
            'fleet_order_required'        => (string) $this->input('fleet_order_required', 'hors_ville'),
            'fleet_reminder_minutes'      => (string) (int) $this->input('fleet_reminder_minutes', 60),
            'fleet_doc_alert_days'        => (string) (int) $this->input('fleet_doc_alert_days', 30),
        ];
        $error = match (true) {
            $values['fleet_city'] === '' => 'Indiquez la ville de référence (missions hors ville).',
            (int) $values['fleet_direction_max_days'] < 0 || (int) $values['fleet_direction_max_days'] > FleetWorkflow::MAX_DAYS => 'Durée maximale invalide (0 à ' . FleetWorkflow::MAX_DAYS . ' jours).',
            !in_array($values['fleet_order_required'], ['toujours', 'hors_ville', 'jamais'], true) => 'Choix de l\'ordre de mission invalide.',
            (int) $values['fleet_reminder_minutes'] < 0 || (int) $values['fleet_reminder_minutes'] > 1440 => 'Rappel invalide (0 à 1440 minutes).',
            (int) $values['fleet_doc_alert_days'] < 1 || (int) $values['fleet_doc_alert_days'] > 365 => 'Alerte d\'expiration invalide (1 à 365 jours).',
            default => null,
        };
        if ($error) {
            flash('error', $error);
            $this->redirect('/flotte/parametres');
        }
        $old = Fleet::settings();
        foreach ($values as $name => $value) {
            Database::run('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
        }
        Audit::log('flotte_parametres', array_filter($values, static fn($v, $k) => ($old[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        flash('success', 'Paramètres enregistrés. Ils s\'appliquent aux nouvelles demandes.');
        $this->redirect('/flotte/parametres');
    }
}
