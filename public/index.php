<?php
declare(strict_types=1);

// Contrôleur frontal : toutes les requêtes passent par ici.

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Router;
use App\Modules\Attendance\Controllers as A;
use App\Modules\Platform\Controllers as P;

session_name('liquid_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => (bool) App\Core\Config::get('session_secure', true), 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$r = new Router();

// Socle : connexion, profil, utilisateurs, modules à venir
$r->get('/connexion', [P\AuthController::class, 'showLogin'], null);
$r->post('/connexion', [P\AuthController::class, 'login'], null);
$r->post('/deconnexion', [P\AuthController::class, 'logout'], 'view');
$r->get('/profil', [P\ProfileController::class, 'show']);
$r->post('/profil/mot-de-passe', [P\ProfileController::class, 'updatePassword'], 'view');
$r->get('/utilisateurs', [P\UserController::class, 'index'], 'admin');
$r->post('/utilisateurs', [P\UserController::class, 'store'], 'admin');
$r->post('/utilisateurs/{id}', [P\UserController::class, 'update'], 'admin');
$r->get('/journal', [P\UserController::class, 'audit'], 'admin');
$r->get('/flotte', [P\ModuleController::class, 'fleet']);
$r->get('/conges', [P\ModuleController::class, 'leave']);

// Employés et groupes (liste commune à tous les modules)
$r->get('/employes', [A\EmployeeController::class, 'index']);
$r->get('/employes/{id}', [A\EmployeeController::class, 'show']);
$r->get('/employes/{id}/modifier', [A\EmployeeController::class, 'edit'], 'manage');
$r->post('/employes/{id}', [A\EmployeeController::class, 'update']);
$r->post('/employes/groupe', [A\EmployeeController::class, 'bulkGroup']);
$r->get('/groupes', [A\GroupController::class, 'index']);
$r->post('/groupes', [A\GroupController::class, 'store']);
$r->post('/groupes/{id}', [A\GroupController::class, 'update']);
$r->post('/groupes/{id}/supprimer', [A\GroupController::class, 'destroy']);

// Présence
$r->get('/', [A\DashboardController::class, 'index']);
$r->get('/presence', [A\DailyController::class, 'index']);
$r->get('/presence/export', [A\DailyController::class, 'export']);
$r->get('/corrections', [A\CorrectionController::class, 'index']);
$r->post('/corrections/{id}', [A\CorrectionController::class, 'store']);
$r->post('/corrections/{id}/annuler', [A\CorrectionController::class, 'cancel']);
$r->get('/acces', [A\AccessController::class, 'index']);

// Horaires et attributions
$r->get('/horaires', [A\ScheduleController::class, 'index']);
$r->get('/horaires/nouveau', [A\ScheduleController::class, 'create'], 'manage');
$r->post('/horaires', [A\ScheduleController::class, 'store']);
$r->get('/horaires/{id}/modifier', [A\ScheduleController::class, 'edit'], 'manage');
$r->post('/horaires/{id}', [A\ScheduleController::class, 'update']);
$r->post('/horaires/{id}/supprimer', [A\ScheduleController::class, 'destroy']);
$r->get('/horaires/attribuer', [A\ScheduleController::class, 'assignForm'], 'manage');
$r->post('/horaires/attribuer', [A\ScheduleController::class, 'assign']);
$r->post('/attributions/{id}/supprimer', [A\ScheduleController::class, 'unassign']);
$r->post('/presence/recalculer', [A\ScheduleController::class, 'recompute']);

// Pointeuses
$r->get('/pointeuses', [A\DeviceController::class, 'index']);
$r->post('/pointeuses', [A\DeviceController::class, 'store'], 'admin');
$r->post('/pointeuses/{id}', [A\DeviceController::class, 'update'], 'admin');

$r->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
