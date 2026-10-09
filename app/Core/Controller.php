<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function render(string $view, array $data = [], ?string $layout = 'layout'): void
    {
        (new View())->render($view, $data, $layout);
    }

    protected function redirect(string $to): never
    {
        header('Location: ' . $to);
        exit;
    }

    protected function back(): never
    {
        $this->redirect($_SERVER['HTTP_REFERER'] ?? '/');
    }

    /** Conserve la saisie pour la réafficher après une erreur de validation. */
    protected function withInput(): void
    {
        $input = $_POST;
        unset($input['_csrf'], $input['password'], $input['password_confirm']);
        $_SESSION['_old'] = $input;
    }

    protected function input(string $key, mixed $default = null): mixed
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    protected function notFound(): never
    {
        http_response_code(404);
        (new View())->render('errors/404', ['title' => 'Page introuvable']);
        exit;
    }
}
