<?php

/**
 * Copyright 2016 Everex https://everex.io
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace AmiLabs\CryptoKit;

use \AmiLabs\DevKit\Registry;
use \AmiLabs\CryptoKit\IRPCServiceClient;
use \AmiLabs\CryptoKit\RPCServiceClient;

/**
 * Class for JSON RPC execution.
 */
class RPCJSON extends RPCServiceClient implements IRPCServiceClient{
    /**
     * JSON RPC Client object
     *
     * @var \AmiLabs\JSONRPC\RPC\Client\JSON
     */
    protected $oClient;

    /**
     * Constructor.
     *
     * @param array $aConfig  Driver configuration
     */
    public function __construct(array $aConfig){
        $this->oClient = \AmiLabs\JSONRPC\RPC::getLayer(
            // 'AmiLabs\\CryptoKit\\Net\\RPC\\Client\\JSON',
            'JSON',
            \AmiLabs\JSONRPC\RPC::TYPE_CLIENT,
            array(
                CURLOPT_SSL_VERIFYPEER => FALSE, // Todo: use from configuration, only for HTTPS
                CURLOPT_SSL_VERIFYHOST => FALSE,
                'AmiLabs\\Logger' =>
                    Registry::useStorage('CFG')->get('AmiLabs\\Logger', array())
            )
        );
        $this->oClient->open($aConfig['address']);
    }

    /**
     * Execute JSON RPC command.
     *
     * @param string $command
     * @param array $aParams
     * @return array
     */
    public function exec($command, array $aParams){
        return $this->oClient->execute(
            $command,
            $aParams,
            array(
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 240
            )
        );
    }
}
