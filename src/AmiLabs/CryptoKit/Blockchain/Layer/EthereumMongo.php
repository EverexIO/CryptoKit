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

namespace AmiLabs\CryptoKit\Blockchain\Layer;

use Exception;
use RuntimeException;
use UnexpectedValueException;
use AmiLabs\CryptoKit\Blockchain\ILayer;
use AmiLabs\CryptoKit\Blockchain\EthereumDB;
use AmiLabs\CryptoKit\RPC;
use AmiLabs\CryptoKit\RPCJSON;
use Moontoast\Math\BigNumber;
use AmiLabs\DevKit\Logger;
use AmiLabs\DevKit\Registry;

class EthereumMongo implements ILayer
{
    /**
     * RPC execution object
     *
     * @var \AmiLabs\CryptoKit\RPC
     */
    protected $oRPC;

    /**
     * EthereumDB object
     *
     * @var \AmiLabs\CryptoKit\Blockchain\EthereumDB
     */
    protected $oDB;

    /**
     * Flag specifying that PHP integer is 32bit only
     *
     * @var bool
     */
    protected $is32bit;

    /**
     * Service-specific configuration
     *
     * @var array
     */
    protected $aConfig;

    /**
     * Database connection object
     *
     * @var \PDO
     */
    // protected $oDB;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->is32bit = PHP_INT_MAX <= 2147483647;
    }

    /**
     * Checks if Counterparty server is up and running.
     *
     * @param  array $aConfig  Server configuration
     * @return bool
     */
    public function checkServerConfig(array $aConfig)
    {
        $result = TRUE;

        //$this->aConfig = $this->getConfig()->get('MRService', array());
        /*
        $oLogger = Logger::get('check-eth-servers');
        if(isset($aConfig['eth-service'])){
            $address = $aConfig['eth-service']['address'];
            $aContextOptions = array('http' => array('timeout' => 5), 'ssl'  => array('verify_peer' => FALSE, 'verify_peer_name' => FALSE));
            $state = @file_get_contents($address, FALSE, stream_context_create($aContextOptions));
            $result = ($state && (substr($state, 0, 1) == '{') && ($aState = json_decode($state, TRUE)) && is_array($aState) && isset($aState['counterparty-server']) && ('OK' == $aState['counterparty-server']));
            $oLogger->log($result ? ('OK: ' . $address . ' is UP and RUNNING, using as primary') : ('ERROR: ' . $address . ' is DOWN, skipping'));
        }else{
            // Can not check state without Counterblock
            $oLogger->log('SKIP: No counterblock information in RPC config');
        }
        */
        return $result;
    }

    /**
     * Returns some operational parameters for the server.
     *
     * @param  bool $ignoreLastBlockInfo  Flag specifying to ignore last block info if not available
     * @param  bool $logResult            Flag specifying to log result
     * @return array
     * @throws RuntimeException  optionally, if last block info not available
     */
    public function getServerState($ignoreLastBlockInfo = FALSE, $logResult = FALSE)
    {
        $aState = $this->getRPC()->exec('eth-service', 'getServerState', array(), $logResult);
        return $aState;
    }

    /**
     * Returns wallets/assets balances.
     *
     * @return string
     */
    public function getBalancesServiceName(){
        return 'eth-service';
    }

    /**
     * Returns list of block transactions.
     *
     * @param  int  $blockIndex   Block index
     * @param  bool $logResult    Flag specifying to log result
     * @param  bool $cacheResult  Flag specifying to cache result
     * @return mixed
     */
    public function getBlock($blockIndex, $logResult = FALSE, $cacheResult = TRUE)
    {
        $result = $this->getDB()->getBlockTransactions($blockIndex);
        return $result;
    }

    /**
     * Returns detailed block information.
     *
     * @param  int  $blockIndex   Block index
     * @param  bool $logResult    Flag specifying to log result
     * @param  bool $cacheResult  Flag specifying to cache result
     * @return mixed
     */
    public function getBlockInfo($blockIndex, $logResult = FALSE, $cacheResult = TRUE)
    {
        return $this->getBlock($blockIndex, $logResult, $cacheResult);
    }

    /**
     * Returns transaction raw hex with (or without) extended info.
     *
     * @param string $txHash     Transaction hash
     * @param bool $onlyHex      Return only tx raw hex if set to true
     * @param bool $logResult    Flag specifying to log result
     * @param bool $cacheResult  Flag specifying to cache result
     * @return mixed
     */
    public function getRawTransaction($txHash, $extended = FALSE, $logResult = FALSE, $cacheResult = TRUE){
        return array();
    }

    /**
     * Returns newest unconfirmed transactions.
     *
     * @param bool $logResult  Flag specifying to log result
     * @return array
     */
    public function getLastTransactions($logResult = FALSE){
        $result = $this->getRPC()->exec('eth-service', 'getLastTransactions', array(), $logResult);
        return is_array($result) ? $result : array();
    }

    /**
     * Returns asset related information from transaction.
     *
     * @param  string $txHash       Transaction hash or raw data
     * @param  bool   $hashPassed   Flag specifying that in previous argument passed hash
     * @param  bool   $logResult    Flag specifying to log result
     * @param  bool   $cacheResult  Flag specifying to cache result
     * @return array(
     *     'source'      => 'Source address',
     *     'destination' => 'Destination address',
     *     'asset'       => 'Asset',
     *     'quantity'    => 'Quantity',
     *     'type'        => ... // Tx type
     * )
     * @throws UnexpectedValueException in case of unknown transaction type
     * @todo   Count correct quantity for BTC txs
     */
    public function getAssetInfoFromTx(
        $txHash,
        $hashPassed = TRUE,
        $logResult = FALSE,
        $cacheResult = TRUE
    )
    {
        $aResult = array();
        $aData = $this->getRPC()->exec('eth-service', $hashPassed ? 'getTx' : 'decodeRawTx', array($txHash), $logResult, $cacheResult);
        if(isset($aData['asset'])){
            $aResult = array(
                'source'      => $aData['from'],
                'destination' => $aData['to'],
                'asset'       => $aData['asset'],
                'quantity'    => $aData['quantity'],
                'type'        => $aData['opType'],
                'gas'         => isset($aData['gas']) ? $aData['gas'] : 0,
                'gasUsed'     => isset($aData['gasUsed']) ? $aData['gasUsed'] : 0
            );
        }
        return $aResult;
    }

    /**
     * Returns transactions from blocks filtered by passed asset.
     *
     * @param  array  $aAssets        List of assets
     * @param  array  $aBlockIndexes  List of block indexes
     * @param  bool   $logResult      Flag specifying to log result
     * @param  bool   $cacheResult    Flag specifying to cache result
     * @return array
     */
    public function getAssetTxsFromBlocks(array $aAssets, array $aBlockIndexes, $logResult = FALSE, $cacheResult = TRUE){
        throw new Exception('Method is not supported');
    }

    /**
     * Creates specified tx sending amount of asset from source
     * to destination and returns raw tx data.
     *
     * @param  string $source       Source address
     * @param  string $destination  Destination address
     * @param  string $asset        Asset name
     * @param  int    $amount       Amount (in satoshi)
     * @param  array  $aPublicKeys  List of public keys of all addresses
     * @param  bool   $logResult    Flag specifying to log result
     * @return string
     */
    public function send($source, $destination, $asset, $amount, array $aPublicKeys = array(), $logResult = TRUE)
    {
        return $this->getRPC()->exec('eth-service', 'createSendTx', array($source, $destination, $asset, $amount), $logResult);
    }

    /**
     * Signs raw tx.
     *
     * @param  string $rawData
     * @param  string $privateKey
     * @param  bool   $logResult    Flag specifying to log result
     * @return string
     * @todo   Cover by unit tests
     */
    public function signRawTx($rawData, $privateKey, $logResult = TRUE)
    {
        return $this->getRPC()->exec('eth-service', 'signTx', array($rawData, $privateKey), $logResult);
    }

    /**
     * Sends raw tx.
     *
     * @param  string $rawData
     * @param  bool   $logResult  Flag specifying to log result
     * @return string
     * @todo   Cover by unit tests
     */
    public function sendRawTx($rawData, $logResult = TRUE){
        return $this->getRPC()->exec('eth-service', 'sendTx', array($rawData), $logResult);
    }

    /**
     * Decodes raw tx.
     *
     * @param  string $rawData
     * @param  bool   $logResult    Flag specifying to log result
     * @param  bool   $cacheResult  Flag specifying to cache result
     * @return array
     */
    public function decodeRawTx($rawData, $logResult = FALSE, $cacheResult = TRUE)
    {
        $data = $this->getRPC()->exec('eth-service', 'decodeRawTx', array($rawData), $logResult, $cacheResult);
        return $data;
    }

    /**
     * Returns number of transaction confirmations.
     *
     * @param string $txHash     Transaction hash
     * @param bool $onlyHex      Return only tx raw hex if set to true
     * @param bool $logResult    Flag specifying to log result
     * @return mixed
     */
    public function getTxConfirmations($txHash, $logResult = FALSE){
        $result = 0;
        $aTxData = $this->getDB()->getTransactionDetails($txHash);
        if(isset($aTxData['tx'])){
            $result = (int)$aTxData['tx']['confirmations'];
        }
        return $result;
    }

    /**
     * Returns wallets/assets balances.
     *
     * @param  array $aAssets       List of assets
     * @param  array $aWallets      List of wallets
     * @param  array $aExtraParams  Extra params
     * @param  bool  $logResult     Flag specifying to log result
     * @return array
     */
    public function getBalances(
        array $aAssets = array(),
        array $aWallets = array(),
        array $aExtraParams = array(),
        $logResult = FALSE
    ){
        $aResult = array();
        foreach($aWallets as $address){
            $aBalances = $this->getDB()->getAddressBalances($address);
            $aResult[$address] = array();
            foreach($aBalances as $aBalance){
                $aResult[$address] = array($aBalance['contract'] => $aBalance['balance']['c'][0]);
            }
        }
        return $aResult;
    }

    /**
     * Returns addresse balances of blockchain native coin (BTC, ETH, etc).
     *
     * @param string $aAddresses    Addresses list
     * @param bool   $logResult     Flag specifying to log result
     * @param bool   $cacheResult   Flag specifying to cache result
     * @return array
     */
    public function getFuelBalance($aAddresses, $logResult = FALSE){
        $aResult = array();
        $geth = Registry::useStorage('CFG')->get('CryptoKit/ethereum', FALSE);
        if(FALSE !== $geth){
            $oEthRPC = new RPCJSON(array('address' => $geth));
            foreach($aAddresses as $address){
                $balance = $oEthRPC->exec('eth_getBalance', array($address, 'latest'));
                if(FALSE !== $balance){
                    $balance = hexdec(str_replace('0x', '', $balance)) / pow(10, 18);
                    $aResult[$address] = array('ETH' => $balance);
                }
            }
        }
        return $aResult;
    }

    /**
     * Checks if bitcoind::getrawtransaction response can be stored in cache.
     *
     * @param mixed $response  Bitcoind response
     * @return bool
     * @todo   Cover by unit tests
     */
    public function validateGetRawTransactionCache($response){
        throw new Exception('Method is not supported');
    }

    /**
     * Creates new RPC object, or uses existing one.
     *
     * @return \AmiLabs\CryptoKit\RPC
     */
    protected function getRPC()
    {
        if(is_null($this->oRPC)){
            $this->oRPC = new RPC;
        }
        return $this->oRPC;
    }

    /**
     * Creates new EthereumDB object, or uses existing one.
     *
     * @return \AmiLabs\CryptoKit\Blockchain\EthereumDB
     */
    protected function getDB()
    {
        if(is_null($this->oDB)){
            $this->oDB = EthereumDB::db();
        }
        return $this->oDB;
    }
}
