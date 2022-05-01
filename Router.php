<?php

namespace Msgframework\Lib\Route;

use Msgframework\Lib\Config\Config;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request;
use League\Uri\Uri;
use League\Uri\UriModifier;
use Msgframework\Lib\Registry\Registry;

class Router
{
    protected Config $config;
    protected RouteMap $routeMap;
    protected array $instances = array();
    protected Uri $base;
    protected Uri $root;
    protected Route $current;
    protected bool $friendly = false;
    protected Request $request;

    /**
     * @var string Can be used to ignore leading part of the Request URL (if main file lives in subdirectory of host)
     */
    protected string $rootPath = '';

    /**
     * @var array Array of default match types (regex helpers)
     */
    protected array $matchTypes = array(
        'int' => '[0-9]++',
        'str' => '[0-9A-Za-z\-]++',
        'uuid4' => '[0-9a-f]{8}\-[0-9a-f]{4}\-4[0-9a-f]{3}\-[89ab][0-9a-f]{3}\-[0-9a-f]{12}',
        'h' => '[0-9A-Fa-f]++',
        '*' => '.+?',
        '**' => '.++',
        '' => '[^/\.]++'
    );

    public function __construct(Config $config, Request $request, RouteMap $routeMap)
    {
        $this->config = $config;
        $this->request = $request;
        $this->routeMap = $routeMap;
        $this->friendly = $this->config->get('friendly_url', false);

        $this->setRootPath($this->config->get('root_path', ''));

        if (!$this->friendly) {
            $routes = explode('/', $_GET['route']);
            $_SERVER['REDIRECT_URL'] = $_GET['route'];

            unset($_GET['route']);

            if (trim($routes[0]) == "") {
                $routes = null;
            }
        }
    }

    public function __get($name)
    {
        $method = "get" . ucfirst($name);

        if(!isset($this->$name)) {
            throw new \RuntimeException(sprintf('Property %s can not be read from this Extension', $name));
        }

        if(!\is_callable(array($this, $method))) {
            throw new \RuntimeException(sprintf('Method %s can\'t be call from this Extension', $method));
        }

        return $this->$method();
    }

    public function match(Request $request): Route
    {
        $vars = array();

        $requestUrl = $request->getPathInfo();
        $requestMethod = $request->getMethod();

        // strip base path from request url
        $requestUrl = trim(substr($requestUrl, strlen($this->rootPath)), '/');
        $requestUrl = trim(substr($requestUrl, strlen($this->config->get('base_uri', ''))), '/');

        if ($requestUrl == "") {
            $requestUrl = '/';
        }

        foreach ($this->routeMap as $route) {
            $match = 0;
            if (!in_array($requestMethod, $route->getMethods())) {
                continue;
            }

            if (isset($route->getPath()[0]) && $route->getPath()[0] === '@') {
                // @ regex delimiter
                $pattern = '`' . substr($route->getPath(), 1) . '`u';
                $match = preg_match($pattern, $requestUrl, $vars) === 1;
            } elseif (($position = strpos($route->getPath(), '[')) === false) {
                // No params in url, do string comparison
                $match = strcmp($requestUrl, $route->getPath()) === 0;
            } else {
                // Compare longest non-param string with url
                if (strncmp($requestUrl, $route->getPath(), $position) !== 0) {
                    continue;
                }
                $regex = $this->compileRoute($route->getPath());
                $match = preg_match($regex, $requestUrl, $vars) === 1;
            }

            if ($match) {
                $match_route = clone $route;
                $tmp_vars = $match_route->vars;

                if (count($vars)) {
                    foreach ($vars as $key => $value) {
                        if (is_numeric($key)) {
                            unset($vars[$key]);
                        } else {
                            $tmp_vars->set($key, $value);
                        }
                    }
                }

                $tmp_vars->merge(new Registry($request->query->all()));

                $match_route->vars = $tmp_vars;
                $request->query->add($tmp_vars->toArray());

                $this->current = $match_route;

                return $match_route;
            }
        }

        throw new HttpException(404);
    }

    public function create(string $component, string $controller, string $action = 'index', $routeVars = null, bool $ajax = false)
    {
        $id = Uuid::uuid3(Uuid::NAMESPACE_OID, "route/{$component}/{$controller}/{$action}");

        return $this->buildRoute($id, $routeVars, $ajax);
    }

