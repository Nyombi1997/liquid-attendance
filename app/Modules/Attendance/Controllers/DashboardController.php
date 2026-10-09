<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Core\Controller;
use App\Modules\Attendance\Services\DashboardStats;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $today = date('Y-m-d');
        $this->render('Attendance:dashboard', [
            'title'   => 'Tableau de bord',
            'today'   => $today,
            'kpis'    => DashboardStats::kpis($today),
            'trend'   => DashboardStats::trend($today),
            'punches' => DashboardStats::latestPunches(),
            'lates'   => DashboardStats::lates($today),
            'devices' => DashboardStats::devices(),
            'live'    => DashboardStats::liveState($today),
        ]);
    }
}
