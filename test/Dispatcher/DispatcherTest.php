<?php

namespace FastRoute\Dispatcher;

use FastRoute\BadRouteException;
use FastRoute\RouteCollector;

abstract class DispatcherTest extends \PHPUnit_Framework_TestCase {

    /**
     * Delegate dispatcher selection to child test classes
     */
    abstract protected function getDispatcherClass();

    /**
     * Delegate dataGenerator selection to child test classes
     */
    abstract protected function getDataGeneratorClass();

    /**
     * Set appropriate options for the specific Dispatcher class we're testing
     */
    private function generateDispatcherOptions() {
        return [
            'dataGenerator' => $this->getDataGeneratorClass(),
            'dispatcher' => $this->getDispatcherClass()
        ];
    }

    /**
     * @dataProvider provideFoundDispatchCases
     */
    public function testFoundDispatches($method, $uri, $callback, $handler, $argDict) {
        $dispatcher = \FastRoute\simpleDispatcher($callback, $this->generateDispatcherOptions());
        list($routedStatus, $routedTo, $routedArgs) = $dispatcher->dispatch($method, $uri);
        $this->assertSame($dispatcher::FOUND, $routedStatus);
        $this->assertSame($handler, $routedTo);
        $this->assertSame($argDict, $routedArgs);
    }

    /**
     * @dataProvider provideNotFoundDispatchCases
     */
    public function testNotFoundDispatches($method, $uri, $callback) {
        $dispatcher = \FastRoute\simpleDispatcher($callback, $this->generateDispatcherOptions());
        $this->assertFalse(isset($routeInfo[1]),
            "NOT_FOUND result must only contain a single element in the returned info array"
        );
        list($routedStatus) = $dispatcher->dispatch($method, $uri);
        $this->assertSame($dispatcher::NOT_FOUND, $routedStatus);
    }

    /**
     * @dataProvider provideMethodNotAllowedDispatchCases
     */
    public function testMethodNotAllowedDispatches($method, $uri, $callback, $availableMethods) {
        $dispatcher = \FastRoute\simpleDispatcher($callback, $this->generateDispatcherOptions());
        $routeInfo = $dispatcher->dispatch($method, $uri);
        $this->assertTrue(isset($routeInfo[1]),
            "METHOD_NOT_ALLOWED result must return an array of allowed methods at index 1"
        );

        list($routedStatus, $methodArray) = $dispatcher->dispatch($method, $uri);
        $this->assertSame($dispatcher::METHOD_NOT_ALLOWED, $routedStatus);
        $this->assertSame($availableMethods, $methodArray);
    }

    /**
     * @expectedException \FastRoute\BadRouteException
     * @expectedExceptionMessage Cannot use the same placeholder "test" twice
     */
    public function testDuplicateVariableNameError() {
        \FastRoute\simpleDispatcher(function(RouteCollector $r) {
            $r->addRoute('GET', '/foo/{test}/{test:\d+}', 'handler0');
        }, $this->generateDispatcherOptions());
    }

    /**
     * @expectedException \FastRoute\BadRouteException
     * @expectedExceptionMessage Cannot register two routes matching "/user/([^/]+)" for method "GET"
     */
    public function testDuplicateVariableRoute() {
        \FastRoute\simpleDispatcher(function(RouteCollector $r) {
            $r->addRoute('GET', '/user/{id}', 'handler0'); // oops, forgot \d+ restriction ;)
            $r->addRoute('GET', '/user/{name}', 'handler1');
        }, $this->generateDispatcherOptions());
    }

    /**
     * @expectedException \FastRoute\BadRouteException
     * @expectedExceptionMessage Cannot register two routes matching "/user" for method "GET"
     */
    public function testDuplicateStaticRoute() {
        \FastRoute\simpleDispatcher(function(RouteCollector $r) {
            $r->addRoute('GET', '/user', 'handler0');
            $r->addRoute('GET', '/user', 'handler1');
        }, $this->generateDispatcherOptions());
    }

