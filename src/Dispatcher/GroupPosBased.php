<?php

namespace FastRoute\Dispatcher;

class GroupPosBased extends RegexBasedAbstract {
    public function __construct($data) {
        list($this->staticRouteMap, $this->variableRouteData) = $data;
    }

    protected function dispatchVariableRoute($routeData, $uri) {
        foreach ($routeData as $data) {
            if (!preg_match($data['regex'], $uri, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            // A placeholder may legitimately match an empty string, so the
            // matched route is the one whose first group participated in the
            // match (offset other than -1), not the first non-empty group.
            foreach ($data['routeMap'] as $i => $route) {
                if (!isset($matches[$i]) || -1 === $matches[$i][1]) {
                    continue;
                }

                list($handler, $varNames) = $route;

                $vars = [];
                foreach ($varNames as $varName) {
                    $vars[$varName] = $matches[$i++][0];
                }
                return [self::FOUND, $handler, $vars, $uri];
            }
        }

        return [self::NOT_FOUND];
    }
}
