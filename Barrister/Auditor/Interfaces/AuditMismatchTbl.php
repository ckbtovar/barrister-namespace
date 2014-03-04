<?php namespace Barrister\Auditor\Interfaces;

interface AuditMismatchTbl {
    /**
     * Inserts the executed query, bound parameters, and the barristerId into the mismatch table
     *
     * @param $barristerId
     * @param $query
     * @param $params
     *
     * @return mixed
     */
    public function insertQueryProfile($barristerId, $query, $params);
}