    /**
     * @expectedException \FastRoute\BadRouteException
     * @expectedExceptionMessage Static route "/user/nikic" is shadowed by previously defined variable route "/user/([^/]+)" for method "GET"
     */
    public function testShadowedStaticRoute() {
        \FastRoute\simpleDispatcher(function(RouteCollector $r) {
            $r->addRoute('GET', '/user/{name}', 'handler0');
            $r->addRoute('GET', '/user/nikic', 'handler1');
        }, $this->generateDispatcherOptions());
    }

    /**
     * @dataProvider provideUnsafePlaceholderRegexes
     */
    public function testUnsafePlaceholderRegexIsRejected($regex, $uri, $expectedMessage) {
        try {
            $dispatcher = \FastRoute\simpleDispatcher(function(RouteCollector $r) use ($regex) {
                $r->addRoute('GET', '/user/{id}/{action}', 'profile');
                $r->addRoute('GET', '/user/{id:' . $regex . '}', 'show');
                $r->addRoute('GET', '/user/{id}/delete', 'delete');
                $r->addRoute('GET', '/user/{id}/{action}/{extra}', 'admin');
            }, $this->generateDispatcherOptions());
        } catch (BadRouteException $e) {
            $this->assertSame(sprintf($expectedMessage, $regex), $e->getMessage());
            return;
        }

        $this->fail(sprintf(
            'Placeholder regex "%s" was accepted; GET %s dispatched as %s',
            $regex, $uri, json_encode(@$dispatcher->dispatch('GET', $uri))
        ));
    }

    public function provideUnsafePlaceholderRegexes() {
        $group = 'Regex "%s" for parameter "id" contains a capturing group';
        $accept = 'Regex "%s" for parameter "id" contains (*ACCEPT)';
        $invalid = 'Regex "%s" for parameter "id" is not a self-contained regular expression';
        return [
            ['(\d+)', '/user/10', $group],
            ['(\d+)-(\d+)', '/user/10-20', $group],
            ['(?<num>\d+)', '/user/10', $group],
            ['(?P<num>\d+)', '/user/10', $group],
            ["(?'num'\\d+)", '/user/10', $group],
            ['(?:(\d+))', '/user/10', $group],
            ['(?|(\d+)|(x))', '/user/10', $group],
            ['(?=(\d))\d+', '/user/10', $group],
            ['\Q[\E(\d+)]?', '/user/10', $group],
            ['\d+(*ACCEPT)', '/user/10', $accept],
            ['\d+(*ACCEPT:x)', '/user/10', $accept],
            ['\d+)|(?:zz', 'zz', $invalid],
            ['\d+\Q', '/user/10', $invalid],
            ['(?x)\d+#', '/user/10', $invalid],
        ];
    }

    public function testCapturingGroupInEarlierRouteDoesNotRunLaterHandler() {
        try {
            $dispatcher = \FastRoute\simpleDispatcher(function(RouteCollector $r) {
                $r->addRoute('GET', '/user/{id:(\d+)}', 'show');
                $r->addRoute('GET', '/user/{id}/delete', 'delete');
            }, $this->generateDispatcherOptions());
        } catch (BadRouteException $e) {
            $this->assertSame('Regex "(\d+)" for parameter "id" contains a capturing group', $e->getMessage());
            return;
        }

        $routeInfo = @$dispatcher->dispatch('GET', '/user/10');
        $this->assertSame([$dispatcher::FOUND, 'show', ['id' => '10']], array_slice($routeInfo, 0, 3));
    }

    public function testAcceptVerbInLaterRouteDoesNotRunEarlierHandler() {
        try {
            $dispatcher = \FastRoute\simpleDispatcher(function(RouteCollector $r) {
                $r->addRoute('GET', '/admin/{x}', 'admin');
                $r->addRoute('GET', '/user/{id:\d+(*ACCEPT)}', 'show');
            }, $this->generateDispatcherOptions());
        } catch (BadRouteException $e) {
            $this->assertSame('Regex "\d+(*ACCEPT)" for parameter "id" contains (*ACCEPT)', $e->getMessage());
            return;
        }

        $routeInfo = @$dispatcher->dispatch('GET', '/user/10');
        $this->assertSame([$dispatcher::FOUND, 'show', ['id' => '10']], array_slice($routeInfo, 0, 3));
    }

