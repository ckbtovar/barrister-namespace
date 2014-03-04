<?php namespace Barrister\Auditor;

use Barrister\Auditor\Interfaces\AuditMismatchTbl;
use ReflectionMethod;
use Zend_Db_Adapter_Mysqli;
use Zend_Registry;

class BarristerAuditor {
    const AUDITING = true;

    /** @var  AuditMismatchTbl $auditMismatchTbl */
    private $auditMismatchTbl;

    public function audit(ReflectionMethod $reflectionMethod, $handler, $params, $barristerId) {
        /** @var Zend_Db_Adapter_Mysqli $adapter */
        $adapter = Zend_Registry::get('DbReadAdapter');

        $adapter->getProfiler()->setEnabled(true);
        $reflectionMethod->invokeArgs($handler, $params);
        $adapter->getProfiler()->setEnabled(false);

        $query = $adapter->getProfiler()->getLastQueryProfile()->getQuery();
        $params = $adapter->getProfiler()->getLastQueryProfile()->getQueryParams();

        $tbl = $this->getAuditMismatchTbl();
        $tbl->insertQueryProfile($barristerId, $query, $params);
    }

    public function setAuditMismatchTbl(AuditMismatchTbl $tbl) {
        $this->auditMismatchTbl = $tbl;
    }

    public function getAuditMismatchTbl() {
        if (!isset($this->auditMismatchTbl)) {
            $this->auditMismatchTbl = new AuditDslResultMismatchTbl();
        }

        return $this->auditMismatchTbl;
    }

    public function isInAuditMode() {
        return self::AUDITING;
    }
} 
