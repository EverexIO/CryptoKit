<?php

/*!
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

namespace AmiLabs\CryptoKit\Blockchain;

use AmiLabs\DevKit\Cache;
use AmiLabs\DevKit\Registry;
use \Litipk\BigNumbers\Decimal;

/**
 * Class to interact with Ethereum parsed mongodb database.
 */
class EthereumDB {
    /**
     * Token cache update interval
     */
    const TOKEN_UPDATE_INTERVAL = 3600;

    /**
     * Settings array
     *
     * @var array
     */
    protected $aSettings = array();

    /**
     * MongoDB collections.
     *
     * @var array
     */
    protected $dbs;

    /**
     * Singleton instance.
     *
     * @var Etherscan
     */
    protected static $oInstance;

    /**
     * Last known block number
     *
     * @var int
     */
    protected $lastBlock;

    /**
     * Constructor.
     *
     * @throws \Exception
     */
    protected function __construct(array $aConfig){
        $this->aSettings = $aConfig;
        if(!isset($this->aSettings['mongo'])){
            $this->aSettings['mongo'] = Registry::useStorage('CFG')->get('CryptoKit/mongo', FALSE);
            if(FALSE === $this->aSettings['mongo']){
                throw new \Exception("Mongo configuration not found");
            }
        }
        if(!isset($this->aSettings['ethereum'])){
            $this->aSettings['ethereum'] = Registry::useStorage('CFG')->get('CryptoKit/ethereum', FALSE);
        }
        if(class_exists("MongoClient")){
            $oMongo = new \MongoClient($this->aSettings['mongo']['server']);
            $oDB = $oMongo->{$this->aSettings['mongo']['dbName']};
            $this->dbs = array(
                'transactions' => $oDB->{"everex.eth.transactions"},
                'blocks'       => $oDB->{"everex.eth.blocks"},
                'contracts'    => $oDB->{"everex.eth.contracts"},
                'tokens'       => $oDB->{"everex.erc20.contracts"},
                'transfers'    => $oDB->{"everex.erc20.transfers"},
                'issuances'    => $oDB->{"everex.erc20.issuances"},
                'balances'     => $oDB->{"everex.erc20.balances"},
            );
        }else{
            throw new \Exception("MongoClient class not found, php_mongo extension required");
        }
    }

    /**
     * Singleton getter.
     *
     * @return Ethereum
     */
    public static function db(array $aConfig = array()){
        if(is_null(self::$oInstance)){
            self::$oInstance = new EthereumDB($aConfig);
        }
        return self::$oInstance;
    }

    /**
     * Returns true if provided string is a valid ethereum address.
     *
     * @param string $address  Address to check
     * @return bool
     */
    public function isValidAddress($address){
        return (is_string($address)) ? preg_match("/^0x[0-9a-f]{40}$/", $address) : false;
    }

    /**
     * Returns true if provided string is a valid ethereum tx hash.
     *
     * @param string  $hash  Hash to check
     * @return bool
     */
    public function isValidTransactionHash($hash){
        return (is_string($hash)) ? preg_match("/^0x[0-9a-f]{64}$/", $hash) : false;
    }

    /**
     * Returns list of block transactions.
     *
     * @param int $block
     * @return array
     */
    public function getBlockTransactions($block){
        $cursor = $this->dbs['transactions']->find(array("blockNumber" => (int)$block));
        $result = array();
        while($cursor->hasNext()){
            $res = $cursor->getNext();
            unset($res["_id"]);
            $result[] = $res;
        }
        return $result;
    }

    /**
     * Returns advanced address details.
     *
     * @param string $address
     * @return array
     */
    public function getAddressDetails($address){
        $result = array(
            "isContract"    => false,
            "balance"       => $this->getBalance($address),
            "transfers"     => array()
        );
        $contract = $this->getContract($address);
        $token = false;
        if($contract){
            $result['isContract'] = true;
            $result['contract'] = $contract;
            if($token = $this->getToken($address)){
                $result["token"] = $token;
            }
        }
        if($result['isContract'] && isset($result['token'])){
            $result["transfers"] = $this->getContractTransfers($address);
            $result["issuances"] = $this->getContractIssuances($address);
        }
        if(!isset($result['token'])){
            // Get balances
            $result["tokens"] = array();
            $result["balances"] = $this->getAddressBalances($address);
            foreach($result["balances"] as $balance){
                $balanceToken = $this->getToken($balance["contract"]);
                if($balanceToken){
                    $result["tokens"][$balance["contract"]] = $balanceToken;
                }
            }
            $result["transfers"] = $this->getAddressTransfers($address);
        }
        return $result;
    }

