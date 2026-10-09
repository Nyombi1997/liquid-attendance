<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Rendu des vues.
 * 'Attendance:dashboard' → app/Modules/Attendance/Views/dashboard.php
 * 'errors/404'           → app/Views/errors/404.php
 */
final class View
{
    public function render(string $view, array $data = [], ?string $layout = 'layout'): void
    {
        $content = $this->capture($this->resolve($view), $data);
        if ($layout === null) {
            echo $content;
            return;
        }
        echo $this->capture(APP_PATH . '/Views/' . $layout . '.php', $data + ['content' => $content]);
    }

    private function resolve(string $view): string
    {
        if (str_contains($view, ':')) {
            [$module, $name] = explode(':', $view, 2);
            return APP_PATH . '/Modules/' . $module . '/Views/' . $name . '.php';
        }
        return APP_PATH . '/Views/' . $view . '.php';
    }

    private function capture(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
