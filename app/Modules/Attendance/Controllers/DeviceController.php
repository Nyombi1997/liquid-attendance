<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;

/** État des pointeuses ; seuls les admins modifient leur configuration. */
final class DeviceController extends Controller
{
    public function index(): void
    {
        $this->render('Attendance:devices', [
            'title'   => 'Pointeuses',
            'devices' => Database::all(
                'SELECT d.*, o.name office,
                        (SELECT MAX(punched_at) FROM punches p WHERE p.device_id = d.id) last_punch
                   FROM devices d JOIN offices o ON o.id = d.office_id ORDER BY d.usage_type DESC, o.name, d.direction'
            ),
            'offices' => Database::all('SELECT * FROM offices ORDER BY name'),
        ]);
    }

    public function store(): void
    {
        $d = $this->validated();
        if (Database::value('SELECT id FROM devices WHERE ip = ?', [$d['ip']])) {
            flash('error', 'Une pointeuse utilise déjà cette adresse IP.');
            $this->redirect('/pointeuses');
        }
        Database::run(
            'INSERT INTO devices (name, ip, port, office_id, direction, usage_type, active) VALUES (?,?,?,?,?,?,?)',
            array_values($d)
        );
        Audit::log('pointeuse_ajoutee', $d);
        flash('success', 'Pointeuse ajoutée. Elle sera interrogée au prochain passage du service de synchronisation.');
        $this->redirect('/pointeuses');
    }

    public function update(int $id): void
    {
        $d = $this->validated();
        if (Database::value('SELECT id FROM devices WHERE ip = ? AND id <> ?', [$d['ip'], $id])) {
            flash('error', 'Une pointeuse utilise déjà cette adresse IP.');
            $this->redirect('/pointeuses');
        }
        Database::run(
            'UPDATE devices SET name = ?, ip = ?, port = ?, office_id = ?, direction = ?, usage_type = ?, active = ? WHERE id = ?',
            [...array_values($d), $id]
        );
        Audit::log('pointeuse_modifiee', ['id' => $id] + $d);
        flash('success', 'Pointeuse mise à jour. Pensez à recalculer la période concernée si son rôle a changé.');
        $this->redirect('/pointeuses');
    }

    private function validated(): array
    {
        $office = trim((string) $this->input('office', ''));
        $officeId = (int) Database::value('SELECT id FROM offices WHERE name = ?', [$office]);
        if (!$officeId && $office !== '') {
            $officeId = Database::insert('INSERT INTO offices (name) VALUES (?)', [mb_substr($office, 0, 100)]);
        }
        $d = [
            'name'       => mb_substr((string) $this->input('name', ''), 0, 100),
            'ip'         => (string) $this->input('ip', ''),
            'port'       => (int) $this->input('port', 4370) ?: 4370,
            'office_id'  => $officeId,
            'direction'  => $this->input('direction') === 'sortie' ? 'sortie' : 'entree',
            'usage_type' => $this->input('usage_type') === 'acces' ? 'acces' : 'presence',
            'active'     => isset($_POST['active']) ? 1 : 0,
        ];
        if ($d['name'] === '' || !filter_var($d['ip'], FILTER_VALIDATE_IP) || !$officeId) {
            flash('error', 'Nom, adresse IP valide et bureau sont obligatoires.');
            $this->redirect('/pointeuses');
        }
        return $d;
    }
}
