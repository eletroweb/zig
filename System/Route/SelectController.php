<?php
namespace System\Route;

/*
|--------------------------------------------------------------------------
| SelectController
|--------------------------------------------------------------------------
| This class is used to call the controller and method according
| the route passed on browser.
|
*/

use System\Request\Request;

class SelectController
{
  private $controller;
  private $method;
  private $getRoute;
  private $allRouters = [];
  private $routerAliases;
  private $routeRegex = [
    '{id}' => '([0-9]{1,})', // rotas passando id: user/1
    '{slug}' => '([a-zA-z0-9_-]+)', // rotas passando slug: usuario/meu-nome-de-usuario
    '{id}-{slug}' => '([0-9]{1,})\-([a-zA-z0-9_-]+)', // rotas passando id e slug: produto/00001-meu-produto
    '{any}' => '([a-zA-Z0-9-_\.]+)', // aceita qualquer parâmetro
  ];

  private $atual;

    /**
    * The construc method receive the getRoute instance.
    * @param getRoute Object
    */
  public function __construct(GetRoute $getRoute)
  {
    $this->getRoute = $getRoute;
    $this->controller = $getRoute->getControllerName();
    $this->method = $getRoute->getMethodName();
    $this->routerAliases = $this->getRoute->getControllerNameAliases();
    if ($this->getRoute->getMethodNameAliases()) {
      $this->routerAliases = "{$this->routerAliases}/{$this->getRoute->getMethodNameAliases()}";
    }
  }

  public function create(string $aliases, string $controllerAndMethod, string $type = "GET")
  {
    $arrayExplode = explode('@', $controllerAndMethod);

    $this->allRouters[$aliases] = [
      'controller' => $arrayExplode[0],
      'method'     => $arrayExplode[1],
      'type'       => $type,
    ];
  }

  public function get(string $aliases, string $controllerAndMethod)
  {
    $this->create($aliases, $controllerAndMethod, "GET");
  }

  public function post(string $aliases, string $controllerAndMethod)
  {
    $this->create($aliases, $controllerAndMethod, "POST");
  }

  public function put(string $aliases, string $controllerAndMethod)
  {
    $this->create($aliases, $controllerAndMethod, "PUT");
  }

  public function delete(string $aliases, string $controllerAndMethod)
  {
    $this->create($aliases, $controllerAndMethod, "DELETE");
  }

    /**
    * The method is used to instantiate the controller
    * @param controller
    * @param method String the method name
    * @return method
    */
  public function instantiateController(string $controller, string $method, array $data = [])
  {
      # Verifying if exist the character \\ in Controller name
      if (strstr($controller,'\\')) {
        $stringToArray = explode('\\', $controller);
        $controllerName = end($stringToArray);
        $controllerNameWithfullNamespace = implode("\\", array_values($stringToArray));
        $controller = "App\Controllers\\".$controllerNameWithfullNamespace;

      } else {
        $controllerName = $controller;
        $controller = "App\Controllers\\".$controller;
      }

      # Instanciate the Controller
      $controller = new $controller;

      # Call the Controller Method
      # data is empty
      if (!count($data)) {
        return call_user_func([$controller, $method]);
      }

      $data = explode('/', $data[0]);

      # data is not empty
      return call_user_func_array([$controller, $method], $data);
  }

  public function run()
  {
      // se rota for vazia, verifica se existe rot para vazio e adiciona uma barra
      if ($this->routerAliases == '' && !isset($this->allRouters['']) && isset($this->allRouters['/'])) {
        $this->routerAliases = '/';
      }
      // quanta a quantidade de barras na rota atual
      $barsInActualRoute = substr_count($this->routerAliases, '/');
      // pega todas as rotas parecidas
      $similarRoutes = array_filter($this->allRouters, function ($data, $route) use($barsInActualRoute) {
        // pra home
        if ($route == '/') {
          return $this->routerAliases == '/';
        }
        // rotas opcionais
        if (preg_match('/\{(.*)\?\}/', $route)) {
          return true;
        }
        return substr_count($route, '/') == $barsInActualRoute;
      }, ARRAY_FILTER_USE_BOTH);
      // busca por rotas com regex
      $similarRoutes = array_map(function ($route, $data) {
        if (preg_match_all("/\{([a-zA-Z0-9\?]+)\}/", $route, $matches)) {
          $data['realRoute'] = $route;
          $route = $this->manipulateRouteRegex($route, $matches);
        }
        $data['route'] = $route;
        return $data;
      }, array_keys($similarRoutes), $similarRoutes);
      // busca a rota atual por regex
      $similarRoutes = array_filter($similarRoutes, function ($data) {
        $route = str_replace('/', '\/', $data['route']);
        return preg_match("/^{$route}/", $this->routerAliases, $matches);
      });
      // pega os parametros da url se houverem
      $similarRoutes = array_map(function ($data) {
        $route = str_replace('/', '\/', $data['route']);
        preg_match("/^{$route}/", $this->routerAliases, $match);
        array_shift($match);
        $data['data'] = $match;
        return $data;
      }, $similarRoutes);
      // rota não encontrada
      if (!count($similarRoutes)) {
        require_once(__DIR__ . '/../../App/Views/Layouts/404.php');
        exit;
      }
      // pega a primeira
      $similarRoutes = current($similarRoutes);
      $route = $similarRoutes['route'];
      $data  = isset($similarRoutes['data'])? $similarRoutes['data']: [];
      // a rota atual é um regex
      if (isset($similarRoutes['realRoute'])) {
        $route = $similarRoutes['realRoute'];
      }
      $route = $this->allRouters[$route];
      // verifica tipo de rota
      if ($route['type'] != $_SERVER['REQUEST_METHOD']) {
        require_once(__DIR__ . '/../../App/Views/Layouts/405.php');
        exit;
      }
      $controller = $route['controller'];
      $method = $route['method'];
      //
      $this->instantiateController($controller, $method, $data);
  }

  protected function manipulateRouteRegex($route, $matches): string
  {
    foreach ($matches[0] as $regex) {
      $optional = false;
      if (preg_match('/\{(.*)\?\}/', $regex)) {
        $optional = true;
        $regex = str_replace('?}', '}', $regex);
      }
      $regexValue = isset($this->routeRegex[$regex])? $this->routeRegex[$regex]: '(.*)';
      $regexValue = $optional? "{$regexValue}?": $regexValue;
      $regex = $optional? str_replace('}', '?}', $regex): $regex;
      $route = str_replace($regex, "?{$regexValue}", $route);
    }
    return $route;
  }
}