    /**
     * Returns advanced transaction data.
     *
     * @param string  $hash  Transaction hash
     * @return array
     */
    public function getTransactionDetails($hash){
        $oCache = Cache::get('tx-' . $hash);
        if(!$oCache->exists()){
            $tx = $this->getTransaction($hash);
            $result = array(
                "tx" => $tx,
                "contracts" => array()
            );
            if(isset($tx["creates"]) && $tx["creates"]){
                $result["contracts"][] = $tx["creates"];
            }
            $fromContract = $this->getContract($tx["from"]);
            if($fromContract){
                $result["contracts"][] = $tx["from"];
            }
            if(isset($tx["to"]) && $tx["to"]){
                $toContract = $this->getContract($tx["to"]);
                if($toContract){
                    $result["contracts"][] = $tx["to"];
                    if($token = $this->getToken($tx["to"])){
                        $result["token"] = $token;
                        $result["transfers"] = $this->getTransfers($hash);
                        $result["issuances"] = $this->getIssuances($hash);
                    }
                    if(is_array($result["issuances"]) && count($result["issuances"])){
                        $result["operation"] = $result["issuances"][0];
                    }elseif(is_array($result["transfers"]) && count($result["transfers"])){
                        $result["operation"] = $result["transfers"][0];
                    }
                }
            }
            $oCache->save($result);
        }else{
            $result = $oCache->load();
        }
        if(is_array($result) && is_array($tx)){
            $result['tx']['confirmations'] = $this->getLastBlock() - $tx['blockNumber'];
        }
        return $result;
    }

    /**
     * Return transaction data by transaction hash.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getTransaction($tx){
        $cursor = $this->dbs['transactions']->find(array("hash" => $tx));
        $result = $cursor->hasNext() ? $cursor->getNext() : false;
        if($result){
            unset($result["_id"]);
            $result['gasLimit'] = $result['gas'];
            unset($result["gas"]);
            $result['gasUsed'] = isset($result['receipt']) ? $result['receipt']['gasUsed'] : 0;
            $result['success'] = isset($result['receipt']) ? ($result['gasUsed'] < $result['gasLimit']) : true;
        }
        return $result;
    }

    /**
     * Returns list of transfers in specified transaction.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getTransfers($tx){
        $cursor = $this->dbs['transfers']->find(array("transactionHash" => $tx));
        $result = array();
        while($cursor->hasNext()){
            $res = $cursor->getNext();
            unset($res["_id"]);
            $res["success"] = true;
            $res["type"] = "transfer";
            $result[] = $res;
        }
        return $result;
    }

    /**
     * Returns list of issuances in specified transaction.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getIssuances($tx){
        $cursor = $this->dbs['issuances']->find(array("transactionHash" => $tx));
        $result = array();
        while($cursor->hasNext()){
            $res = $cursor->getNext();
            unset($res["_id"]);
            $res["success"] = true;
            $res["type"] = "issuance";
            $result[] = $res;
        }
        return $result;
    }

    /**
     * Returns list of known tokens.
     *
     * @param bool  $updateCache  Update cache from DB if true
     * @return array
     */
    public function getTokens($updateCache = false){
        $oCache = Cache::get('tokens');
        if($updateCache || !$oCache->exists() || $oCache->clearIfOlderThan(self::TOKEN_UPDATE_INTERVAL)){
            $cursor = $this->dbs['tokens']->find()->sort(array("transfersCount" => -1));
            $aResult = array();
            foreach($cursor as $aToken){
                $address = $aToken["address"];
                unset($aToken["_id"]);
                $aResult[$address] = $aToken;
            }
            $oCache->save($aResult);
        }else{
            $aResult = $oCache->load();
        }
        return $aResult;
    }

    /**
     * Returns token data by contract address.
     *
     * @param string  $address  Token contract address
     * @return array
     */
    public function getToken($address){
        $aTokens = $this->getTokens();
        $result = isset($aTokens[$address]) ? $aTokens[$address] : false;
        if($result) unset($result["_id"]);
        return $result;
    }

    /**
     * Returns contract data by contract address.
     *
     * @param string $address
     * @return array
     */
    public function getContract($address){
        $cursor = $this->dbs['contracts']->find(array("address" => $address));
        $result = $cursor->hasNext() ? $cursor->getNext() : false;
        if($result) unset($result["_id"]);
        return $result;
    }

    /**
     * Returns list of contract transfers.
     *
     * @param string $address  Contract address
     * @param int $limit       Maximum number of records
     * @return array
     */
    public function getContractTransfers($address, $limit = 10){
        return $this->getContractOperation('transfers', $address, $limit);
    }

    /**
     * Returns list of contract issuances.
     *
     * @param string $address  Contract address
     * @param int $limit       Maximum number of records
     * @return array
     */
    public function getContractIssuances($address, $limit = 10){
        return $this->getContractOperation('issuances', $address, $limit);
    }

    /**
     * Returns last known mined block number.
     *
     * @return int
     */
    public function getLastBlock(){
        if(!$this->lastBlock){
            $cursor = $this->dbs['blocks']->find(array(), array('number' => true))->sort(array('number' => -1))->limit(1);
            $block = $cursor->getNext();
            $this->lastBlock = $block && isset($block['number']) ? (int)$block['number'] : false;
        }
        return $this->lastBlock;
    }

    /**
     * Returns address token balances.
     *
     * @param string $address  Address
     * @param bool $withZero   Returns zero balances if true
     * @return array
     */
    public function getAddressBalances($address, $withZero = true){
        $cursor = $this->dbs['balances']->find(array("address" => $address));
        $result = array();
        $fetches = 0;
        foreach($cursor as $balance){
            unset($balance["_id"]);
            // @todo: $withZero flag implementation
            $result[] = $balance;
            $fetches++;
        }
        return $result;
    }

