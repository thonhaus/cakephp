<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Controller;

use Cake\Core\App;
use Cake\Http\ControllerFactoryInterface;
use Cake\Http\Exception\MissingControllerException;
use Cake\Http\ServerRequest;
use Cake\Utility\Inflector;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;

/**
 * Factory method for building controllers for request.
 *
 * @implements \Cake\Http\ControllerFactoryInterface<\Cake\Controller\Controller>
 */
class ControllerFactory implements ControllerFactoryInterface
{
    /**
     * Create a controller for a given request.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request to build a controller for.
     * @return \Cake\Controller\Controller
     * @throws \Cake\Http\Exception\MissingControllerException
     * @throws \ReflectionException
     */
    public function create(ServerRequestInterface $request): Controller
    {
        $className = $this->getControllerClass($request);
        if (!$className) {
            $this->missingController($request);
        }
        /** @var string $className */
        $reflection = new ReflectionClass($className);
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            $this->missingController($request);
        }

        /** @var \Cake\Controller\Controller $controller */
        $controller = $reflection->newInstance($request);

        return $controller;
    }

    /**
     * Invoke a controller's action and wrapping methods.
     *
     * @param mixed $controller The controller to invoke.
     * @return \Psr\Http\Message\ResponseInterface The response
     * @throws \Cake\Controller\Exception\MissingActionException If controller action is not found.
     * @throws \UnexpectedValueException If return value of action method is not null or ResponseInterface instance.
     * @psalm-param \Cake\Controller\Controller $controller
     */
    public function invoke($controller): ResponseInterface
    {
        $result = $controller->startupProcess();
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        $action = $controller->getAction();
        $args = array_values($controller->getRequest()->getParam('pass'));
        $controller->invokeAction($action, $args);

        $result = $controller->shutdownProcess();
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        return $controller->getResponse();
    }

    /**
     * Determine the controller class name based on current request and controller param
     *
     * @param \Cake\Http\ServerRequest $request The request to build a controller for.
     * @return string|null
     */
    public function getControllerClass(ServerRequest $request): ?string
    {
        $pluginPath = $controller = '';
        $namespace = 'Controller';
        if ($request->getParam('controller')) {
            $controller = $request->getParam('controller');
        }
        if ($request->getParam('plugin')) {
            $pluginPath = $request->getParam('plugin') . '.';
        }
        if ($request->getParam('prefix')) {
            if (strpos($request->getParam('prefix'), '/') === false) {
                $namespace .= '/' . Inflector::camelize($request->getParam('prefix'));
            } else {
                $prefixes = array_map(
                    'Cake\Utility\Inflector::camelize',
                    explode('/', $request->getParam('prefix'))
                );
                $namespace .= '/' . implode('/', $prefixes);
            }
        }
        $firstChar = substr($controller, 0, 1);

        // Disallow plugin short forms, / and \\ from
        // controller names as they allow direct references to
        // be created.
        if (
            strpos($controller, '\\') !== false ||
            strpos($controller, '/') !== false ||
            strpos($controller, '.') !== false ||
            $firstChar === strtolower($firstChar)
        ) {
            $this->missingController($request);
        }

        return App::className($pluginPath . $controller, $namespace, 'Controller');
    }

    /**
     * Throws an exception when a controller is missing.
     *
     * @param \Cake\Http\ServerRequest $request The request.
     * @throws \Cake\Http\Exception\MissingControllerException
     * @return void
     */
    protected function missingController(ServerRequest $request): void
    {
        throw new MissingControllerException([
            'class' => $request->getParam('controller'),
            'plugin' => $request->getParam('plugin'),
            'prefix' => $request->getParam('prefix'),
            '_ext' => $request->getParam('_ext'),
        ]);
    }
}