    public function testPlaceholderMatchingEmptyStringDoesNotRunLaterHandler() {
        $dispatcher = \FastRoute\simpleDispatcher(function(RouteCollector $r) {
            $r->addRoute('GET', '/user/{id:\d*}', 'show');
            $r->addRoute('GET', '/admin/{action}', 'admin');
        }, $this->generateDispatcherOptions());

        $routeInfo = @$dispatcher->dispatch('GET', '/user/');
        $this->assertSame([$dispatcher::FOUND, 'show', ['id' => '']], array_slice($routeInfo, 0, 3));
    }

    /**
     * @dataProvider provideSafePlaceholderRegexes
     */
    public function testPlaceholderRegexWithoutCapturingGroupsDispatches($regex, $uri, $id) {
        $dispatcher = \FastRoute\simpleDispatcher(function(RouteCollector $r) use ($regex) {
            $r->addRoute('GET', '/user/{id:' . $regex . '}', 'show');
            $r->addRoute('GET', '/user/{id}/delete', 'delete');
        }, $this->generateDispatcherOptions());

        $routeInfo = $dispatcher->dispatch('GET', $uri);
        $this->assertSame([$dispatcher::FOUND, 'show', ['id' => $id]], array_slice($routeInfo, 0, 3));

        $routeInfo = $dispatcher->dispatch('GET', '/user/10/delete');
        $this->assertSame([$dispatcher::FOUND, 'delete', ['id' => '10']], array_slice($routeInfo, 0, 3));
    }

    public function provideSafePlaceholderRegexes() {
        return [
            ['(?:\d+)', '/user/10', '10'],
            ['(?>\d+)', '/user/10', '10'],
            ['(?i:ab)c', '/user/ABc', 'ABc'],
            ['(?=\d)\w+', '/user/1a', '1a'],
            ['\(\d+\)', '/user/(10)', '(10)'],
            ['[()0-9]+', '/user/(10)', '(10)'],
            ['\Q(\E\d+', '/user/(10', '(10'],
            ['\d+(?#comment)', '/user/10', '10'],
        ];
    }

