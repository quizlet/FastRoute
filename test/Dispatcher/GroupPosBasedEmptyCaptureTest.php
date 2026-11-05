<?php

namespace FastRoute\Dispatcher;

use FastRoute\RouteCollector;

require_once __DIR__ . '/../bootstrap.php';

/**
 * GroupPosBased must keep an empty placeholder on the route that matched it.
 *
 * Run:
 *   php test/Dispatcher/GroupPosBasedEmptyCaptureTest.php
 *
 * The cases below fail while an empty capture is treated as "not this route".
 */
class GroupPosBasedEmptyCaptureTest
{
    private $failures = 0;

    public function run()
    {
        $this->testSingleRouteEmptyStarReturnsRegisteredHandler();
        $this->testCallerReceivesEmptyCaptureFromRegisteredHandler();
        $this->testEmptyStarDoesNotSelectFollowingRoute();
        $this->testEmptyStarAfterAnotherRouteDoesNotSelectEitherNeighbor();
        $this->testLeadingEmptyCaptureKeepsLaterPlaceholders();
        $this->testEveryCaptureEmptyStaysOnThatRoute();
        $this->testEveryCaptureEmptyDoesNotSelectFollowingRoute();
        $this->testOptionalSingleCharacterQuantifierMatchesEmpty();
        $this->testEmptyCaptureBetweenLiterals();
        $this->testHeadFallbackReturnsGetHandlerForEmptyCapture();
        $this->testHeadFallbackDoesNotSelectFollowingRoute();
        $this->testPostRouteEmptyCaptureReturnsItsOwnHandler();
        $this->testNonEmptyCaptureOnTheSameRoutes();
        $this->testEmptyCaptureAfterNonEmptyCapture();
        $this->testWrongMethodDoesNotReturnFound();

        return $this->failures;
    }

    private function testSingleRouteEmptyStarReturnsRegisteredHandler()
    {
        $this->assertDispatch(
            'single route {id:[0-9]*} GET /api/',
            array(array('GET', '/api/{id:[0-9]*}', 'show')),
            'GET',
            '/api/',
            \FastRoute\Dispatcher::FOUND,
            'show',
            array('id' => '')
        );
    }

    private function testCallerReceivesEmptyCaptureFromRegisteredHandler()
    {
        $show = function (array $vars) {
            return array('called' => 'show', 'vars' => $vars);
        };
        $routeInfo = $this->dispatch(
            array(array('GET', '/api/{id:[0-9]*}', $show)),
            'GET',
            '/api/'
        );
        $name = 'README caller invokes the handler for GET /api/';
        $produced = null;
        try {
            switch ($routeInfo[0]) {
                case \FastRoute\Dispatcher::FOUND:
                    $handler = $routeInfo[1];
                    $vars = $routeInfo[2];
                    $produced = $handler($vars);
                    break;
                default:
                    $this->fail($name, 'dispatch did not return FOUND', $routeInfo);
                    return;
            }
        } catch (\Exception $e) {
            $this->fail($name, 'calling the handler threw ' . $e->getMessage(), $routeInfo);
            return;
        } catch (\Error $e) {
            $this->fail($name, 'calling the handler threw ' . $e->getMessage(), $routeInfo);
            return;
        }
        $expected = array('called' => 'show', 'vars' => array('id' => ''));
        if ($produced !== $expected || $routeInfo[1] !== $show) {
            $this->fail($name, 'caller got ' . var_export($produced, true), $routeInfo);
            return;
        }
        $this->pass($name);
    }

    private function testEmptyStarDoesNotSelectFollowingRoute()
    {
        $show = function (array $vars) {
            return array('called' => 'show', 'vars' => $vars);
        };
        $other = function (array $vars) {
            return array('called' => 'other', 'vars' => $vars);
        };
        $routeInfo = $this->dispatch(
            array(
                array('GET', '/api/{id:[0-9]*}', $show),
                array('GET', '/other/{name}', $other),
            ),
            'GET',
            '/api/'
        );
        $name = 'empty star must not select the following variable route';
        try {
            if ($routeInfo[0] !== \FastRoute\Dispatcher::FOUND) {
                $this->fail($name, 'dispatch did not return FOUND', $routeInfo);
                return;
            }
            $produced = $routeInfo[1]($routeInfo[2]);
        } catch (\Exception $e) {
            $this->fail($name, 'calling the handler threw ' . $e->getMessage(), $routeInfo);
            return;
        } catch (\Error $e) {
            $this->fail($name, 'calling the handler threw ' . $e->getMessage(), $routeInfo);
            return;
        }
        $expected = array('called' => 'show', 'vars' => array('id' => ''));
        if ($produced !== $expected || $routeInfo[1] !== $show) {
            $this->fail($name, 'caller got ' . var_export($produced, true), $routeInfo);
            return;
        }
        $this->pass($name);
    }

