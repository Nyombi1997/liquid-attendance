<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Modules\Fleet\Services\Fleet;
use App\Modules\Fleet\Services\FleetStats;

/**
 * Accueil du Parc automobile (/flotte) : tableau de bord pour le gestionnaire,
 * la direction et l'administrateur (fleet_board) ; les autres comptes arrivent
 * sur leurs demandes, les chauffeurs sur leurs missions.
 */
final class DashboardController extends Controller
{
    public function index(): void
    {
        if (!Auth::can('fleet_board')) {
            $this->redirect(Auth::can('fleet_drive') ? '/flotte/missions' : '/flotte/demandes');
        }
        $alertDays = (int) Fleet::settings()['fleet_doc_alert_days'];
        $this->render('Fleet:dashboard', [
            'title'     => 'Parc automobile',
            'vehicles'  => FleetStats::vehicles(),
            'pending'   => FleetStats::pending(),
            'delays'    => FleetStats::delays(),
            'today'     => FleetStats::today(),
            'toAssign'  => FleetStats::toAssign(),
            'expiring'  => FleetStats::expiring($alertDays),
            'month'     => FleetStats::month(),
            'alertDays' => $alertDays,
        ]);
    }
}
