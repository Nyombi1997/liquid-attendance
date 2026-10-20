<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int, array{method:string, regex:string, handler:array, ability:?string}> */
    private array $routes = [];

    /**
     * @param string      $ability null = page publique, sinon un droit de Auth (board, presence, staff, calendar, view, status, manage, admin)
     */
    public function add(string $method, string $path, array $handler, ?string $ability = 'view'): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[0-9]+)', rtrim($path, '/') ?: '/') . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'ability' => $ability];
    }

    public function get(string $path, array $handler, ?string $ability = 'view'): void
    {
        $this->add('GET', $path, $handler, $ability);
    }

    public function post(string $path, array $handler, ?string $ability = 'manage'): void
    {
        $this->add('POST', $path, $handler, $ability);
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/') ?: '/';
        $allowed = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $allowed = true;
            if ($route['method'] !== $method) {
                continue;
            }

            if ($method === 'POST' && !Csrf::check($_POST['_csrf'] ?? null)) {
                http_response_code(419);
                flash('error', 'La session a expiré, merci de recommencer.');
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/'));
                return;
            }

            if ($route['ability'] !== null) {
                // API : réponse JSON, jamais de redirection vers une page HTML
                if (str_starts_with($path, '/api/') && !Auth::can($route['ability'])) {
                    http_response_code(Auth::user() === null ? 401 : 403);
                    header('Content-Type: application/json; charset=utf-8');
                    header('Cache-Control: no-store');
                    echo json_encode(['error' => Auth::user() === null ? 'session' : 'droits']);
                    return;
                }
                if (Auth::user() === null) {
                    $_SESSION['_intended'] = $method === 'GET' ? $uri : '/';
                    header('Location: /connexion');
                    return;
                }
                if (!Auth::can($route['ability'])) {
                    // Page d'accueil (PWA, logo) d'un compte qui n'y a pas accès : sa propre page d'accueil
                    if ($method === 'GET' && $path === '/' && Auth::home() !== '/') {
                        header('Location: ' . Auth::home());
                        return;
                    }
                    http_response_code(403);
                    (new View())->render('errors/403', ['title' => 'Accès refusé']);
                    return;
                }
            }

            $params = array_map('intval', array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
            [$class, $action] = $route['handler'];
            (new $class())->$action(...array_values($params));
            unset($_SESSION['_old']);
            return;
        }

        http_response_code($allowed ? 405 : 404);
        (new View())->render('errors/404', ['title' => 'Page introuvable'], Auth::user() ? 'layout' : 'auth');
    }
}
