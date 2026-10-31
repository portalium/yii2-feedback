<?php

namespace portalium\feedback\bundles;

use yii\web\AssetBundle;

/**
 * BugPin feedback widget; the settings come from window.BugPinSettings.
 */
class BugPinAsset extends AssetBundle
{
    public $sourcePath = '@vendor/portalium/yii2-feedback/src/assets/bugpin';

    public $js = [
        'widget.js',
        'bugpin.js',
    ];

    public $jsOptions = [
        'appendTimestamp' => true,
    ];

    public $publishOptions = [
        'forceCopy' => YII_DEBUG,
    ];
}