    private function testEmptyStarAfterAnotherRouteDoesNotSelectEitherNeighbor()
    {
        $this->assertDispatch(
            'empty star in a later route keeps that route handler',
            array(
                array('GET', '/user/{name}', 'user'),
                array('GET', '/api/{id:[0-9]*}', 'show'),
                array('GET', '/tail/{name}', 'tail'),
            ),
            'GET',
            '/api/',
            \FastRoute\Dispatcher::FOUND,
            'show',
            array('id' => '')
        );
    }

    private function testLeadingEmptyCaptureKeepsLaterPlaceholders()
    {
        $this->assertDispatch(
            'leading empty capture still binds the rest of the route',
            array(
                array('GET', '/api/{id:[0-9]*}/{name}', 'show'),
                array('GET', '/other/{slug}', 'other'),
            ),
            'GET',
            '/api//zoe',
            \FastRoute\Dispatcher::FOUND,
            'show',
            array('id' => '', 'name' => 'zoe')
        );
    }

    private function testEveryCaptureEmptyStaysOnThatRoute()
    {
        $this->assertDispatch(
            'route whose placeholders are all empty',
            array(array('GET', '/api/{a:[0-9]*}/{b:[0-9]*}', 'show')),
            'GET',
            '/api//',
            \FastRoute\Dispatcher::FOUND,
            'show',
            array('a' => '', 'b' => '')
        );
    }

    private function testEveryCaptureEmptyDoesNotSelectFollowingRoute()
    {
        $this->assertDispatch(
            'all-empty captures must not select the following route',
            array(
                array('GET', '/api/{a:[0-9]*}/{b:[0-9]*}', 'show'),
                array('GET', '/other/{name}', 'other'),
            ),
            'GET',
            '/api//',
            \FastRoute\Dispatcher::FOUND,
            'show',
            array('a' => '', 'b' => '')
        );
    }

    private function testOptionalSingleCharacterQuantifierMatchesEmpty()
    {
        $this->assertDispatch(
            'optional {id:[0-9]?} matches an empty capture',
            array(
                array('GET', '/api/{id:[0-9]?}', 'show'),
                array('GET', '/other/{name}', 'other'),
            ),
            'GET',
            '/api/',
            \FastRoute\Dispatcher::FOUND,
            'show',
            array('id' => '')
        );
    }

    private function testEmptyCaptureBetweenLiterals()
    {
        $this->assertDispatch(
            'empty capture between literals',
            array(array('GET', '/pre/{id:[0-9]*}/post', 'show')),
            'GET',
            '/pre//post',
            \FastRoute\Dispatcher::FOUND,
            'show',
            array('id' => '')
        );
    }

    private function testHeadFallbackReturnsGetHandlerForEmptyCapture()
    {
        $this->assertDispatch(
            'HEAD falls back to the GET handler for an empty capture',
            array(array('GET', '/api/{id:[0-9]*}', 'show')),
            'HEAD',
            '/api/',
            \FastRoute\Dispatcher::FOUND,
            'show',
            array('id' => '')
        );
    }

    private function testHeadFallbackDoesNotSelectFollowingRoute()
    {
        $this->assertDispatch(
            'HEAD fallback must not select the following variable route',
            array(
                array('GET', '/api/{id:[0-9]*}', 'show'),
                array('GET', '/other/{name}', 'other'),
            ),
            'HEAD',
            '/api/',
            \FastRoute\Dispatcher::FOUND,
            'show',
            array('id' => '')
        );
    }

    private function testPostRouteEmptyCaptureReturnsItsOwnHandler()
    {
        $this->assertDispatch(
            'POST route with an empty capture',
            array(array('POST', '/api/{id:[0-9]*}', 'show')),
            'POST',
            '/api/',
            \FastRoute\Dispatcher::FOUND,
            'show',
            array('id' => '')
        );
    }

