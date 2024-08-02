<?php

declare(strict_types = 1);

namespace OCA\FileImport\AppInfo;

use OCP\AppFramework\App;

class Application extends App {
    
    public const APP_ID = 'file_import';
    
    public function __construct() {
        parent::__construct(self::APP_ID);
    }
    
}
