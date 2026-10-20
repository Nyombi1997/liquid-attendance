<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Services;

/**
 * Frise des réservations (affectation et planning) : position des barres en
 * pourcentage de la période affichée. La CSP interdit style= : les vues
 * écrivent data-span-left / data-span-width, app.js applique les positions.
 */
final class Timeline
{
    /**
     * Position d'un créneau dans la période [$from, $to[ ; null s'il est en dehors.
     *
     * @return array{0:float, 1:float}|null [gauche, largeur] en %
     */
    public static function span(string $start, string $end, string $from, string $to): ?array
    {
        $a = strtotime($from);
        $b = strtotime($to);
        $s = max(strtotime($start), $a);
        $e = min(strtotime($end), $b);
        if ($b <= $a || $e <= $s) {
            return null;
        }
        return [round(($s - $a) / ($b - $a) * 100, 3), round(($e - $s) / ($b - $a) * 100, 3)];
    }

    /**
     * Graduations : une par jour au-delà de 2 jours, sinon toutes les 2 heures.
     *
     * @return array<int, array{0:float, 1:float, 2:string}> [gauche, largeur, libellé]
     */
    public static function ticks(string $from, string $to): array
    {
        $a = strtotime($from);
        $b = strtotime($to);
        $byDay = $b - $a > 2 * 86400;
        $step = $byDay ? 86400 : 7200;
        $jours = ['', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
        $out = [];
        for ($t = $a; $t < $b; $t = $byDay ? strtotime('+1 day', $t) : $t + $step) {
            $next = min($b, $byDay ? strtotime('+1 day', $t) : $t + $step);
            $label = $byDay ? $jours[(int) date('N', $t)] . ' ' . date('d/m', $t) : date('H', $t) . 'h' . (date('H', $t) === '00' ? ' ' . date('d/m', $t) : '');
            $out[] = [round(($t - $a) / ($b - $a) * 100, 3), round(($next - $t) / ($b - $a) * 100, 3), $label];
        }
        return $out;
    }
}