    public function buildRoute(UuidInterface $id, $routeVars = null, bool $ajax = false)
    {
        $uri = Uri::createFromString($this->base());

        if ($this->friendly) {
            $weight = 0;

            if (!$this->routeMap->hasRoute($id)) {
                return false;
            }

            if ($routeVars instanceof Registry) {
                $routeVars = $routeVars->toArray();
            }

            $routes = $this->routeMap->getRoutes($id);

            $current = $routes[0];

            foreach ($routes as $route) {
                $vars = $route->vars->toArray();

                if (isset($routeVars) && count($routeVars)) {
                    if (preg_match_all('`(/|\.|)\[([^:\]]*+)(?::([^:\]]*+))?\](\?|)`', $route->getPath(), $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $index => $match) {
                            if (isset($routeVars[$match[3]])) {
                                $vars[$match[3]] = $routeVars[$match[3]];
                            }
                        }
                    }

                    foreach ($routeVars as $key => $value) {
                        if (is_numeric($key) || is_object($value) || $value === null) {
                            continue;
                        } elseif (isset($vars[$key]) && $vars[$key] !== $value) {
                            continue 2;
                        } elseif (isset($vars[$key])) {
                            continue;
                        }
                    }

                    $current_weight = count(array_intersect($routeVars, $vars));

                    if ($weight < $current_weight) {
                        $weight = $current_weight;
                        $current = $route;
                    }
                } else {
                    if (!$weight) {
                        $weight = 1;
                        $current = $route;
                    }
                }
            }

            if ($current) {
                if ($current->isHome()) {
                    return $this->base();
                }

                $current->setPath(ltrim($current->getPath(), '/'));
                $url = $this->rootPath . $current->getPath();

                if (preg_match_all('`(/|\.|)\[([^:\]]*+)(?::([^:\]]*+))?\](\?|)`', $current->getPath(), $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $index => $match) {
                        list($block, $pre, $type, $var, $optional) = $match;

                        if ($pre) {
                            $block = substr($block, 1);
                        }

                        if (isset($routeVars[$var])) {
                            // Part is found, replace for param value
                            $url = str_replace($block, $routeVars[$var], $url);
                            unset($routeVars[$var]);
                        } elseif ($optional && $index !== 0) {
                            // Only strip preceeding slash if it's not at the base
                            $url = str_replace($pre . $block, '', $url);
                        } else {
                            // Strip match block
                            $url = str_replace($block, '', $url);
                        }
                    }
                }

                foreach ($current->vars as $var => $var_val) {
                    unset($routeVars[$var]);
                }

                $uri = UriModifier::appendSegment($uri, $url);

                if (isset($routeVars) && count($routeVars)) {
                    $uri = UriModifier::appendQuery($uri, http_build_query($routeVars));
                }

                $uri = UriModifier::addTrailingSlash($uri);

                return $uri->toString();
            }

            return $uri->toString();
        } else {
            $routeVars = (array)$routeVars;
            $fragment = isset($routeVars['#']) ? '#' . $routeVars['#'] : '';
            unset($routeVars['#']);

            $uri->withFragment($fragment);

            $uri = UriModifier::appendSegment($uri, 'index.php');
            $uri = UriModifier::appendQuery($uri, http_build_query($routeVars));

            return $uri->toString();
        }
    }

    public function base(bool $pathonly = false): string
    {
        if (!isset($this->base)) {
            $this->base = Uri::createFromBaseUri('', $this->root);
            $config = $this->config;
            $request = $this->request;

            if (strpos(php_sapi_name(), 'cgi') !== false && !ini_get('cgi.fix_pathinfo') && !empty($_SERVER['REQUEST_URI'])) {
                // PHP-CGI on Apache with "cgi.fix_pathinfo = 0"

                // We shouldn't have user-supplied PATH_INFO in PHP_SELF in this case
                // because PHP will not work with PATH_INFO at all.
                $script_name = $_SERVER['PHP_SELF'];
            } else {
                // Others
                $script_name = $_SERVER['SCRIPT_NAME'];
            }

            // Extra cleanup to remove invalid chars in the URL to prevent injections through broken server implementation
            $script_name = str_replace(array("'", '"', '<', '>'), array('%27', '%22', '%3C', '%3E'), $script_name);

            $script_name = rtrim(dirname($script_name), '/\\');

            if(!empty($script_name)) {
                $this->base = UriModifier::appendSegment($this->base, $script_name);
            }

            if ($config->get('base_uri', false)) {
                $this->base = UriModifier::appendSegment($this->base, $config->get('base_uri'));
            }
            $this->base = UriModifier::addTrailingSlash($this->base);
        }

        if ($pathonly === false) {
            return $this->base->toString();
        } else {
            return $this->base->getPath();
        }
    }

    public function root(): string
    {
        if (!isset($this->root)) {
            $config = $this->config;
            $request = $this->request;

            $scheme = $request->isSecure() ? 'https' : 'http';
            $host   = $config->exists('domain') ? $config->get('domain') : $_SERVER['HTTP_HOST'];
            $baseUrl = sprintf('%s://%s', $scheme, $host);

            $this->root = Uri::createFromString($baseUrl);

            if($config->exists('root_path')) {
                $this->root = UriModifier::appendSegment($this->root, $config->get('root_path'));
            }
            $this->root = UriModifier::addTrailingSlash($this->root);
        }

        return $this->root->toString();
    }

    public function current(): Route
    {
        return $this->current;
    }

    public function setError(): void
    {
        $target = new Target('errors', 'index', new Registry(), array());
        $vars = new Registry();

        $component = $this->application->getExtensionByName('component', 'errors');

        $this->current = new Route($component, ['GET', 'POST'], '', $target, $vars);
    }

    public function getLang()
    {
        return 'ru';
    }

    /**
     * Set the base path.
     * Useful if you are running your application from a subdirectory in domain.
     * Example: https://domain.com/subdirectory/index.php
     */
    private function setRootPath(string $rootPath)
    {
        $this->rootPath = (trim($rootPath, '/') != '') ? trim($rootPath, '/') . '/' : '';
    }

    /**
     * Compile the regex for a given route (EXPENSIVE)
     */
    private function compileRoute($route): string
    {
        if (preg_match_all('`(/|\.|)\[([^:\]]*+)(?::([^:\]]*+))?\](\?|)`', $route, $matches, PREG_SET_ORDER)) {

            $matchTypes = $this->matchTypes;
            foreach ($matches as $match) {
                list($block, $pre, $type, $param, $optional) = $match;

                if (isset($matchTypes[$type])) {
                    $type = $matchTypes[$type];
                }
                if ($pre === '.') {
                    $pre = '\.';
                }

                $optional = $optional !== '' ? '?' : null;

                //Older versions of PCRE require the 'P' in (?P<named>)
                $pattern = '(?:'
                    . ($pre !== '' ? $pre : null)
                    . '('
                    . ($param !== '' ? "?P<$param>" : null)
                    . $type
                    . ')'
                    . $optional
                    . ')'
                    . $optional;

                $route = str_replace($block, $pattern, $route);
            }

        }
        return "`^$route$`u";
    }
}