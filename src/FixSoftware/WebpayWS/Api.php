<?php

namespace FixSoftware\WebpayWS;

class Api {

    /** @var array */
    private $soapWrapperNameIrregulars = [
        'processMasterPaymentRevoke' => [
            'request' => 'masterPaymentStatusRequest',
            'response' => 'masterPaymentStatusResponse',
        ],
        'processBatchClose' => [
            'request' => 'batchClose',
            'response' => 'batchCloseResponse',
        ],
        'processRefund' => [
            'request' => 'refundRequest',
            'response' => 'refundRequestResponse',
        ],
    ];

    /** @var Config */
    private $config;

    /** @var Signer */
    private $signer;

    /** @var Request */
    private $request;

    /** @var Response */
    private $response;

    /** @var \SoapClient */
    private $soapClient;

    private $method;
    private $params = [];

    public function __construct(Config $config) {

        $this->config = $config;
        $this->signer = new Signer($this->config->signerPrivateKeyPath, $this->config->signerPrivateKeyPassword, $this->config->signerGpPublicKeyPath);
        $this->signer->setLogPath($this->config->signerLogPath);
        $this->soapClient = new \SoapClient($this->config->wsdlPath, [
            'cache_wsdl' => WSDL_CACHE_NONE,
            'trace' => 1,
            'exceptions' => 0,
            'location' => $endpoint,
            'encoding' => 'UTF-8',
            'stream_context'=> stream_context_create(array(
                    'http' => array(
                        'user_agent' => 'PHPSoapClient'
                    ),
                    'ssl'=> array(
                        'verify_peer' => false,
                        'verify_peer_name' => false, 
                        'allow_self_signed' => true
                    )
                )
            )
        ]);
    }

    public function call($method, array $params) {

        $this->method = $method;
        $this->params = $params;
        $this->request = null;
        $this->response = null;

        try {
            
            // create Request
            $this->request = new Request($method, $this->config->provider, $this->config->merchantNumber, $params, $this->soapWrapperNameIrregulars);

            // sign Request
            $requestSignature = $this->signer->sign($this->request->getParams(), Signer::SIGNER_BASE64_DISABLE, $method);
            $this->request->setSignature($requestSignature);

            // set Request data to SoapClient
            $this->soapClient->__setLocation($this->config->serviceUrl);
            //throw new SignerException(var_dump($this->request->getSoapData()));
            $soapResponse = $this->soapClient->__soapCall($this->request->getSoapMethod(), $this->request->getSoapData());

            // create Response
            $this->response = new Response($method, $this->request->getMessageId(), $soapResponse, $this->soapWrapperNameIrregulars);

            //throw new ApiException(var_dump($this->response) . var_dump($this->request));

            // verify Response
            $this->signer->verify($this->response->getParams(), $this->response->getSignature(), !$this->response->hasError() ? Signer::SIGNER_BASE64_DISABLE : Signer::SIGNER_BASE64_ENABLE);

        } catch(\SoapFault $e) {
            throw new ApiException('SOAP exception:' . $e->getMessage(), $e->getCode(), $e);
        }

        return $this->response;

    }

    public function getRequest() {

        return $this->request;

    }

    public function getResponse() {

        return $this->response;

    }

}