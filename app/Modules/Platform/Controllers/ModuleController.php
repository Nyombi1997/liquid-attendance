<?php
declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Controller;
use App\Modules\Platform\Services\Apps;

/** Accueil des applications (grille à la manière d'Odoo) et modules prévus mais pas encore développés. */
final class ModuleController extends Controller
{
    public function apps(): void
    {
        $this->render('Platform:apps', ['title' => 'Applications', 'apps' => Apps::all()]);
    }

    public function leave(): void
    {
        $this->render('Platform:soon', [
            'title' => 'Congés',
            'text'  => 'Demandes et suivi des congés, reliés aux horaires et à la présence. Module prévu en priorité 3.',
        ]);
    }
}
