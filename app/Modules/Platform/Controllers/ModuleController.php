<?php
declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Controller;

/** Modules prévus mais pas encore développés (le Parc automobile a son propre module : app/Modules/Fleet). */
final class ModuleController extends Controller
{
    public function leave(): void
    {
        $this->render('Platform:soon', [
            'title' => 'Congés',
            'text'  => 'Demandes et suivi des congés, reliés aux horaires et à la présence. Module prévu en priorité 3.',
        ]);
    }
}
