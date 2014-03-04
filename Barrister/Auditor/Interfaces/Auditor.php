<?php namespace Barrister\Auditor\Interfaces;


use ReflectionMethod;

interface Auditor {
    /**
     * Audits the current service method and returns the result of the method
     *
     * @param ReflectionMethod $method
     * @param $handler
     * @param $params
     * @param $barristerId
     *
     * @return mixed
     */
    public function audit(ReflectionMethod $method, $handler, $params, $barristerId);

    /**
     * Used to determine if the current configuration is in audit mode or not
     *
     * @return bool
     */
    public function isInAuditMode();
} 
