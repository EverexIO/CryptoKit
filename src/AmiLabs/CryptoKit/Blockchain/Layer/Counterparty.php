<?php

namespace AmiLabs\CryptoKit\Blockchain\Layer;

use AmiLabs\CryptoKit\Blockchain\ILayer;
use AmiLabs\CryptoKit\RPC;
use Moontoast\Math\BigNumber;

class Counterparty implements ILayer
{
    /**
     * RPC execution object
     *
     * @var \AmiLabs\CryptoKit\RPC
     */
    protected $oRPC;

    public function __construct()
    {
        $this->oRPC = new RPC;
    }

    /**
     * Returns some operational parameters for the server.
     *
     * @param  bool $logResult  Flag specifying to log result
     * @return array
     */
    public function getServerState($logResult = FALSE)
    {
        return
            $this->oRPC->execCounterpartyd(
                'get_running_info',
                array(),
                $logResult,
                FALSE
            );
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
        return
            $this->oRPC->execCounterpartyd(
                'get_block_info',
                array('block_index' => $blockIndex),
                $logResult,
                $cacheResult
            );
    }

    /**
     * Returns asset related information from transaction.
     *
     * @param  string $txHash       Transaction hash
     * @param  bool   $logResult    Flag specifying to log result
     * @param  bool   $cacheResult  Flag specifying to cache result
     * @return array('type' => ..., 'asset' => ..., 'quantity' => ..., 'type' => ...)
     * @throws UnexpectedValueException in case of unknown transaction type
     */
    public function getAssetInfoFromTx($txHash, $logResult = FALSE, $cacheResult = TRUE)
    {
        $aData = $this->oRPC->execBitcoind(
            'getrawtransaction',
            array($txHash, 1),
            $logResult,
            $cacheResult
        );
        /*
        $aBlock =
            $this->oRPC->execBitcoind(
                'getblock',
                array($aData['blockhash']),
                $logResult,
                $cacheResult
            );
        */

        $aResult = $this->oRPC->execCounterpartyd(
            'get_tx_info',
            array(
                'tx_hex'      => $aData['hex'],
                ### 'block_index' => $aBlock['height']
            ),
            $logResult,
            $cacheResult
        );
        $data = $aResult[4];
        $type = hexdec(mb_substr($data, 0, 8));
        $assetName = mb_substr($data, 8, 16);
        $quantity = mb_substr($data, 24, 16);
        $assetId =
            new BigNumber(
                BigNumber::convertToBase10($assetName, 16)
            );
        if('00000000' != mb_substr($assetName, 0, 8)){
            $asset = 'A' . $assetId->getValue();
        }else{
            $asset = '';
            $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            do{
                $tmpAssetId = clone($assetId);
                $reminder = (int)$tmpAssetId->mod(26)->getValue();
                $asset .= $alphabet[$reminder];
                $assetId = $assetId->divide(26)->floor();
            }while($assetId->getValue() > 0);
            $asset = strrev($asset);
        }

        switch($type){
            case 0:
                $type = self::TXN_TYPE_SEND;
                break;
            case 20:
                $type = self::TXN_TYPE_ISSUANCE;
                break;
            default:
                throw new UnexpectedValueException('Unknown transaction type ' . $type);
        }

        $quantity =
            new BigNumber(
                BigNumber::convertToBase10($quantity, 16)
            );

        return array('asset' => $asset, 'quantity' => $quantity, 'type' => $type);
    }

    /**
     * Returns transactions from blocks filtered by passed asset.
     *
     * @param  string $asset          Asset
     * @param  array  $aBlockIndexes  List of block indexes
     * @param  bool   $logResult      Flag specifying to log result
     * @param  bool   $cacheResult    Flag specifying to cache result
     * @return array
     */
    public function getAssetTxsFromBlocks(
        $asset,
        array $aBlockIndexes,
        $logResult = FALSE,
        $cacheResult = TRUE
    )
    {
        $aResult = array();
        $aBlocks = $this->oRPC->execCounterpartyd(
            'get_blocks',
            array('block_indexes' => $aBlockIndexes),
            $logResult,
            $cacheResult
        );
        foreach($aBlocks as $aBlock){
            if(empty($aBlock['_messages'])){
                continue;
            }
            file_put_contents('tx.log', print_r($aBlock['_messages'], TRUE), FILE_APPEND);###
            foreach($aBlock['_messages'] as $aBlockMessage){
                if(empty($aBlockMessage['bindings'])){
                    continue;
                }
                $aBindings = json_decode($aBlockMessage['bindings'], TRUE);
                if(!is_array($aBindings)){
                    continue;
                }
                if('order_matches' == $aBlockMessage['category']){
                    if(
                        'update' != $aBlockMessage['command'] &&
                        (
                            !isset($aBindings['forward_asset']) ||
                            !isset($aBindings['backward_asset']) ||
                            (
                                $asset != $aBindings['forward_asset'] &&
                                $asset != $aBindings['backward_asset']
                            )
                        )
                    ){
                        continue;
                    }
                }else{
                    if(
                        empty($aBindings['asset']) ||
                        $asset != $aBindings['asset']
                    ){
                        continue;
                    }
                }
                // 64-bit PHP integer hack
                if(isset($aBindings['quantity'])){
                    preg_match('/quantity":\s*(\d+)/', $aBlockMessage['bindings'], $aMatches);
                    $aBindings['quantity'] = $aMatches[1];
                }
                $aBlockMessage['bindings'] = $aBindings;
                $aResult[] = $aBlockMessage;
            }
        }

        return $aResult;
    }



    /**
     * Returns wallets/assets balances.
     *
     * @param  array $aAssets    List of assets
     * @param  array $aWallets   List of wallets
     * @param  bool  $logResult  Flag specifying to log result
     * @return array
     */
    public function getBalances(
        array $aAssets = array(),
        array $aWallets = array(),
        $logResult = FALSE
    ){
        $aParams = array('filters' => array());
        if(sizeof($aWallets)){
            $aParams['filters'][] = array(
                'field' => 'address',
                'op'    => 'IN',
                'value' => $aWallets
            );
        }
        if(sizeof($aAssets)){
            $aParams['filters'][] = array(
                'field' => 'asset',
                'op'    => 'IN',
                'value' => $aAssets
            );
        }

        $aBalances = $this->oRPC->execCounterpartyd(
            'get_balances',
            $aParams,
            $logResult,
            FALSE
        );

        return $aBalances;
    }
}
