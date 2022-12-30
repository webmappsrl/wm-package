<?php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;

class HoquCredentialsProvider
{

    /**
     * Set HOQU_USERNAME in .env file
     *
     * @param string $value
     * @return bool - return true on success false otherwise
     */
    public function setUsername($value)
    {
        return $this->setCredential('username', $value);
    }

    /**
     * Set HOQU_PASSWORD in .env file
     *
     * @param string $value
     * @return bool - return true on success false otherwise
     */
    public function setPassword($value)
    {
        return $this->setCredential('password', $value);
    }

    /**
     * Set HOQU_TOKEN in .env file
     *
     * @param string $value
     * @return bool - return true on success false otherwise
     */
    public function setToken($value)
    {
        return $this->setCredential('token', $value);
    }

    /**
     * Get HOQU_USERNAME from .env file
     *
     * @return string|false - return the value on success, false otherwise
     */
    public function getUsername()
    {
        return $this->getCredential('username');
    }

    /**
     * Get HOQU_PASSWORD from .env file
     *
     * @return string|false - return the value on success, false otherwise
     */
    public function getPassword()
    {
        return $this->getCredential('password');
    }

    /**
     * Get HOQU_TOKEN from .env file
     *
     * @return string|false - return the value on success, false otherwise
     */
    public function getToken()
    {
        return $this->getCredential('token');
    }


    /**
     * Store the credential in .env file
     *
     *
     * @param  string  $name
     * @param  string  $value
     * @return bool
     */
    private function setCredential($name, $value)
    {
        $check = $this->writeNewEnvironmentFileWith($name, $value);
        Artisan::call('config:cache');
        return $check;
    }

    /**
     * Get the hoqu token stored
     *
     * @param  string  $name
     *
     *
     * @return string|false - return the token if stored, false otherwise
     */
    private function getCredential($name)
    {
        return env($this->getEnvKeyByName($name), false);
    }


    /**
     * The convention of keys in .env file dedicated to this class
     *
     * @param string $name
     * @return string
     */
    private function getEnvKeyByName($name)
    {
        $name = strtoupper($name);
        return "HOQU_{$name}";
    }



    /**
     * Write a new environment file with the given key and value.
     *
     * @param  string  $key
     * @return bool
     */
    private function writeNewEnvironmentFileWith($key, $value)
    {

        $envKey = $this->getEnvKeyByName($key);
        $envPath = App::environmentFilePath();
        $replaced = preg_replace(
            $this->keyReplacementPattern($key),
            "{$envKey}={$value}",
            $input = file_get_contents($envPath)
        );

        if ($replaced === $input || $replaced === null) {
            $replaced .= "\n{$envKey}={$value}";
        }


        file_put_contents($envPath, $replaced);

        return true;
    }

    /**
     * Get a regex pattern that will match env HOQU_{key}
     *
     * @return string
     */
    private function keyReplacementPattern($name)
    {
        $envKey = $this->getEnvKeyByName($name);

        return "/^{$envKey}=(.*)/m";
    }
}
