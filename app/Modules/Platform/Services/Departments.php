<?php
declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Core\Database;

/**
 * Départements (table departments) : arborescence partagée par tous les modules.
 * Un agent (employees.department_id) et un manager (users.department_id)
 * appartiennent à un département ; un manager voit aussi les sous-départements.
 * kind : direction ou service ; head_employee_id : responsable (N+1) qui valide
 * les demandes de véhicule (FleetWorkflow::resolveN1()).
 */
final class Departments
{
    /** @var array<int, array>|null */
    private static ?array $all = null;

    /** Tous les départements, indexés par id. */
    public static function all(): array
    {
        if (self::$all === null) {
            self::$all = [];
            foreach (Database::all('SELECT id, name, parent_id, kind, head_employee_id FROM departments ORDER BY name') as $d) {
                self::$all[(int) $d['id']] = ['id' => (int) $d['id'], 'name' => $d['name'],
                    'parent_id' => $d['parent_id'] !== null ? (int) $d['parent_id'] : null, 'kind' => $d['kind'],
                    'head_employee_id' => $d['head_employee_id'] !== null ? (int) $d['head_employee_id'] : null];
            }
        }
        return self::$all;
    }

    /** À appeler après une modification de la table. */
    public static function forget(): void
    {
        self::$all = null;
    }

    /**
     * Le département et tous ses sous-départements.
     *
     * @return int[]
     */
    public static function descendants(int $id): array
    {
        $all = self::all();
        if (!isset($all[$id])) {
            return [];
        }
        $ids = [$id];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($all as $d) {
                if ($d['parent_id'] === $ids[$i] && !in_array($d['id'], $ids, true)) {
                    $ids[] = $d['id'];
                }
            }
        }
        return $ids;
    }

    /**
     * Arbre à plat, dans l'ordre d'affichage, avec la profondeur de chaque
     * département. $rootIds limite l'arbre à ces racines (périmètre d'un manager).
     *
     * @param int[]|null $rootIds
     * @return array<int, array{id:int, name:string, parent_id:?int, depth:int, has_children:bool}>
     */
    public static function tree(?array $rootIds = null): array
    {
        $all = self::all();
        $children = [];
        foreach ($all as $d) {
            // Parent introuvable (supprimé) : le département remonte à la racine
            $parent = $d['parent_id'] !== null && isset($all[$d['parent_id']]) ? $d['parent_id'] : 0;
            $children[$parent][] = $d['id'];
        }
        $roots = $rootIds ?? ($children[0] ?? []);
        $out = [];
        $walk = static function (array $ids, int $depth) use (&$walk, &$out, $all, $children): void {
            foreach ($ids as $id) {
                if (!isset($all[$id]) || isset($out[$id])) {
                    continue;
                }
                $out[$id] = $all[$id] + ['depth' => $depth, 'has_children' => !empty($children[$id])];
                $walk($children[$id] ?? [], $depth + 1);
            }
        };
        $walk($roots, 0);
        return array_values($out);
    }

    /**
     * Racines d'un périmètre (départements dont le parent n'en fait pas partie),
     * pour afficher l'arbre d'un manager. null (tout voir) reste null.
     *
     * @param int[]|null $scope
     * @return int[]|null
     */
    public static function roots(?array $scope): ?array
    {
        if ($scope === null) {
            return null;
        }
        $all = self::all();
        return array_values(array_filter($scope, static fn(int $id) => !in_array($all[$id]['parent_id'] ?? null, $scope, true)));
    }

    /** « Direction › Informatique » */
    public static function path(?int $id): string
    {
        $all = self::all();
        $names = [];
        while ($id !== null && isset($all[$id]) && count($names) < 10) {
            array_unshift($names, $all[$id]['name']);
            $id = $all[$id]['parent_id'];
        }
        return implode(' › ', $names);
    }

    /**
     * Condition SQL de périmètre, à ajouter après un WHERE :
     * '' (tout voir), ' AND 0' (rien) ou ' AND e.department_id IN (1,2)'.
     * Les ids sont des entiers : aucune donnée saisie n'entre dans la requête.
     *
     * @param int[]|null $ids
     */
    public static function sql(?array $ids, string $column = 'e.department_id'): string
    {
        if ($ids === null) {
            return '';
        }
        if (!$ids) {
            return ' AND 0';
        }
        return ' AND ' . $column . ' IN (' . implode(',', array_map('intval', $ids)) . ')';
    }
}
