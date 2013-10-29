<?php

namespace Barrister\Handler\Namespaced;

use Barrister\Exception\RequestException;
use Barrister\Exception\WrongRequestType;
use Barrister\Handler;
use Barrister\Request;
use Barrister\Response;
use Doctrine\Common\Collections\ArrayCollection;

class NamespacedHandlerMapper implements Handler {
    /**
     * @var \Doctrine\Common\Collections\ArrayCollection
     */
    private $handlers;

    public function __construct() {
        $this->handlers = new ArrayCollection();
    }

    /**
     * @param string  $namespaceKey
     * @param Handler $handler
     */
    public function addHandler($namespaceKey, Handler $handler) {
        $this->handlers->set($namespaceKey, $handler);
    }

    /**
     * @param Request $request
     * @return Response
     * @throws \Barrister\Exception\RequestException
     * @throws \Barrister\Exception\WrongRequestType
     */
    public function handle(Request $request) {
        if (!($request instanceof Request\NamespacedRequest)) {
            throw new WrongRequestType("Request should be an instance of a NamespacedRequest");
        }

        $namespaceKey = $request->getNamespace();

        if (!$this->handlers->containsKey($namespaceKey)) {
            throw new RequestException("There is no handler mapped to this key: $namespaceKey.");
        }

        /** @var Handler $handler */
        $handler = $this->handlers->get($namespaceKey);

        return $handler->handle($request);
    }
}
