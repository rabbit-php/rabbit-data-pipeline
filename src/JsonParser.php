<?php
declare(strict_types=1);

namespace Rabbit\Data\Pipeline;

use rabbit\exception\InvalidConfigException;
use rabbit\helper\FileHelper;
use rabbit\helper\JsonHelper;

/**
 * Class JsonParser
 * @package Rabbit\Data\Pipeline
 */
class JsonParser implements ConfigParserInterface
{
    /** @var string */
    protected $path;

    /**
     * JsonParser constructor.
     * @param string $path
     * @throws InvalidConfigException
     */
    public function __construct(string $path)
    {
        if (!is_dir($path) && !file_exists($path)) {
            throw new InvalidConfigException("The path must be dir or file");
        }
        $this->path = $path;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return array
     * @throws InvalidConfigException
     */
    public function parse(): array
    {
        $config = [];
        if (is_dir($this->path)) {
            FileHelper::findFiles($this->path, [
                'filter' => function ($path) use (&$config) {
                    if (!is_file($path)) {
                        return true;
                    }
                    if (pathinfo($path, PATHINFO_EXTENSION) !== 'yaml') {
                        return false;
                    }
                    $json = file_get_contents($path);
                    if ($json === false) {
                        throw new InvalidConfigException(error_get_last()['message'] . " path=$path");
                    }
                    $json = JsonHelper::decode($json, true);
                    $config[pathinfo($path, PATHINFO_FILENAME)] = $json;
                    return true;
                }
            ]);
        } else {
            $json = file_get_contents($this->path);
            if ($json === false) {
                throw new InvalidConfigException(error_get_last()['message'] . " path=$this->path");
            }
            $config[pathinfo($this->path, PATHINFO_FILENAME)] = JsonHelper::decode($json, true);
        }
        return $config;
    }
}