<?php

namespace FastRoute\DataGenerator;

use FastRoute\DataGenerator;
use FastRoute\BadRouteException;
use FastRoute\Route;

abstract class RegexBasedAbstract implements DataGenerator {
    protected $staticRoutes = [];
    protected $methodToRegexToRoutesMap = [];

    protected abstract function getApproxChunkSize();
    protected abstract function processChunk($regexToRoutesMap);

    public function addRoute($httpMethod, $routeData, $handler) {
        if ($this->isStaticRoute($routeData)) {
            $this->addStaticRoute($httpMethod, $routeData, $handler);
        } else {
            $this->addVariableRoute($httpMethod, $routeData, $handler);
        }
    }

    public function getData() {
        if (empty($this->methodToRegexToRoutesMap)) {
            return [$this->staticRoutes, []];
        }

        return [$this->staticRoutes, $this->generateVariableRouteData()];
    }

    private function generateVariableRouteData() {
        $data = [];
        foreach ($this->methodToRegexToRoutesMap as $method => $regexToRoutesMap) {
            $chunkSize = $this->computeChunkSize(count($regexToRoutesMap));
            $chunks = array_chunk($regexToRoutesMap, $chunkSize, true);
            $data[$method] =  array_map([$this, 'processChunk'], $chunks);
        }
        return $data;
    }

    private function computeChunkSize($count) {
        $numParts = max(1, round($count / $this->getApproxChunkSize()));
        return ceil($count / $numParts);
    }

    private function isStaticRoute($routeData) {
        return count($routeData) == 1 && is_string($routeData[0]);
    }

    private function addStaticRoute($httpMethod, $routeData, $handler) {
        $routeStr = $routeData[0];

        if (isset($this->staticRoutes[$routeStr][$httpMethod])) {
            throw new BadRouteException(sprintf(
                'Cannot register two routes matching "%s" for method "%s"',
                $routeStr, $httpMethod
            ));
        }

        if (isset($this->methodToRegexToRoutesMap[$httpMethod])) {
            foreach ($this->methodToRegexToRoutesMap[$httpMethod] as $route) {
                if ($route->matches($routeStr)) {
                    throw new BadRouteException(sprintf(
                        'Static route "%s" is shadowed by previously defined variable route "%s" for method "%s"',
                        $routeStr, $route->regex, $httpMethod
                    ));
                }
            }
        }

        $this->staticRoutes[$routeStr][$httpMethod] = $handler;
    }

    private function addVariableRoute($httpMethod, $routeData, $handler) {
        list($regex, $variables) = $this->buildRegexForRoute($routeData);

        if (isset($this->methodToRegexToRoutesMap[$httpMethod][$regex])) {
            throw new BadRouteException(sprintf(
                'Cannot register two routes matching "%s" for method "%s"',
                $regex, $httpMethod
            ));
        }

        $this->methodToRegexToRoutesMap[$httpMethod][$regex] = new Route(
            $httpMethod, $handler, $regex, $variables
        );
    }

    private function buildRegexForRoute($routeData) {
        $regex = '';
        $variables = [];
        foreach ($routeData as $part) {
            if (is_string($part)) {
                $regex .= preg_quote($part, '~');
                continue;
            }

            list($varName, $regexPart) = $part;

            if (isset($variables[$varName])) {
                throw new BadRouteException(sprintf(
                    'Cannot use the same placeholder "%s" twice', $varName
                ));
            }

            $this->validatePlaceholderRegex($varName, $regexPart);

            $variables[$varName] = $varName;
            $regex .= '(' . $regexPart . ')';
        }

        return [$regex, $variables];
    }

    /**
     * The dispatchers identify the matched route and its arguments by the position
     * and number of capturing groups, so a placeholder regex must contribute nothing
     * but the single group it is wrapped in.
     */
    private function validatePlaceholderRegex($varName, $regexPart) {
        // (*ACCEPT) ends the match before the groups and marks that identify the route
        if (false !== strpos($regexPart, '(*ACCEPT')) {
            throw new BadRouteException(sprintf(
                'Regex "%s" for parameter "%s" contains (*ACCEPT)', $regexPart, $varName
            ));
        }

        // Compiling it alone rejects unbalanced ")" that would close the wrapping group;
        // compiling it in a group rejects a trailing "\", "\Q" or comment that would swallow it
        if (!$this->regexCompiles($regexPart) || !$this->regexCompiles('(?:' . $regexPart . ')')) {
            throw new BadRouteException(sprintf(
                'Regex "%s" for parameter "%s" is not a self-contained regular expression',
                $regexPart, $varName
            ));
        }

        // A backreference to group 1 only compiles if a capturing group (numbered, named
        // or inside a branch reset) exists, which PCRE determines more reliably than parsing
        if ($this->regexCompiles('(?:' . $regexPart . ')\g{1}')) {
            throw new BadRouteException(sprintf(
                'Regex "%s" for parameter "%s" contains a capturing group', $regexPart, $varName
            ));
        }
    }

    private function regexCompiles($regex) {
        // The leading (?!) fails immediately, so the pattern is compiled but never executed
        return false !== @preg_match('~(?!)' . $regex . '~', '');
    }
}