    public function provideFoundDispatchCases() {
        $cases = [];

        // 0 -------------------------------------------------------------------------------------->

        $callback = function(RouteCollector $r) {
            $r->addRoute('GET', '/resource/123/456', 'handler0');
        };

        $method = 'GET';
        $uri = '/resource/123/456';
        $handler = 'handler0';
        $argDict = [];

        $cases[] = [$method, $uri, $callback, $handler, $argDict];

        // 1 -------------------------------------------------------------------------------------->

        $callback = function(RouteCollector $r) {
            $r->addRoute('GET', '/handler0', 'handler0');
            $r->addRoute('GET', '/handler1', 'handler1');
            $r->addRoute('GET', '/handler2', 'handler2');
        };

        $method = 'GET';
        $uri = '/handler2';
        $handler = 'handler2';
        $argDict = [];

        $cases[] = [$method, $uri, $callback, $handler, $argDict];

        // 2 -------------------------------------------------------------------------------------->

        $callback = function(RouteCollector $r) {
            $r->addRoute('GET', '/user/{name}/{id:[0-9]+}', 'handler0');
            $r->addRoute('GET', '/user/{id:[0-9]+}', 'handler1');
            $r->addRoute('GET', '/user/{name}', 'handler2');
        };

        $method = 'GET';
        $uri = '/user/rdlowrey';
        $handler = 'handler2';
        $argDict = ['name' => 'rdlowrey'];

        $cases[] = [$method, $uri, $callback, $handler, $argDict];

        // 3 -------------------------------------------------------------------------------------->

        // reuse $callback from #2

        $method = 'GET';
        $uri = '/user/12345';
        $handler = 'handler1';
        $argDict = ['id' => '12345'];

        $cases[] = [$method, $uri, $callback, $handler, $argDict];

        // 4 -------------------------------------------------------------------------------------->

        // reuse $callback from #3

        $method = 'GET';
        $uri = '/user/NaN';
        $handler = 'handler2';
        $argDict = ['name' => 'NaN'];

        $cases[] = [$method, $uri, $callback, $handler, $argDict];

        // 5 -------------------------------------------------------------------------------------->

        // reuse $callback from #4

        $method = 'GET';
        $uri = '/user/rdlowrey/12345';
        $handler = 'handler0';
        $argDict = ['name' => 'rdlowrey', 'id' => '12345'];

        $cases[] = [$method, $uri, $callback, $handler, $argDict];

        // 6 -------------------------------------------------------------------------------------->

        $callback = function(RouteCollector $r) {
            $r->addRoute('GET', '/user/{id:[0-9]+}', 'handler0');
            $r->addRoute('GET', '/user/12345/extension', 'handler1');
            $r->addRoute('GET', '/user/{id:[0-9]+}.{extension}', 'handler2');

        };

        $method = 'GET';
        $uri = '/user/12345.svg';
        $handler = 'handler2';
        $argDict = ['id' => '12345', 'extension' => 'svg'];

        $cases[] = [$method, $uri, $callback, $handler, $argDict];

        // 7 ----- Test GET method fallback on HEAD route miss ------------------------------------>

        $callback = function(RouteCollector $r) {
            $r->addRoute('GET', '/user/{name}', 'handler0');
            $r->addRoute('GET', '/user/{name}/{id:[0-9]+}', 'handler1');
            $r->addRoute('GET', '/static0', 'handler2');
            $r->addRoute('GET', '/static1', 'handler3');
            $r->addRoute('HEAD', '/static1', 'handler4');
        };

        $method = 'HEAD';
        $uri = '/user/rdlowrey';
        $handler = 'handler0';
        $argDict = ['name' => 'rdlowrey'];

        $cases[] = [$method, $uri, $callback, $handler, $argDict];

        // 8 ----- Test GET method fallback on HEAD route miss ------------------------------------>

        // reuse $callback from #7

        $method = 'HEAD';
        $uri = '/user/rdlowrey/1234';
        $handler = 'handler1';
        $argDict = ['name' => 'rdlowrey', 'id' => '1234'];

        $cases[] = [$method, $uri, $callback, $handler, $argDict];

        // 9 ----- Test GET method fallback on HEAD route miss ------------------------------------>

        // reuse $callback from #8

        $method = 'HEAD';
        $uri = '/static0';
        $handler = 'handler2';
        $argDict = [];

        $cases[] = [$method, $uri, $callback, $handler, $argDict];

        // 10 ---- Test existing HEAD route used if available (no fallback) ----------------------->

        // reuse $callback from #9

        $method = 'HEAD';
        $uri = '/static1';
        $handler = 'handler4';
        $argDict = [];

        $cases[] = [$method, $uri, $callback, $handler, $argDict];

        // 11 ---- More specified routes are not shadowed by less specific of another method ------>

        $callback = function(RouteCollector $r) {
            $r->addRoute('GET', '/user/{name}', 'handler0');
            $r->addRoute('POST', '/user/{name:[a-z]+}', 'handler1');
        };

        $method = 'POST';
        $uri = '/user/rdlowrey';
        $handler = 'handler1';
        $argDict = ['name' => 'rdlowrey'];

        $cases[] = [$method, $uri, $callback, $handler, $argDict];

        // 12 ---- Handler of more specific routes is used, if it occurs first -------------------->

        $callback = function(RouteCollector $r) {
            $r->addRoute('GET', '/user/{name}', 'handler0');
            $r->addRoute('POST', '/user/{name:[a-z]+}', 'handler1');
            $r->addRoute('POST', '/user/{name}', 'handler2');
        };

        $method = 'POST';
        $uri = '/user/rdlowrey';
        $handler = 'handler1';
        $argDict = ['name' => 'rdlowrey'];

        $cases[] = [$method, $uri, $callback, $handler, $argDict];

        // 13 ---- Route with constant suffix ----------------------------------------------------->

        $callback = function(RouteCollector $r) {
            $r->addRoute('GET', '/user/{name}', 'handler0');
            $r->addRoute('GET', '/user/{name}/edit', 'handler1');
        };

        $method = 'GET';
        $uri = '/user/rdlowrey/edit';
        $handler = 'handler1';
        $argDict = ['name' => 'rdlowrey'];

        $cases[] = [$method, $uri, $callback, $handler, $argDict];

        // x -------------------------------------------------------------------------------------->

        return $cases;
    }

