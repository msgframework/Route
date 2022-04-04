<?php

namespace Msgframework\Lib\Route;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request;
use Purl\Url;
use Msgframework\Lib\Registry\Registry;

class Router
{
    protected $application = null;
    protected RouteMap $routeMap;
    protected array $instances = array();
    protected Url $base;
    protected Url $root;
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
        'str' => '[0-9A-Za-z]++',
        'uuid4' => '[0-9a-f]{8}\-[0-9a-f]{4}\-4[0-9a-f]{3}\-[89ab][0-9a-f]{3}\-[0-9a-f]{12}',
        'h' => '[0-9A-Fa-f]++',
        '*' => '.+?',
        '**' => '.++',
        '' => '[^/\.]++'
    );

    public function __construct($application, Request $request, RouteMap $routeMap)
    {
        $this->application = $application;
        $this->request = $request;
        $this->routeMap = $routeMap;
        $config = $this->application->getConfig();
        $this->friendly = $config->get('friendly_url', false);

        if ($this->friendly) {
            $this->setBasePath($config->get('base_url', ''));
        } else {
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

    public function getVars()
    {
        return $this->current()->getVars();
    }

    public function getParams()
    {
        return $this->current()->getParams();
    }

    public function getComponent()
    {
        return $this->current->getComponent();
    }

    public function getController()
    {
        return $this->current->getController();
    }

    public function getAction()
    {
        return $this->current->getAction();
    }

    public function match(Request $request): Route
    {
        $vars = array();

        $requestUrl = $request->getPathInfo();
        $requestMethod = $request->getMethod();

        // strip base path from request url
        $requestUrl = trim(substr($requestUrl, strlen($this->rootPath)), '/');

        if ($requestUrl == "") {
            $requestUrl = '/';
        }

        foreach ($this->routeMap as $route) {
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
                $tmp_vars = $match_route->getVars();

                if (count($vars)) {
                    foreach ($vars as $key => $value) {
                        if (is_numeric($key)) {
                            unset($vars[$key]);
                        } else {
                            $tmp_vars->set($key, $value);
                        }
                    }
                }
//TODO Нужно исправить занесение переменных в vars
                $tmp_vars->merge(new Registry($request->query->all()));

                $match_route->setVars($tmp_vars);

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
        $uri = new Url($this->root());
        $url_parts = array('scheme', 'user', 'pass', 'host', 'port', 'path', 'query', 'fragment');

        if ($ajax) {
            $url_parts = array('path', 'query', 'fragment');
        }

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

                foreach ($current->getVars() as $var => $var_val) {
                    unset($routeVars[$var]);
                }

                $uri->set('path', $url . (($url != "") ? '/' : ''));

                if (isset($routeVars) && count($routeVars)) {
                    $uri->query->setData($routeVars);
                }

                return $uri->getUrl($url_parts);
            }

            $uri->set('path', $this->rootPath);

            return $uri->getUrl($url_parts);
        } else {
            $routeVars = (array)$routeVars;
            $fragment = isset($routeVars['#']) ? '#' . $routeVars['#'] : '';
            unset($routeVars['#']);

            $uri->set('fragment', $fragment);
            $uri->set('path', 'index.php');

            $uri->set('query', $routeVars);

            return $uri->getUrl($url_parts);
        }

        return $uri->getUrl($url_parts);
    }

    public function getInstance($uri = 'SERVER')
    {
        if (empty($this->instances[$uri])) {
            // Are we obtaining the URI from the server?
            if ($uri == 'SERVER') {
                // Determine if the request was over SSL (HTTPS).
                if (isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) && (strtolower($_SERVER['HTTPS']) != 'off')) {
                    $https = 's://';
                } elseif ((isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
                    !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
                    (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) !== 'http'))) {
                    $https = 's://';
                } else {
                    $https = '://';
                }

                /*
                 * Since we are assigning the URI from the server variables, we first need
                 * to determine if we are running on apache or IIS.  If PHP_SELF and REQUEST_URI
                 * are present, we will assume we are running on apache.
                 */

                if (!empty($_SERVER['PHP_SELF']) && !empty($_SERVER['REQUEST_URI'])) {
                    // To build the entire URI we need to prepend the protocol, and the http host
                    // to the URI string.
                    $theURI = 'http' . $https . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                } else {
                    /*
                     * Since we do not have REQUEST_URI to work with, we will assume we are
                     * running on IIS and will therefore need to work some magic with the SCRIPT_NAME and
                     * QUERY_STRING environment variables.
                     *
                     * IIS uses the SCRIPT_NAME variable instead of a REQUEST_URI variable... thanks, MS
                     */
                    $theURI = 'http' . $https . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];

                    // If the query string exists append it to the URI string
                    if (isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING'])) {
                        $theURI .= '?' . $_SERVER['QUERY_STRING'];
                    }
                }

                // Extra cleanup to remove invalid chars in the URL to prevent injections through the Host header
                $theURI = str_replace(array("'", '"', '<', '>'), array('%27', '%22', '%3C', '%3E'), $theURI);
            } else {
                // We were given a URI
                $theURI = $uri;
            }

            $this->instances[$uri] = new Url($theURI);
        }

        return $this->instances[$uri];
    }

    public function base(bool $pathonly = false): string
    {
        $app = $this->application;
        if (empty($this->base)) {
            $config = $app->getConfig();
            $uri = $this->getInstance();

            $request = $this->request;

            $base_site = ($request->isSecure()) ? str_replace('http://', 'https://', $config->get('base_site', false)) : $config->get('base_site', false);

            if (trim($base_site) != '') {
                $uri = $this->getInstance($base_site);

                if ($config->get('base_url', false)) {
                    $uri->path->add($config->get('base_url'));
                }
            } else {
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

                $uri->set('path', $script_name . (($script_name != "") ? '/' : ''));
            }

            $this->base = $uri;
        }

        if ($pathonly === false) {
            return $this->base->getUrl(array('scheme', 'host', 'port', 'path'));
        } else {
            return $this->base->getUrl(array('path'));
        }
    }

    public function root(): string
    {
        if (empty($this->root)) {
            $this->root = $this->getInstance($this->rootPath);
        }

        return $this->root->getUrl(array('scheme', 'host', 'port'));
    }

    public function current(): Route
    {
        return $this->current;
    }

    public function setError(): void
    {
        $target = new \stdClass();
        $target->component = 'errors';
        $target->controller = 'errors';
        $target->action = 'index';
        $target->vars = new Registry();
        $target->params = new Registry();

        $component = $this->application->getExtensionByName('component', 'errors');

        $this->current = new Route($component, ['GET', 'POST'], '', $target);
    }

    public function setOffline(): void
    {
        $target = new \stdClass();
        $target->component = 'offline';
        $target->controller = 'offline';
        $target->action = 'index';
        $target->vars = new Registry();
        $target->params = new Registry();

        $component = $this->application->getExtensionByName('component', 'errors');

        $this->current = new Route($component, ['GET', 'POST'], '', $target);
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