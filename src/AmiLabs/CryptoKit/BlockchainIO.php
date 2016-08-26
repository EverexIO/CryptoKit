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
use \AmiLabs\CryptoKit\Blockchain;

/**
 * Blockchain I/O Facade.
 */
class BlockchainIO{
    /**
     * Singleton implementation.
     *
     * @ \AmiLabs\CryptoKit\BlockchainIO
     * @return \AmiLabs\CryptoKit\Blockchain\ILayer
     */
    public static function getInstance($layer = FALSE)
    {
        return self::initLayer($layer);
    }

    /**
     * Returns appropriate block chain layer.
     *
     * @param  string $layer
     * @return \AmiLabs\CryptoKit\Blockchain\ILayer
     */
    public static function getLayer($layer = FALSE)
    {
        return self::initLayer($layer);
    }
    /**
     * Contructor.
     *
     * @todo Ability to use classname in config
     */
    // protected function __construct()
    public static function initLayer($layer = FALSE)
    {
        $cfgLayer = Registry::useStorage('CFG')->get('CryptoKit/layer', FALSE);
        if(FALSE !== $layer){
            $layerName = $layer;
        }elseif(FALSE !== $cfgLayer){
            $layerName = $cfgLayer;
        }else{
            $layer = 'Counterparty';
        }
        $class = "\\AmiLabs\\CryptoKit\\Blockchain\\Layer\\" . $layerName;
        return new $class;
    }
}
