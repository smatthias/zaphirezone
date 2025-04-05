<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Rector\Assign\CallOnAppArrayAccessToStandaloneAssignRector;
use RectorLaravel\Rector\MethodCall\ReverseConditionableMethodCallRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->import(__DIR__ . '/../config.php');
    $rectorConfig->rule(CallOnAppArrayAccessToStandaloneAssignRector::class);
    $rectorConfig->rule(ReverseConditionableMethodCallRector::class);
};
