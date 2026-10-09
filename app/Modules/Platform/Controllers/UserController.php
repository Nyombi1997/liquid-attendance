<?php
declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;

final class UserController extends Controller
{
    public function index(): void
    {
        $users = Database::all('SELECT id, username, full_name, role, active, last_login_at FROM users ORDER BY full_name');
        $this->render('Platform:users', ['title' => 'Utilisateurs', 'users' => $users]);
    }

    public function store(): void
    {
        $username = (string) $this->input('username', '');
        $fullName = (string) $this->input('full_name', '');
        $role = (string) $this->input('role', 'lecteur');
        $password = (string) ($_POST['password'] ?? '');

        $error = $this->validate($username, $fullName, $role, $password, true);
        if (!$error && Database::value('SELECT id FROM users WHERE username = ?', [$username])) {
            $error = 'Cet identifiant existe déjà.';
        }
        if ($error) {
            $this->withInput();
            flash('error', $error);
            $this->redirect('/utilisateurs');
        }

        Database::run('INSERT INTO users (username, full_name, password_hash, role) VALUES (?, ?, ?, ?)',
            [$username, $fullName, password_hash($password, PASSWORD_DEFAULT), $role]);
        Audit::log('utilisateur_cree', ['username' => $username, 'role' => $role]);
        flash('success', "Compte « $username » créé.");
        $this->redirect('/utilisateurs');
    }

    public function update(int $id): void
    {
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$id]) ?? $this->notFound();
        $fullName = (string) $this->input('full_name', $user['full_name']);
        $role = (string) $this->input('role', $user['role']);
        $active = isset($_POST['active']) ? 1 : 0;
        $password = (string) ($_POST['password'] ?? '');

        if ($id === Auth::id() && ($role !== 'admin' || !$active)) {
            flash('error', 'Vous ne pouvez pas retirer vos propres droits d\'administrateur.');
            $this->redirect('/utilisateurs');
        }
        if ($error = $this->validate($user['username'], $fullName, $role, $password, false)) {
            flash('error', $error);
            $this->redirect('/utilisateurs');
        }

        Database::run('UPDATE users SET full_name = ?, role = ?, active = ? WHERE id = ?', [$fullName, $role, $active, $id]);
        if ($password !== '') {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
        }
        Audit::log('utilisateur_modifie', ['id' => $id, 'role' => $role, 'actif' => $active, 'mdp' => $password !== '']);
        flash('success', 'Compte mis à jour.');
        $this->redirect('/utilisateurs');
    }

    public function audit(): void
    {
        $rows = Database::all(
            'SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 300'
        );
        $this->render('Platform:audit', ['title' => 'Journal d\'activité', 'rows' => $rows]);
    }

    private function validate(string $username, string $fullName, string $role, string $password, bool $isNew): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
            return 'Identifiant invalide (3 à 60 caractères : lettres, chiffres, point, tiret).';
        }
        if ($fullName === '') {
            return 'Le nom complet est obligatoire.';
        }
        if (!isset(Auth::ROLES[$role])) {
            return 'Rôle invalide.';
        }
        if (($isNew || $password !== '') && mb_strlen($password) < 12) {
            return 'Le mot de passe doit contenir au moins 12 caractères.';
        }
        return null;
    }
}
