<?php

namespace Barrister;

use Barrister\Auditor\BarristerAuditor;
use Barrister\Auditor\Interfaces\Auditor;
use Barrister\Exception\BarristerRpcException;
use Barrister\Exception\IncompatibleIDLException;
use Barrister\Exception\IncompleteRequestException;
use Barrister\Exception\InvalidRequestParamsException;
use Barrister\Exception\InvalidResultsException;

class BarristerServer {
    const INTERFACE_NAME = "interface_name";
    const FUNCTION_NAME  = "function_name";

    /**
     * @var BarristerContract
     */
    public $contract;

    /**
     * @var array
     */
    public $handlers;

    /**
     * @var BarristerJsonDecoder
     */
    public $jsonDecoder;

    /**
     * @var Auditor $auditor
     */
    private $auditor;

    public function getAuditor() {
        if (!isset($this->auditor)) {
            $this->auditor = new BarristerAuditor();
        }

        return $this->auditor;
    }

    /**
     * @param Auditor $auditor
     */
    public function setAuditor($auditor=null) {
        if (!isset($auditor)) {
            $auditor = new BarristerAuditor();
        }

        $this->auditor = $auditor;
    }

    /**
     * @param                      $idlFile
     * @param BarristerJsonDecoder $jsonDecoder
     * @throws \Exception
     */
    public function __construct($idlFile, BarristerJsonDecoder $jsonDecoder) {
        $this->jsonDecoder = $jsonDecoder;

        if (file_exists($idlFile)) {
            $fh = fopen($idlFile, 'r');
            $data = fread($fh, filesize($idlFile));
            fclose($fh);

            $this->contract = new BarristerContract($this->jsonDecoder->decode($data));
            $this->handlers = array();
        }
        else {
            throw new \Exception("File not found: $idlFile");
        }
    }

    public function addHandler($interfaceName, $handler) {
        $this->contract->checkInterface($interfaceName);
        $this->handlers[$interfaceName] = $handler;
    }

    public function handleHTTP() {
        $isCGI = strpos($_ENV['GATEWAY_INTERFACE'], "CGI") === 0;
        if ($isCGI) {
            $reqJson = file_get_contents('php://stdin', NULL, NULL, 0, $_ENV['CONTENT_LENGTH']);
        }
        else {
            $reqJson = file_get_contents('php://input');
        }

        $resp = null;
        $req  = null;
        try {
            $req = $this->jsonDecoder->decode($reqJson);
        }
        catch (BarristerRpcException $e) {
            $resp = $this->errResp($req, $e->getCode(), $e->getMessage());
        }

        if ($resp === null) {
            $resp = $this->handle($req);
        }

        $respJson = json_encode($resp);
        $len      = strlen($respJson);

        if ($isCGI) {
            print "Content-Type: application/json\r\n";
            print "Content-Length: $len\r\n\r\n";
            print $respJson;
        }
        else {
            header("Content-Type: application/json");
            header("Content-Length: $len");
            print $respJson;
        }
    }

    public function handle($req) {
        if (is_array($req)) {
            $retList = array();
            foreach ($req as $i=>$r) {
                array_push($retList, $this->handleSingle($r));
            }
            return $retList;
        }
        else {
            return $this->handleSingle($req);
        }
    }

    public function handleSingle($req) {
        $method = $req->method;
        if (!$method) {
            throw new IncompleteRequestException();
        }

        if ($method === "barrister-idl") {
            return $this->okResp($req, $this->contract->idl);
        }

        $deconstructedMethodSignature = $this->deconstructMethodString($method);

        $requestInterface = $deconstructedMethodSignature[self::INTERFACE_NAME];
        $requestFunction  = $deconstructedMethodSignature[self::FUNCTION_NAME];

        $ifaceInst = $this->contract->getInterface($requestInterface);
        $funcInst  = null;
        if ($ifaceInst) {
            $funcInst = $ifaceInst->getFunction($requestFunction);
        }
        if (!$ifaceInst || !$funcInst) {
            throw new IncompatibleIDLException("Method not found on IDL: $method");
        }

        $params = $req->params;
        if (!$params) {
            $params = array();
        }

        $invalid = $funcInst->validateParams($this->contract, $params);
        if ($invalid !== null) {
            throw new InvalidRequestParamsException($invalid);
        }

        $handler = $this->handlers[$requestInterface];
        if (!$handler) {
            throw new IncompatibleIDLException("Interface not found: $requestInterface");
        }

        $reflectMethod = null;
        try {
            $reflectMethod = new \ReflectionMethod(get_class($handler), $requestFunction);
        }
        catch (\Exception $e) { }

        if (!$reflectMethod) {
            try {
                $reflectMethod = new \ReflectionMethod(get_class($handler), $requestFunction . "_");
            }
            catch (\Exception $e) { }
        }

        if (!$reflectMethod) {
            throw new IncompatibleIDLException("Method not found: $method");
        }

        if ($this->isInAuditMode()) {
            $aud = $this->getAuditor();
            $result = $aud->audit($reflectMethod, $handler, $params, $req->id);
        } else {
            $result = $reflectMethod->invokeArgs($handler, $params);
        }

        $invalid = $funcInst->validateResult($this->contract, $result);
        if ($invalid !== null) {
            throw new InvalidResultsException($invalid);
        }
        else {
            return $this->okResp($req, $result);
        }
    }

    public function okResp($req, $result) {
        $resp = array("jsonrpc"=>"2.0", "result"=>$result);
        if (isset($req->id)) {
            $resp["id"] = $req->id;
        }
        return $resp;
    }

    public function errResp($req, $code, $message, $data=null) {
        $err = array("code"=>$code, "message"=>$message);
        if ($data) {
            $err["data"] = $data;
        }
        $resp = array("jsonrpc"=>"2.0", "error"=>$err);
        if (isset($req->id)) {
            $resp["id"] = $req->id;
        }
        return $resp;
    }

    /**
     * @param $method
     * @return array
     * @throws Exception\IncompleteRequestException
     */
    private function deconstructMethodString($method) {
        $pos = strpos($method, '.');

        if ($pos > 0) {
            $interface = substr($method, 0, $pos);
            $func  = substr($method, $pos + 1);

            return array(
                self::INTERFACE_NAME => $interface,
                self::FUNCTION_NAME  => $func
            );
        }
        else {
            throw new IncompleteRequestException("Invalid request method when trying to get interface and function: $method");
        }
    }

    private function isInAuditMode() {
        return $this->getAuditor()->isInAuditMode();
    }
}
