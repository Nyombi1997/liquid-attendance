<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Modules\Attendance\Services\DashboardStats;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $today = date('Y-m-d');
        $scope = Auth::departmentScope();
        $this->render('Attendance:dashboard', [
            'title'   => 'Tableau de bord',
            'today'   => $today,
            'scoped'  => $scope !== null,
            'kpis'    => DashboardStats::kpis($today, $scope),
            'punches' => DashboardStats::latestPunches(8, $scope),
            'lates'   => DashboardStats::lates($today, $scope),
            // Pointeuses : hors du périmètre d'un manager
            'devices' => $scope === null ? DashboardStats::devices() : [],
            'live'    => DashboardStats::liveState($today),
        ]);
    }
}
