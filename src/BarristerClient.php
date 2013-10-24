<?php

namespace Barrister;

class BarristerClient {
    /**
     * @var BarristerTransport
     */
    public $trans;

    /**
     * @var BarristerContract
     */
    public $contract;

    /**
     * @param BarristerTransport $trans
     */
    public function __construct(BarristerTransport $trans) {
        $this->trans = $trans;
        $this->loadContract();
    }

    /**
     * @param $interfaceName
     * @return BarristerClientProxy
     */
    public function proxy($interfaceName) {
        $this->contract->checkInterface($interfaceName);
        return new BarristerClientProxy($this, $interfaceName);
    }

    /**
     * @return array
     */
    public function getMeta() {
        return $this->contract->getMeta();
    }

    public function loadContract() {
        $req            = array("jsonrpc" => "2.0", "id" => "1", "method" => "barrister-idl");
        $resp           = $this->trans->request($req);
        $this->contract = new BarristerContract($resp->result);
    }

    /**
     * @return BarristerBatch
     */
    public function startBatch() {
        return new BarristerBatch($this);
    }

    public function request($method, $params) {
        $req = $this->createRequest($method, $params);
        return $this->trans->request($req);
    }

    public function createRequest($method, $params) {
        $req = array("jsonrpc" => "2.0", "id" => uniqid("", true), "method" => $method);
        if ($params && is_array($params) && count($params) > 0) {
            $req["params"] = $params;
        }
        return $req;
    }
}