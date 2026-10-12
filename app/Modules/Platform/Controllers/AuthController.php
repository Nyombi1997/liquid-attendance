<?php
declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Auth;
use App\Core\Controller;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::user()) {
            $this->redirect(Auth::home());
        }
        $this->render('Platform:login', ['title' => 'Connexion'], 'auth');
    }

    public function login(): void
    {
        $username = Auth::normalizeUsername((string) $this->input('username', ''));
        $password = (string) ($_POST['password'] ?? '');
        $this->withInput();

        if (Auth::isThrottled($username, client_ip())) {
            flash('error', 'Trop de tentatives. Réessayez dans 15 minutes.');
            $this->redirect('/connexion');
        }
        if ($username === '' || $password === '' || !Auth::attempt($username, $password)) {
            flash('error', 'Identifiant ou mot de passe incorrect.');
            $this->redirect('/connexion');
        }

        $to = $_SESSION['_intended'] ?? '/';
        unset($_SESSION['_intended'], $_SESSION['_old']);
        $valid = str_starts_with($to, '/') && !str_starts_with($to, '//');
        $this->redirect($valid && $to !== '/' ? $to : Auth::home());
    }

    public function logout(): void
    {
        Auth::logout();
        $this->redirect('/connexion');
    }
}
