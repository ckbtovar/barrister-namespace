<?php

namespace Barrister;

use Barrister\Exception\BarristerRpcException;

class BarristerServer {
    const FULLY_QUALIFIED_NAMESPACE = "namespace_name";
    const INTERFACE_NAME            = "interface_name";
    const FUNCTION_NAME             = "function_name";

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
            return $this->errResp($req, -32600, "No method specified on request");
        }

        if ($method === "barrister-idl") {
            return $this->okResp($req, $this->contract->idl);
        }

        $deconstructedMethodSignature = $this->deconstructMethodString($method);
        if ($deconstructedMethodSignature instanceof \Exception) {
            return $this->errResp($req, -32600, $deconstructedMethodSignature->getMessage());
        }
        else {
            $fullNamespace = $deconstructedMethodSignature[self::FULLY_QUALIFIED_NAMESPACE];
            $iface         = $deconstructedMethodSignature[self::INTERFACE_NAME];
            $func          = $deconstructedMethodSignature[self::FUNCTION_NAME];
        }

        $ifaceInst = $this->contract->getInterface($iface);
        $funcInst  = null;
        if ($ifaceInst) {
            $funcInst = $ifaceInst->getFunction($func);
        }
        if (!$ifaceInst || !$funcInst) {
            return $this->errResp($req, -32601, "Method not found on IDL: $method");
        }

        $params = $req->params;
        if (!$params) {
            $params = array();
        }

        $invalid = $funcInst->validateParams($this->contract, $params);
        if ($invalid !== null) {
            return $this->errResp($req, -32602, $invalid);
        }

        $handler = $this->handlers[$iface];
        if (!$handler) {
            return $this->errResp($req, -32601, "Interface not found: $iface");
        }

        $reflectMethod = null;
        try {
            $reflectMethod = new \ReflectionMethod(get_class($handler), $func);
        }
        catch (\Exception $e) { }

        if (!$reflectMethod) {
            try {
                $reflectMethod = new \ReflectionMethod(get_class($handler), $func . "_");
            }
            catch (\Exception $e) { }
        }

        if (!$reflectMethod) {
            return $this->errResp($req, -32601, "Method not found: $method");
        }

        try {
            $result = $reflectMethod->invokeArgs($handler, $params);

            $invalid = $funcInst->validateResult($this->contract, $result);
            if ($invalid !== null) {
                return $this->errResp($req, -32001, $invalid);
            }
            else {
                return $this->okResp($req, $result);
            }
        }
        catch (BarristerRpcException $e) {
            return $this->errResp($req, $e->getCode(), $e->getMessage(), $e->getData());
        }
        catch (\Exception $e) {
            return $this->errResp($req, -32000, "Unknown error: " . $e->getMessage());
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
     * Deconstruct a method string into a fully qualified namespace, interface, and function strings
     *
     * @param $method
     * @return array
     */
    private function deconstructMethodString($method) {
        $pos = strpos($method, '.');

        try {
            if ($pos > 0) {
                $fullyQualifiedNamespace = substr($method, 0, $pos);
                $method                  = substr($method, $pos + 1);

                $pos = strpos($method, '.');

                if ($pos > 0) {
                    $interface = substr($method, 0, $pos);
                    $func  = substr($method, $pos + 1);

                    return array(
                        self::FULLY_QUALIFIED_NAMESPACE => $fullyQualifiedNamespace,
                        self::INTERFACE_NAME            => $interface,
                        self::FUNCTION_NAME             => $func
                    );
                }
                else {
                    throw new \Exception("Invalid request method when trying to get interface and function: $method");
                }
            }
            else {
                throw new \Exception("Invalid request method when trying to get fully qualified namespace: $method");
            }
        } catch (\Exception $ex) {
            return $ex;
        }
    }
}