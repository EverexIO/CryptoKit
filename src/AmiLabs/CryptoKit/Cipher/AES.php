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

namespace AmiLabs\CryptoKit\Cipher;

use AmiLabs\CryptoKit\ICipher;

/**
 * AES cipher implementation.
 */
class AES implements ICipher{
    /**
     * Generates key and initialization vector.
     *
     * @param  string $password
     * @param  string $salt
     * @param  string $cipher
     * @return array  ['key' => '...', 'iv' => '...']
     */
    public function generateKey($password, $salt, $cipher){
        /**
         * Number of rounds depends on the size of the AES in use:
         * - 3 rounds for 256 (2 rounds for the key, 1 for the IV)
         * - 3 rounds for 192 since it's not evenly divided by 128 bits
         * - 2 rounds for 128 (1 round for the key, 1 round for the IV)
         * @see https://github.com/mdp/gibberish-aes
         */
        $rounds = 3;
        if(preg_match('/\d+/', $cipher, $aMatches)){
            $bits = (int)$aMatches[0];
            switch($bits){
                case 128:
                    $rounds = 2;
                    break;
            }
        }

        $data00 = $password . $salt;
        $aMD5Hash = array();
        $aMD5Hash[0] = md5($data00, TRUE);
        $result = $aMD5Hash[0];
        for($i = 1; $i < $rounds; ++$i){
            $aMD5Hash[$i] = md5($aMD5Hash[$i - 1] . $data00, TRUE);
            $result .= $aMD5Hash[$i];
        }
        $aResult = array(
            'key'  => substr($result, 0, 32),
            'iv'   => substr($result, 32,16),
        );

        return $aResult;
    }
}
