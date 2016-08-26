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

namespace AmiLabs\CryptoKit\Crypt;

use AmiLabs\CryptoKit\ICrypt;

/**
 * Encrypting/decrypting OpenSSL implementation.
 */
class OpenSSL implements ICrypt{
    /**
     * Generates and returns salt.
     *
     * @return string
     */
    public function generateSalt(){
        $salt = openssl_random_pseudo_bytes(8);

        return $salt;
    }

    /**
     * Encrypts data.
     *
     * @param  string $data
     * @param  string $cipher    {@see http://php.net/manual/en/function.openssl-get-cipher-methods.php}
     * @param  string $password
     * @param  string $iv        A non-NULL Initialization Vector
     * @return string
     */
    public function encrypt($data, $cipher, $password, $iv = ''){
        $encrypted = @openssl_encrypt(
            $data,
            $cipher,
            $password,
            OPENSSL_RAW_DATA,
            $iv
        );

        return $encrypted;
    }

    /**
     * Decrypts data.
     *
     * @param  string $data
     * @param  string $cipher    {@see http://php.net/manual/en/function.openssl-get-cipher-methods.php}
     * @param  string $password
     * @param  string $iv        A non-NULL Initialization Vector
     * @return string
     */
    public function decrypt($data, $cipher, $password, $iv = ''){
        $decrypted = openssl_decrypt(
            $data,
            $cipher,
            $password,
            OPENSSL_RAW_DATA,
            $iv
        );

        return $decrypted;
    }
}