    private function testNonEmptyCaptureOnTheSameRoutes()
    {
        $this->assertDispatch(
            'non-empty {id:[0-9]*} still returns its own handler',
            array(
                array('GET', '/api/{id:[0-9]*}', 'show'),
                array('GET', '/other/{name}', 'other'),
            ),
            'GET',
            '/api/42',
            \FastRoute\Dispatcher::FOUND,
            'show',
            array('id' => '42')
        );
        $this->assertDispatch(
            'non-empty optional quantifier still returns its capture',
            array(array('GET', '/api/{id:[0-9]?}', 'show')),
            'GET',
            '/api/7',
            \FastRoute\Dispatcher::FOUND,
            'show',
            array('id' => '7')
        );
    }

    private function testEmptyCaptureAfterNonEmptyCapture()
    {
        $this->assertDispatch(
            'empty capture after a non-empty placeholder stays on that route',
            array(
                array('GET', '/user/{name}/{id:[0-9]*}', 'show'),
                array('GET', '/other/{slug}', 'other'),
            ),
            'GET',
            '/user/ada/',
            \FastRoute\Dispatcher::FOUND,
            'show',
            array('name' => 'ada', 'id' => '')
        );
    }

    private function testWrongMethodDoesNotReturnFound()
    {
        $this->assertDispatch(
            'POST against a GET-only empty-capture route is method not allowed',
            array(
                array('GET', '/api/{id:[0-9]*}', 'show'),
                array('GET', '/other/{name}', 'other'),
            ),
            'POST',
            '/api/',
            \FastRoute\Dispatcher::METHOD_NOT_ALLOWED,
            array('GET'),
            null
        );
    }

    private function assertDispatch($name, array $routes, $method, $uri, $status, $handlerOrAllowed, $vars)
    {
        $info = $this->dispatch($routes, $method, $uri);
        if (!is_array($info) || !array_key_exists(0, $info)) {
            $this->fail($name, 'dispatch did not return a status array', $info);
            return;
        }
        if ($info[0] !== $status) {
            $this->fail($name, 'status ' . var_export($info[0], true) . ' !== ' . var_export($status, true), $info);
            return;
        }
        if ($status === \FastRoute\Dispatcher::METHOD_NOT_ALLOWED) {
            $allowed = array_key_exists(1, $info) ? $info[1] : null;
            if ($allowed !== $handlerOrAllowed) {
                $this->fail($name, 'allowed methods ' . var_export($allowed, true), $info);
                return;
            }
            $this->pass($name);
            return;
        }
        if (!$this->assertFoundShape($name, $info, $handlerOrAllowed, $vars)) {
            return;
        }
        $this->pass($name);
    }

    private function assertFoundShape($name, $info, $handler, array $vars)
    {
        if (!is_array($info) || !array_key_exists(1, $info) || !array_key_exists(2, $info)) {
            $this->fail($name, 'FOUND result is missing the handler or vars', $info);
            return false;
        }
        if ($info[0] !== \FastRoute\Dispatcher::FOUND) {
            $this->fail($name, 'status ' . var_export($info[0], true), $info);
            return false;
        }
        if ($info[1] !== $handler) {
            $this->fail($name, 'handler ' . $this->exportHandler($info[1]), $info);
            return false;
        }
        if ($info[2] !== $vars) {
            $this->fail($name, 'vars ' . var_export($info[2], true), $info);
            return false;
        }
        return true;
    }

    private function dispatch(array $routes, $method, $uri)
    {
        $dispatcher = \FastRoute\simpleDispatcher(function (RouteCollector $r) use ($routes) {
            foreach ($routes as $route) {
                $r->addRoute($route[0], $route[1], $route[2]);
            }
        }, array(
            'routeParser' => 'FastRoute\\RouteParser\\Std',
            'dataGenerator' => 'FastRoute\\DataGenerator\\GroupPosBased',
            'dispatcher' => 'FastRoute\\Dispatcher\\GroupPosBased',
        ));

        return $dispatcher->dispatch($method, $uri);
    }

    private function exportHandler($handler)
    {
        if (is_object($handler)) {
            return 'object(' . get_class($handler) . ')';
        }
        return var_export($handler, true);
    }

    private function pass($name)
    {
        echo "PASS  $name\n";
    }

    private function fail($name, $detail, $info)
    {
        $this->failures++;
        echo "FAIL  $name\n      $detail\n      result " . var_export($info, true) . "\n";
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__) {
    $failures = (new GroupPosBasedEmptyCaptureTest())->run();
    echo $failures === 0 ? "\nAll cases passed.\n" : "\n$failures case(s) failed.\n";
    exit($failures === 0 ? 0 : 1);
}
