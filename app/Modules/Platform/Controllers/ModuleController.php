<?php
declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Controller;

/** Modules prévus mais pas encore développés. */
final class ModuleController extends Controller
{
    public function fleet(): void
    {
        $this->render('Platform:soon', [
            'title' => 'Parc automobile',
            'text'  => 'Gestion du parc de véhicules et de leurs attributions aux employés. Module prévu en priorité 2.',
        ]);
    }

    public function leave(): void
    {
        $this->render('Platform:soon', [
            'title' => 'Congés',
            'text'  => 'Demandes et suivi des congés, reliés aux horaires et à la présence. Module prévu en priorité 3.',
        ]);
    }
}