    public function provideNotFoundDispatchCases() {
        $cases = [];

        // 0 -------------------------------------------------------------------------------------->

        $callback = function(RouteCollector $r) {
            $r->addRoute('GET', '/resource/123/456', 'handler0');
        };

        $method = 'GET';
        $uri = '/not-found';

        $cases[] = [$method, $uri, $callback];

        // 1 -------------------------------------------------------------------------------------->

        // reuse callback from #0
        $method = 'POST';
        $uri = '/not-found';

        $cases[] = [$method, $uri, $callback];

        // 2 -------------------------------------------------------------------------------------->

        // reuse callback from #1
        $method = 'PUT';
        $uri = '/not-found';

        $cases[] = [$method, $uri, $callback];

        // 3 -------------------------------------------------------------------------------------->

        $callback = function(RouteCollector $r) {
            $r->addRoute('GET', '/handler0', 'handler0');
            $r->addRoute('GET', '/handler1', 'handler1');
            $r->addRoute('GET', '/handler2', 'handler2');
        };

        $method = 'GET';
        $uri = '/not-found';

        $cases[] = [$method, $uri, $callback];

        // 4 -------------------------------------------------------------------------------------->

        $callback = function(RouteCollector $r) {
            $r->addRoute('GET', '/user/{name}/{id:[0-9]+}', 'handler0');
            $r->addRoute('GET', '/user/{id:[0-9]+}', 'handler1');
            $r->addRoute('GET', '/user/{name}', 'handler2');
        };

        $method = 'GET';
        $uri = '/not-found';

        $cases[] = [$method, $uri, $callback];

        // 5 -------------------------------------------------------------------------------------->

        // reuse callback from #4
        $method = 'GET';
        $uri = '/user/rdlowrey/12345/not-found';

        $cases[] = [$method, $uri, $callback];

        // 6 -------------------------------------------------------------------------------------->

        // reuse callback from #5
        $method = 'HEAD';

        $cases[] = array($method, $uri, $callback);

        // x -------------------------------------------------------------------------------------->

        return $cases;
    }

    public function provideMethodNotAllowedDispatchCases() {
        $cases = [];

        // 0 -------------------------------------------------------------------------------------->

        $callback = function(RouteCollector $r) {
            $r->addRoute('GET', '/resource/123/456', 'handler0');
        };

        $method = 'POST';
        $uri = '/resource/123/456';
        $allowedMethods = ['GET'];

        $cases[] = [$method, $uri, $callback, $allowedMethods];

        // 1 -------------------------------------------------------------------------------------->

        $callback = function(RouteCollector $r) {
            $r->addRoute('GET', '/resource/123/456', 'handler0');
            $r->addRoute('POST', '/resource/123/456', 'handler1');
            $r->addRoute('PUT', '/resource/123/456', 'handler2');
        };

        $method = 'DELETE';
        $uri = '/resource/123/456';
        $allowedMethods = ['GET', 'POST', 'PUT'];

        $cases[] = [$method, $uri, $callback, $allowedMethods];

        // 2 -------------------------------------------------------------------------------------->

        $callback = function(RouteCollector $r) {
            $r->addRoute('GET', '/user/{name}/{id:[0-9]+}', 'handler0');
            $r->addRoute('POST', '/user/{name}/{id:[0-9]+}', 'handler1');
            $r->addRoute('PUT', '/user/{name}/{id:[0-9]+}', 'handler2');
            $r->addRoute('PATCH', '/user/{name}/{id:[0-9]+}', 'handler3');
        };

        $method = 'DELETE';
        $uri = '/user/rdlowrey/42';
        $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH'];

        $cases[] = [$method, $uri, $callback, $allowedMethods];

        // 3 -------------------------------------------------------------------------------------->

        $callback = function(RouteCollector $r) {
            $r->addRoute('POST', '/user/{name}', 'handler1');
            $r->addRoute('PUT', '/user/{name:[a-z]+}', 'handler2');
            $r->addRoute('PATCH', '/user/{name:[a-z]+}', 'handler3');
        };

        $method = 'GET';
        $uri = '/user/rdlowrey';
        $allowedMethods = ['POST', 'PUT', 'PATCH'];

        $cases[] = [$method, $uri, $callback, $allowedMethods];

        // x -------------------------------------------------------------------------------------->

        return $cases;
    }

}