    /**
     * Returns current balances.
     *
     * @param  array $aAssets   List of assets
     * @param  array $aAddress  Addresses list
     * @return array
     */
    public function getCurrentAddressBalance(
        array $aAssets = array(),
        array $aAddress = array()
    ){
        $aConfig = $this->aSettings['ethereum'];

        $aContractInfo = array();
        if(isset($aConfig['contracts'])){
            foreach($aConfig['contracts'] as $asset => $address){
                $aContractInfo[$asset] = $this->getToken($address);
            }
        }

        $aResult = array();
        foreach($aAssets as $asset){
            foreach($aAddress as $address){
                if(!isset($aContractInfo[$asset])) continue;
                $cursor = $this->dbs['balances']->find(array('address' => $address, 'contract' => $aContractInfo[$asset]['address']));
                $result = $cursor->hasNext() ? $cursor->getNext() : false;
                if($result){
                    $aResult[$address][$asset] = array(
                        'balance' => $this->getDecimalFromJSObject($result['balance'], $aContractInfo[$asset]['decimals'])->__toString()
                    );
                }
            }
        }

        return $aResult;
    }

    /**
     * Returns address history.
     *
     * @param  array  $aAssets    List of assets
     * @param  string $address    Addresse
     * @param  int    $limit      Transactions number
     * @param  string $order      Sort order
     * @param  string $direction  Sort direction
     * @return array
     */
    public function getAddressHistory(
        array $aAssets = array(),
        $address,
        $limit,
        $order,
        $direction
    ){
        $aConfig = $this->aSettings['ethereum'];

        $aContractInfo = array();
        if(isset($aConfig['contracts'])){
            foreach($aConfig['contracts'] as $asset => $contract){
                $aContractInfo[$asset] = $this->getToken($contract);
            }
        }

        $aResult = array();
        foreach($aAssets as $asset){
            if(!isset($aContractInfo[$asset])) continue;

            $cursor = $this->dbs['transfers']
                ->find(array(
                    'contract' => $aContractInfo[$asset]['address'],
                    '$or' => array(array("from" => $address), array("to" => $address))))
                    ->sort(array("timestamp" => 1))
                    ->limit($limit);

            $balance = Decimal::create(0);
            foreach($cursor as $transfer){
                //unset($transfer["_id"]);
                if($transfer['from'] == $address){
                    $transfer['value']['s'] = -1;
                }

                $curBalance = $this->getDecimalFromJSObject($transfer['value'], $aContractInfo[$asset]['decimals']);
                $balance = $balance->add($curBalance);

                $aResult[] = array(
                    'date' => date('Y-m-d', $transfer['timestamp']),
                    'asset' => $asset,
                    'balance' => $balance->__toString()
                );
            }
        }

        return $aResult;
    }

    /**
     * Returns list of transfers made by specified address.
     *
     * @param string $address  Address
     * @param int $limit       Maximum number of records
     * @return array
     */
    public function getAddressTransfers($address, $limit = 10){
        $cursor = $this->dbs['transfers']
            ->find(array('$or' => array(array("from" => $address), array("to" => $address))))
                ->sort(array("timestamp" => -1))
                ->limit($limit);
        $result = array();
        $fetches = 0;
        foreach($cursor as $transfer){
            unset($transfer["_id"]);
            $result[] = $transfer;
            $fetches++;
        }
        return $result;
    }

    /**
     * Returns contract operation data.
     *
     * @param string $type     Operation type
     * @param string $address  Contract address
     * @param string $limit    Maximum number of records
     * @return array
     */
    protected function getContractOperation($type, $address, $limit){
        $cursor = $this->dbs[$type]
            ->find(array("contract" => $address))
                ->sort(array("timestamp" => -1))
                ->limit($limit);
        $result = array();
        $fetches = 0;
        foreach($cursor as $transfer){
            unset($transfer["_id"]);
            $result[] = $transfer;
            $fetches++;
        }
        return $result;
    }

    /**
     * Returns balance string value of the big number object.
     *
     * @param array $aNumber   Number in js object format
     * @param array $aDecimal  Number of decimal in js object format
     * @return \Litipk\BigNumbers\Decimal
     */
    protected function getDecimalFromJSObject($aNumber, $aDecimal){
        $ten = Decimal::create(10);
        if(isset($aNumber['s'])) $s = Decimal::create($aNumber['s']);
        else $s = Decimal::create(1);
        if(isset($aNumber['c']) && sizeof($aNumber['c'])){
            $c = Decimal::create($aNumber['c'][0])->div($ten->pow(Decimal::create(strlen($aNumber['c'][0]) - 1)))->mul($ten->pow(Decimal::create($aNumber['e'])));
        }
        else $c = Decimal::create(0);
        if(isset($aDecimal['c']) && sizeof($aDecimal['c'])) $dec = Decimal::create($aDecimal['c'][0]);
        else $dec = Decimal::create(0);
        $res = $s->mul($c->div($ten->pow($dec)));
        return $res;
    }
}