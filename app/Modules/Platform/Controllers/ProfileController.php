<?php
declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;

final class ProfileController extends Controller
{
    public function show(): void
    {
        $this->render('Platform:profile', ['title' => 'Mon profil', 'user' => Auth::user()]);
    }

    public function updatePassword(): void
    {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $hash = Database::value('SELECT password_hash FROM users WHERE id = ?', [Auth::id()]);

        if (!password_verify($current, (string) $hash)) {
            flash('error', 'Mot de passe actuel incorrect.');
        } elseif (mb_strlen($new) < 12) {
            flash('error', 'Le nouveau mot de passe doit contenir au moins 12 caractères.');
        } elseif ($new !== $confirm) {
            flash('error', 'Les deux saisies ne correspondent pas.');
        } else {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), Auth::id()]);
            Audit::log('mot_de_passe_modifie');
            flash('success', 'Mot de passe modifié.');
        }
        $this->redirect('/profil');
    }
}
