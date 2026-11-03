<?php
declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Core\Auth;

/**
 * Applications de la plateforme, à la manière d'Odoo : un écran d'accueil en grille
 * (/applications) et, dans chaque application, une barre de menus en haut.
 *
 * Chaque entrée de menu : [libellé, adresse, droit, préfixes actifs] ; « /flotte$ » : adresse exacte.
 * Un groupe : ['Libellé', [entrées…]] (menu déroulant ; affiché en lien simple s'il ne reste qu'une entrée).
 * Une entrée n'apparaît qu'avec le droit de sa route ; une application sans entrée visible est masquée.
 */
final class Apps
{
    /** @return array<string, array{name: string, icon: string, color: string, paths: string[], soon?: bool, menu: array}> */
    private static function definitions(): array
    {
        $agents = ['Agents', '/agents', 'board', ['/agents']];
        $dashboard = ['Tableau de bord', '/', 'board', ['/']];
        // La direction et les managers commencent par la page Agents
        $first = Auth::home() === '/agents' ? [$agents, $dashboard] : [$dashboard, $agents];

        return [
            'presence' => [
                'name'  => 'Présence',
                'icon'  => 'fa-solid fa-user-clock',
                'color' => 'presence',
                'paths' => ['/', '/agents', '/presence', '/calendrier', '/pointages', '/corrections', '/acces',
                            '/horaires', '/attributions', '/groupes', '/pointeuses'],
                'menu'  => [
                    ...$first,
                    ['Présence du jour', '/presence', 'presence', ['/presence']],
                    ['Calendrier', '/calendrier', 'calendar', ['/calendrier']],
                    ['Pointages', '/pointages', 'board', ['/pointages']],
                    ['Suivi', [
                        ['Sorties manquantes', '/corrections', 'view', ['/corrections']],
                        ['Accès aux salles', '/acces', 'view', ['/acces']],
                    ]],
                    ['Configuration', [
                        ['Horaires', '/horaires', 'view', ['/horaires', '/attributions']],
                        ['Groupes', '/groupes', 'view', ['/groupes']],
                        ['Pointeuses', '/pointeuses', 'view', ['/pointeuses']],
                    ]],
                ],
            ],
            'employes' => [
                'name'  => 'Employés',
                'icon'  => 'fa-solid fa-users',
                'color' => 'employes',
                'paths' => ['/employes', '/departements'],
                'menu'  => [
                    ['Employés', '/employes', 'staff', ['/employes']],
                    ['Départements', '/departements', 'manage', ['/departements']],
                ],
            ],
            'flotte' => [
                'name'  => 'Parc automobile',
                'icon'  => 'fa-solid fa-car',
                'color' => 'flotte',
                'paths' => ['/flotte'],
                'menu'  => [
                    ['Tableau de bord', '/flotte', 'fleet_board', ['/flotte$']],
                    ['Mes missions', '/flotte/missions', 'fleet_drive', ['/flotte/missions']],
                    ['Demandes', [
                        ['Mes demandes', '/flotte/demandes', 'fleet', ['/flotte/demandes$', '/flotte/demandes/nouvelle']],
                        ['À valider', '/flotte/validations', 'fleet_validate', ['/flotte/validations']],
                        ['Registre des demandes', '/flotte/registre', 'fleet_board', ['/flotte/registre']],
                    ]],
                    ['Planning', '/flotte/planning', 'fleet_board', ['/flotte/planning']],
                    ['Véhicules', '/flotte/vehicules', 'fleet_board', ['/flotte/vehicules', '/flotte/documents']],
                    ['Chauffeurs', '/flotte/chauffeurs', 'fleet_board', ['/flotte/chauffeurs']],
                    ['Configuration', [
                        ['Import CSV', '/flotte/import', 'fleet_manage', ['/flotte/import']],
                        ['Paramètres', '/flotte/parametres', 'admin', ['/flotte/parametres']],
                    ]],
                ],
            ],
            'conges' => [
                'name'  => 'Congés',
                'icon'  => 'fa-solid fa-umbrella-beach',
                'color' => 'conges',
                'paths' => ['/conges'],
                'soon'  => true,
                'menu'  => [
                    ['Congés', '/conges', 'view', ['/conges']],
                ],
            ],
            'parametres' => [
                'name'  => 'Paramètres',
                'icon'  => 'fa-solid fa-gears',
                'color' => 'parametres',
                'paths' => ['/utilisateurs', '/journal', '/etat-du-service'],
                'menu'  => [
                    ['Utilisateurs', '/utilisateurs', 'admin', ['/utilisateurs']],
                    ['Journal d\'activité', '/journal', 'admin', ['/journal']],
                    ['État du service', '/etat-du-service', 'admin', ['/etat-du-service']],
                ],
            ],
        ];
    }

    /**
     * Applications du compte connecté, menus filtrés selon ses droits.
     * Chaque application reçoit « home » : la première entrée visible de son menu.
     */
    public static function all(): array
    {
        static $apps = null;
        if ($apps !== null) {
            return $apps;
        }
        $apps = [];
        foreach (self::definitions() as $key => $app) {
            $menu = [];
            foreach ($app['menu'] as $entry) {
                if (is_array($entry[1])) {
                    $items = array_values(array_filter($entry[1], static fn(array $i) => can($i[2])));
                    if (count($items) > 1) {
                        $menu[] = [$entry[0], $items];
                    } elseif ($items) {
                        $menu[] = $items[0];
                    }
                } elseif (can($entry[2])) {
                    $menu[] = $entry;
                }
            }
            if (!$menu) {
                continue;
            }
            $home = is_array($menu[0][1]) ? $menu[0][1][0][1] : $menu[0][1];
            $apps[$key] = ['key' => $key, 'home' => $home, 'menu' => $menu] + $app;
        }
        return $apps;
    }

    /** L'adresse correspond-elle à l'un des préfixes ? (« / » et « …$ » : adresse exacte) */
    public static function matches(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if ($p === '/' || str_ends_with($p, '$') ? $path === rtrim($p, '$') : str_starts_with($path, $p)) {
                return true;
            }
        }
        return false;
    }

    /** Application de la page affichée, ou null (accueil des applications, profil). */
    public static function current(string $path): ?array
    {
        foreach (self::all() as $app) {
            if (self::matches($path, $app['paths'])) {
                return $app;
            }
        }
        return null;
    }

    /** Page ouverte après la connexion : la grille des applications s'il y en a plusieurs. */
    public static function landing(): string
    {
        return count(self::all()) > 1 ? '/applications' : Auth::home();
    }
}
