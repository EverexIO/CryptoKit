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
use AmiLabs\DevKit\Logger;
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
     * Logger object
     *
     * @var \AmiLabs\DevKit\Logger
     */
    protected $oLogger;

    /**
     * Constructor.
     *
     * @throws \Exception
     */
    protected function __construct(array $aConfig){
        $this->oLogger = Logger::get('ethereum-mongo', FALSE, TRUE);

        $this->aSettings = $aConfig;
        if(!isset($this->aSettings['mongo'])){
            $this->aSettings['mongo'] = Registry::useStorage('CFG')->get('CryptoKit/mongo', FALSE);
            if(FALSE === $this->aSettings['mongo']){
                throw new \Exception("Mongo configuration not found");
            }
        }
        if(!empty($this->aSettings['ethereum'])){
            $this->aSettings['ethereum'] = Registry::useStorage('CFG')->get('CryptoKit/ethereum', FALSE);
        }
        if(!isset($this->aSettings['assets'])){
            $this->aSettings['assets'] = Registry::useStorage('CFG')->get('assets', []);
        }
        if(class_exists("MongoClient")){
            $oMongo = new \MongoClient($this->aSettings['mongo']['server']);
            $oDB = $oMongo->{$this->aSettings['mongo']['dbName']};
            $this->dbs = array(
                'transactions' => $oDB->{"transactions"},
                'blocks'       => $oDB->{"blocks"},
                'contracts'    => $oDB->{"contracts"},
                'tokens'       => $oDB->{"tokens"},
                'operations'   => $oDB->{"tokenOperations2"},
                'balances'     => $oDB->{"tokenBalances"},
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
            "balance"       => 0, // $this->getBalance($address),
            "transfers"     => array()
        );

        $totalIn = 0;
        $cursor = $this->dbs['transactions']->find(array("to" => $address)/*, array('value')*/);
        while($cursor->hasNext()){
            $res = $cursor->getNext();
            $totalIn += $res['value']/* / 1e+18 */;
        }
        $result['totalIn'] = $totalIn;
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
        if(is_array($result) && is_array($result['tx'])){
            $result['tx']['confirmations'] = $this->getLastBlock() - $result['tx']['blockNumber'];
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
            $result['success'] = isset($result['status']) ? ($result['status'] == '0x1') : (($result['gasUsed'] < $result['gasLimit']) && ($result['gasUsed'] > 21000));
        }
        return $result;
    }
    /**
     * Returns list of transfers in specified transaction.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getOperations($tx, $type = FALSE){
        // evxProfiler::checkpoint('getOperations START [hash=' . $tx . ']');
        $search = array("transactionHash" => $tx);
        if($type){
            $search['type'] = $type;
        }
        $cursor = $this->dbs['operations']->find($search);
        $result = array();
        while($cursor->hasNext()){
            $res = $cursor->getNext();
            unset($res["_id"]);
            $res["success"] = true;
            $result[] = $res;
        }
        // evxProfiler::checkpoint('getOperations FINISH [hash=' . $tx . ']');
        return $result;
    }

    /**
     * Returns list of transfers in specified transaction.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getTransfers($tx){
        return $this->getOperations($tx, 'transfer');
    }

    /**
     * Returns list of issuances in specified transaction.
     *
     * @param string  $tx  Transaction hash
     * @return array
     */
    public function getIssuances($tx){
        return $this->getOperations($tx, 'issuance');
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
        $result = false;
        $oCache = Cache::get('token-' . $address);
        if(!$oCache->exists() || $oCache->clearIfOlderThan(self::TOKEN_UPDATE_INTERVAL)){
            $cursor = $this->dbs['tokens']->find(array("address" => $address));
            $result = $cursor->hasNext() ? $cursor->getNext() : false;
            if($result){
                unset($result["_id"]);
                $oCache->save($result);
            }
        }else{
            $result = $oCache->load();
        }
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
        return $this->getContractOperation('transfer', $address, $limit);
    }

    /**
     * Returns list of contract issuances.
     *
     * @param string $address  Contract address
     * @param int $limit       Maximum number of records
     * @return array
     */
    public function getContractIssuances($address, $limit = 10){
        return $this->getContractOperation('issuance', $address, $limit);
    }

    /**
     * Returns number of contract transactions.
     *
     * @param string $address  Contract address
     * @return int
     */
    public function getContractTransactionsNum($address){
        $cursor = $this->dbs['tokens']->find(array("address" => $address));
        $result = $cursor->hasNext() ? $cursor->getNext() : false;
        if($result){
            return (int)$result['txsCount'];
        }
        return 0;
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
    public function getAddressBalances($address, $withZero = TRUE, $log = FALSE){
        $aAssets = [];
        if(!empty($this->aSettings['assets'])){
            $aAssets = array_keys($this->aSettings['assets']);
        } elseif (!empty($this->aSettings['ethereum'])) {
            $aConfig = $this->aSettings['ethereum'];
            $aAssets = isset($aConfig['contracts']) ? array_keys($aConfig['contracts']) : array();
        }
        $aResult = array();
        // @todo: $withZero flag implementation
        if(!empty($aAssets)){
            $aResult = $this->getAddressesBalances($aAssets, array($address), $log);
        }
        return $aResult;
    }

    /**
     * Returns current balances.
     *
     * @param  array $aAssets   List of assets
     * @param  array $aAddress  Addresses list
     * @return array
     */
    public function getAddressesBalances(
        array $aAssets = array(),
        array $aAddress = array(),
        $log = FALSE
    ){
        $aContractInfo = array();
        if(!empty($this->aSettings['assets'])){
            $aConfigAssets = $this->aSettings['assets'];
            foreach($aConfigAssets as $asset => $data){
                $aContractInfo[$asset] = $this->getToken($data['contractAddress']);
            }
        } elseif (!empty($this->aSettings['ethereum'])) {
            // Backward compatibility
            $aConfig = $this->aSettings['ethereum'];
            if(isset($aConfig['contracts'])){
                foreach($aConfig['contracts'] as $asset => $address){
                    $aContractInfo[$asset] = $this->getToken($address);
                }
            }
        }

        $aResult = array();
        foreach($aAssets as $asset){
            if(!isset($aContractInfo[$asset])) continue;
            foreach($aAddress as $address){
                $cursor = $this->dbs['balances']->find(array('address' => $address, 'contract' => $aContractInfo[$asset]['address']));
                $result = $cursor->hasNext() ? $cursor->getNext() : false;
                $digits = intval($aContractInfo[$asset]['decimals']);
                if($result){
                    $aResult[$address][$asset] = array(
                        'balance' => round(floatval($result['balance']) / pow(10, $digits), $digits)
                    );
                }
            }
        }
        if($log){
            $this->oLogger->log('getAddressesBalances: ' . var_export($aAddress, TRUE) . "\nResult: " . var_export($aResult, TRUE));
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
     * @param  array  $aTxTypes   Transactions types
     * @return array
     */
    public function getAddressHistory(
        array $aAssets = array(),
        $address,
        $limit,
        $order,
        $direction,
        array $aTxTypes = array()
    ){
        $aContractInfo = array();
        $aAssetContracts = [];
        $aBalances = [];
        if(!empty($this->aSettings['assets'])){
            $aConfig = $this->aSettings['assets'];
            foreach($aConfig as $asset => $aAsset){
                $aContractInfo[$asset] = $this->getToken($aAsset['contractAddress']);
                $aAssetContracts[$aAsset['contractAddress']] = $asset;
                $aBalances[$asset] = 0;
            }
        } elseif (!empty($this->aSettings['ethereum'])) {
            $aConfig = $this->aSettings['ethereum'];
            if(isset($aConfig['contracts'])){
                foreach($aConfig['contracts'] as $asset => $contract){
                    $aContractInfo[$asset] = $this->getToken($contract);
                    $aAssetContracts[$contract] = $asset;
                    $aBalances[$asset] = 0;
                }
            }
        }

        $aResult = array();
        $cursor = $this->dbs['operations']
            ->find(
                array(
                    'type' => 'transfer',
                    'addresses' => $address, // '$or' => array(array("from" => $address), array("to" => $address))))
                )
            )
            ->sort(array("timestamp" => (($order == 'asc') ? 1 : -1)))
            ->limit($limit);

        foreach($cursor as $transfer){

            if(empty($transfer['contract']) || !isset($aAssetContracts[$transfer['contract']])){
                continue;
            }

            $asset = $aAssetContracts[$transfer['contract']];

            $txAddress = $transfer['to'];
            $txOppAddress = $transfer['from'];

            $digits = intval($aContractInfo[$asset]['decimals']);
            $txQuantity = round(floatval($transfer['value']) / pow(10, $digits), $digits);
            if($transfer['from'] == $address){
                $txQuantity = -$txQuantity;
                $txAddress = $transfer['from'];
                $txOppAddress = $transfer['to'];
            }

            $aBalances[$asset] += $txQuantity;

            $aResult[] = array(
                'date' => date('Y-m-d H:i:s', $transfer['timestamp']),
                'timestamp' => $transfer['timestamp'] * 1000,
                'block' => $transfer['blockNumber'],
                'tx_hash' => $transfer['transactionHash'],
                'address' => $txAddress,
                'opposite_address' => $txOppAddress,
                'difference' => $txQuantity,
                'asset' => $asset,
                'usdPrice' => isset($transfer['usdPrice']) ? $transfer['usdPrice'] : 0,
                'balance' => round($aBalances[$asset], $digits),
                'failedReason' => false,
                'confirmations' => 1, // Unused, for compatibility only
                'gas_price' => 1, // Unused, for compatibility only
                'gas_used' => 1, // Unused, for compatibility only
            );
        }
        $aResult = ($direction == 'desc') ? array_reverse($aResult) : $aResult;
        $this->oLogger->log('getAddressHistory [' . $address . "]: " . var_export($aResult, TRUE));
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
        $cursor = $this->dbs['operations']
            ->find(array('$or' => array(array("from" => $address), array("to" => $address)), 'type' => 'transfer'))
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
        $cursor = $this->dbs['operations']
            ->find(array("contract" => $address, 'type' => $type))
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
        $s   = Decimal::create(1);
        $c   = Decimal::create(0);
        $e   = Decimal::create(0);
        $dec = Decimal::create(0);

        if(is_array($aNumber)){
            if(isset($aNumber['s'])){
                $s = Decimal::create($aNumber['s']);
            }
            if(isset($aNumber['e'])){
                $e = Decimal::create($aNumber['e']);
            }
            if(isset($aNumber['c']) && !empty($aNumber['c'])){
                $k = Decimal::create(strlen($aNumber['c'][0]) - 1);
                $c = Decimal::create($aNumber['c'][0])->mul($ten->pow($e->sub($k)));
            }
        }else{
            $c = Decimal::create($aNumber);
        }

        if(is_array($aDecimal)){
            if(isset($aDecimal['c']) && sizeof($aDecimal['c'])){
                $dec = Decimal::create($aDecimal['c'][0]);
            }
        }else{
            $dec = Decimal::create($aDecimal);
        }

        $res = $s->mul($c->div($ten->pow($dec)));
        return $res;
    }
}