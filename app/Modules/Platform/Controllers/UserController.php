<?php
declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Config;
use App\Core\Database;
use App\Core\Mailer;
use App\Modules\Platform\Services\Departments;
use App\Modules\Platform\Services\Notifications;

final class UserController extends Controller
{
    public function index(): void
    {
        $users = Database::all(
            'SELECT u.id, u.username, u.full_name, u.email, u.role, u.department_id, u.employee_id, u.fleet_role, u.active, u.last_login_at,
                    e.name employee_name
               FROM users u LEFT JOIN employees e ON e.id = u.employee_id ORDER BY u.full_name'
        );
        $this->render('Platform:users', [
            'title'       => 'Utilisateurs',
            'users'       => $users,
            'departments' => Departments::tree(),
            'employees'   => Database::all('SELECT e.id, e.name, u.id user_id FROM employees e LEFT JOIN users u ON u.employee_id = e.id
                                             WHERE e.active = 1 ORDER BY e.name'),
        ]);
    }

    public function store(): void
    {
        $username = Auth::normalizeUsername((string) $this->input('username', ''));
        $fullName = (string) $this->input('full_name', '');
        $email = $this->email();
        $role = (string) $this->input('role', 'lecteur');
        $department = $this->department($role);
        [$employee, $fleetRole] = $this->fleet();
        $password = (string) ($_POST['password'] ?? '');

        $error = $this->validate($username, $fullName, $email, $role, $department, $password, true)
            ?? $this->validateFleet(null, $role, $employee, $fleetRole);
        if (!$error && Database::value('SELECT id FROM users WHERE username = ?', [$username])) {
            $error = 'Cet identifiant existe déjà.';
        }
        if ($error) {
            $this->withInput();
            flash('error', $error);
            $this->redirect('/utilisateurs');
        }

        $id = Database::insert('INSERT INTO users (username, full_name, email, password_hash, role, department_id, employee_id, fleet_role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$username, $fullName, $email, password_hash($password, PASSWORD_DEFAULT), $role, $department, $employee, $fleetRole]);
        Audit::log('utilisateur_cree', ['username' => $username, 'email' => $email, 'role' => $role, 'departement' => $department,
            'employe' => $employee, 'role_flotte' => $fleetRole]);
        $sent = $this->welcome($id, $username, $fullName, $role);
        flash('success', "Compte « $username » créé." . ($sent ? ' Un e-mail de bienvenue lui est envoyé à ' . $sent . '.' : ''));
        $this->redirect('/utilisateurs');
    }

    public function update(int $id): void
    {
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$id]) ?? $this->notFound();
        $fullName = (string) $this->input('full_name', $user['full_name']);
        $email = $this->email();
        $role = (string) $this->input('role', $user['role']);
        $department = $this->department($role);
        [$employee, $fleetRole] = $this->fleet();
        $active = isset($_POST['active']) ? 1 : 0;
        $password = (string) ($_POST['password'] ?? '');

        if ($id === Auth::id() && ($role !== 'admin' || !$active)) {
            flash('error', 'Vous ne pouvez pas retirer vos propres droits d\'administrateur.');
            $this->redirect('/utilisateurs');
        }
        if ($error = $this->validate($user['username'], $fullName, $email, $role, $department, $password, false)
            ?? $this->validateFleet($id, $role, $employee, $fleetRole)) {
            flash('error', $error);
            $this->redirect('/utilisateurs');
        }

        Database::run('UPDATE users SET full_name = ?, email = ?, role = ?, department_id = ?, employee_id = ?, fleet_role = ?, active = ? WHERE id = ?',
            [$fullName, $email, $role, $department, $employee, $fleetRole, $active, $id]);
        if ($password !== '') {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
        }
        Audit::log('utilisateur_modifie', ['id' => $id, 'email' => $email, 'role' => $role, 'departement' => $department, 'employe' => $employee,
            'role_flotte' => $fleetRole, 'actif' => $active, 'mdp' => $password !== '']);
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

    /** Adresse e-mail saisie (facultative), en minuscules. */
    private function email(): ?string
    {
        return mb_strtolower(trim(mb_substr((string) $this->input('email', ''), 0, 150))) ?: null;
    }

    /**
     * E-mail de bienvenue (et message dans la cloche) au nouveau compte, s'il a une
     * adresse (la sienne ou celle de sa fiche employé) et que le SMTP est configuré.
     * Le mot de passe n'est jamais écrit dans l'e-mail : l'administrateur le
     * communique lui-même. Renvoie l'adresse, ou null si aucun e-mail ne part.
     */
    private function welcome(int $id, string $username, string $fullName, string $role): ?string
    {
        $to = Database::value(
            "SELECT COALESCE(NULLIF(u.email, ''), NULLIF(e.email, '')) FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.id = ?",
            [$id]
        );
        $app = (string) Config::get('app_name', 'Liquid Présence');
        Notifications::send([$id], 'compte_cree', 'Bienvenue sur ' . $app,
            'Bonjour ' . $fullName . ', votre compte a été créé (' . (Auth::ROLES[$role] ?? $role) . '). Identifiant : ' . $username
            . '. Adresse : ' . rtrim((string) Config::get('app_url'), '/') . '/connexion'
            . '. Votre mot de passe provisoire vous est communiqué par l\'administrateur : changez-le dès la première connexion (Mon profil).',
            '/profil', null, 'platform');
        return $to && Mailer::configured() ? (string) $to : null;
    }

    /** Département : seulement pour un manager (son périmètre). */
    private function department(string $role): ?int
    {
        return $role === 'manager' ? ((int) $this->input('department_id', 0) ?: null) : null;
    }

    /**
     * Fiche employé reliée au compte et rôle Parc automobile.
     * @return array{0:?int, 1:string}
     */
    private function fleet(): array
    {
        $role = (string) $this->input('fleet_role', 'demandeur');
        return [(int) $this->input('employee_id', 0) ?: null, isset(Auth::FLEET_ROLES[$role]) ? $role : 'demandeur'];
    }

    /** Une fiche par compte ; fiche obligatoire pour un compte « employé », un chauffeur ou un validateur. */
    private function validateFleet(?int $userId, string $role, ?int $employee, string $fleetRole): ?string
    {
        if ($employee === null) {
            return match (true) {
                $role === 'employe'          => 'Un compte « employé » doit être relié à une fiche employé.',
                $fleetRole === 'chauffeur'   => 'Un chauffeur doit être relié à sa fiche employé.',
                $fleetRole === 'validateur'  => 'Un validateur doit être relié à sa fiche employé.',
                default                      => null,
            };
        }
        if (!Database::value('SELECT id FROM employees WHERE id = ?', [$employee])) {
            return 'Fiche employé introuvable.';
        }
        $other = Database::value('SELECT username FROM users WHERE employee_id = ? AND id <> ?', [$employee, $userId ?? 0]);
        return $other ? "Cette fiche employé est déjà reliée au compte « $other »." : null;
    }

    private function validate(string $username, string $fullName, ?string $email, string $role, ?int $department, string $password, bool $isNew): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
            return 'Identifiant invalide (3 à 60 caractères : lettres, chiffres, point, tiret).';
        }
        if ($fullName === '') {
            return 'Le nom complet est obligatoire.';
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Adresse e-mail invalide.';
        }
        if (!isset(Auth::ROLES[$role])) {
            return 'Rôle invalide.';
        }
        if ($role === 'manager' && ($department === null || !isset(Departments::all()[$department]))) {
            return 'Choisissez le département du manager.';
        }
        if ($isNew || $password !== '') {
            return Auth::passwordError($password);
        }
        return null;
    }
}
