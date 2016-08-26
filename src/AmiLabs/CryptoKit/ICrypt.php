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

/**
 * Encrypting/decrypting interface.
 */
interface ICrypt{
    /**
     * Generates and returns salt.
     *
     * @return string
     */
    public function generateSalt();

    /**
     * Encrypts data.
     *
     * @param  string $data
     * @param  string $cipher
     * @param  string $password
     * @param  string $iv        A non-NULL Initialization Vector
     * @return string
     */
    public function encrypt($data, $cipher, $password, $iv = '');

    /**
     * Decrypts data.
     *
     * @param  string $data
     * @param  string $cipher
     * @param  string $password
     * @param  string $iv        A non-NULL Initialization Vector
     * @return string
     */
    public function decrypt($data, $cipher, $password, $iv = '');
}
