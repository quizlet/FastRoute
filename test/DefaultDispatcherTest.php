<?php

namespace FastRoute;

class DefaultDispatcherTest extends \PHPUnit_Framework_TestCase {
    private $cacheFile;

    public function defineRoutes(RouteCollector $r) {
        $r->addRoute('GET', '/user/{id:(\d+)}', 'show');
        $r->addRoute('GET', '/user/{id}/delete', 'delete');
    }

    public function testSimpleDispatcherRejectsCapturingGroupBeforeDispatching() {
        try {
            $dispatcher = simpleDispatcher([$this, 'defineRoutes']);
        } catch (BadRouteException $e) {
            $this->assertSame('Regex "(\d+)" for parameter "id" contains a capturing group', $e->getMessage());
            return;
        }

        $this->fail('Route was accepted; GET /user/10 dispatched as ' . json_encode(@$dispatcher->dispatch('GET', '/user/10')));
    }

    public function testCachedDispatcherRejectsCapturingGroupWithoutWritingCache() {
        $this->cacheFile = sys_get_temp_dir() . '/fastroute-test-' . uniqid() . '.php';

        try {
            $dispatcher = cachedDispatcher([$this, 'defineRoutes'], ['cacheFile' => $this->cacheFile]);
        } catch (BadRouteException $e) {
            $this->assertFalse(file_exists($this->cacheFile), 'Cache file was written for a rejected route');
            return;
        }

        $this->fail('Route was accepted; GET /user/10 dispatched as ' . json_encode(@$dispatcher->dispatch('GET', '/user/10')));
    }

    protected function tearDown() {
        if ($this->cacheFile !== null && file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }
}
