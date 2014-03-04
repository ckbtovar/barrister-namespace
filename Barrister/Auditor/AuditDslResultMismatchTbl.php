<?php namespace Barrister\Auditor;

use Barrister\Auditor\Interfaces\AuditMismatchTbl;
use CK_Model_Trait_Encryption;
use Exception;
use SuperTbl;

class AuditDslResultMismatchTbl extends SuperTbl implements AuditMismatchTbl {

    use CK_Model_Trait_Encryption;

    const NAME = 'audit_dsl_result_mismatch';

    const C_ID = 'id';
    const C_TIMING_ID = 'timingId'; // @see AuditDslTimingTbl::C_ID
    const C_NUMERIC_ID = 'numericId';
    const C_SERVICE = 'service';
    const C_ARGS = 'args';
    const C_SQL_RESULT = 'sqlResult';
    const C_DSL_RESULT = 'dslResult';
    const C_BARRISTER_ID = 'barristerId';
    const C_DSL_QUERY = 'dslQuery';
    const C_DSL_PARAMS = 'dslParams';


    public function insertWithTimingId($timingId, $numericId = null, $serviceName, $args, $sqlResult, $dslResult) {
        $record = array(
            self::C_TIMING_ID => $timingId,
            self::C_SERVICE => $serviceName,
            self::C_ARGS => $args,
            self::C_SQL_RESULT => $sqlResult,
            self::C_DSL_RESULT => $dslResult
        );

        if (!is_null($numericId)) {
            $record[self::C_NUMERIC_ID] = $numericId;
        }

        return $this->insert($this->encryptRecord($record), true);
    }

    public function getRow($id) {
        $row = $this->selectRow(array('*'), array(self::C_ID => $id));

        $decryptedRow = array();
        if ($row) {
            $cryptographer = $this->getCryptographer();
            $encryptedCols = $this->getEncryptedCols();
            foreach ($row as $column => $value) {
                if (in_array($column, $encryptedCols)) {
                    $decryptedRow[$column] = $cryptographer->decrypt($value);
                }
                else {
                    $decryptedRow[$column] = $value;
                }
            }
        }
        return $decryptedRow;
    }

    /**
     * @param array $timingIds
     * @return array
     */
    public function getMismatchesForTimingIds(array $timingIds) {
        $sqlStatement = "SELECT "
            . "\n " . self::NAME . "." . self::C_SERVICE
            . "\n FROM " . self::NAME
            . "\n WHERE " . self::NAME . "." . self::C_TIMING_ID . " IN (" . self::getQuestionMarks($timingIds) . ")";

        $bindParams = $timingIds;

        try {
            $resultSet = $this->fetchAll($sqlStatement, $bindParams);
            return $resultSet;
        } catch (Exception $ex) {
            dolog("Failed to get mismatches for given timing ids.\n" . $ex->getMessage() . "\n" . $ex->getTraceAsString());
            return array();
        }
    }

    public function getEncryptedCols() {
        return array(
            self::C_ARGS,
            self::C_SQL_RESULT,
            self::C_DSL_RESULT
        );
    }

    public function insertQueryProfile($barristerId, $query, $params) {
        $values = [
            self::C_BARRISTER_ID => "53164119e41ff5.66418879", //$barristerId,
            self::C_DSL_QUERY => $query,
            self::C_DSL_PARAMS => implode(',', $params)
        ];

//        $this->insert($values, true);
        $this->insertOnDuplicate($values, $values);
    }
}
