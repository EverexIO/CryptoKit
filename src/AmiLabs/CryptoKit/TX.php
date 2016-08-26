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
use AmiLabs\DevKit\Registry;

if(!function_exists('coinspark_unpack_raw_txn')){
    $pathLib = Registry::useStorage('CFG')->get('path/lib');
    require_once $pathLib . '/artemko7v/php-op_return/php-OP_RETURN.php';
    unset($pathLib);
}

/**
 * Transaction helper class.
 *
 * @todo: move to BlockchainLayer subclass, like BlockchainIO::...->getTXHelper();
 */
class TX {
    /**
     * Satoshi divider
     */
    const SATOSHI = 100000000;

    /**
     * Decodes OP_RETURN output from raw transaction hex.
     *
     * @param string $rawTXN  Raw transaction hex
     * @param boolean $asHex  Convert decoded value to hex if true
     * @return string
     */
    public static function getDecodedOpReturn($rawTXN, $asHex = true)
    {
        $aTXNunpacked = coinspark_unpack_raw_txn($rawTXN);
        $res = '';
        foreach($aTXNunpacked['vout'] as $aOut){
            if(isset($aOut['scriptPubKey']) && (strpos($aOut['scriptPubKey'], '6a') === 0)){
                $res = pack('H*', substr($aOut['scriptPubKey'], 4));
            }
        }
        $result = unpack('H*', $res);

        return $asHex ? reset($result) : $res;
    }

    /**
     * Adds OP_RETURN output to raw transaction hex.
     *
     * @param string $rawTXN    Source raw transaction string
     * @param string $metadata  Metadata to iclude in transaction (40 bytes max)
     * @return string
     */
    public static function addOpReturnOutput($rawTXN, $metadata)
	{
            $aTXNunpacked = coinspark_unpack_raw_txn($rawTXN);
            $aTXNunpacked['vout'][] = array(
                    'value' => 0,
                    'scriptPubKey' => '6a' . reset(unpack('H*', chr(strlen($metadata)) . $metadata)),
            );
            return coinspark_pack_raw_txn($aTXNunpacked);
	}

    /**
     * Adds custom OP_HASH output. Not recommended to use because it eats memory.
     *
     * @param string $rawTXN   Source raw transaction string
     * @param type $hexString  32 bytes hex string (deadbeefcafe0000000000000000000000000001)
     * @return string
     */
    public static function addOpHashOutput($rawTXN, $hexString)
    {
        $aTXNunpacked = coinspark_unpack_raw_txn($rawTXN);
        $aTXNunpacked['vout'][]=array(
            'value' => 0.000078,
            'scriptPubKey' => '76a914' . $hexString . '88ac'
        );
        return coinspark_pack_raw_txn($aTXNunpacked);
	}

    /**
     * Decodes raw transaction data into array.
     *
     * @param string $rawTXN
     * @return array
     */
    public static function decodeTransaction($rawTXN)
    {
        return coinspark_unpack_raw_txn($rawTXN);
    }

    /**
     * Store data in multisig output.
     *
     * @param type $rawTXN
     * @param type $hexString
     * @return type
     */
    public static function addMultisigDataOutput($rawTXN, $data)
    {
        $hexString = reset(unpack('H*', $data));
        $dataLength = strlen($hexString);
        if($dataLength <= 196){
            $hexString = str_pad($hexString, 196, '0', STR_PAD_LEFT);
        }else{
           // data is too big
           // more outputs: todo
        }
        $hexPart1 = substr($hexString, 0, 64);
        $hexPart2 = substr($hexString, 64, 66);
        $hexPart3 = substr($hexString, 130, 66);

        $hexString = reset(unpack('H*', chr($dataLength))) . $hexPart1 . '21' . $hexPart2 . '21' . $hexPart3;
        $aTXNunpacked = coinspark_unpack_raw_txn($rawTXN);
        $last = array_pop($aTXNunpacked['vout']);
        $aTXNunpacked['vout'][]=array(
            'value' => 0.000078,
            'scriptPubKey' => '5121' . $hexString . '53ae'
        );
        $aTXNunpacked['vout'][]=$last;
        return coinspark_pack_raw_txn($aTXNunpacked);
	}

    /**
     * Returns hash of a signed transaction without broadcast.
     *
     * @param string $rawHexData  Signed tx raw hex
     * @return string
     */
    public static function calculateTxHash($rawHexData)
    {
        $reversedHash = hash('sha256', hash('sha256', pack("H*", trim($rawHexData)), true));
        $unpacked = unpack('H*', strrev(pack('H*', $reversedHash)));
        return reset($unpacked);
    }

    /**
     * Converts float value into Satoshis
     *
     * @param float $value  Value to convert to Satoshis
     * @return int
     */
    public static function floatToSatoshi($value)
    {
        return round(floatval($value) * self::SATOSHI);
    }

    /**
     * Converts Satoshis value into float value
     *
     * @param int $value  Value in Satoshi to convert
     * @return float
     */
    public static function satoshiToFloat($value)
    {
        return $value / self::SATOSHI;
    }
}
