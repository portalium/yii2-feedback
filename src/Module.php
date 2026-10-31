<?php

namespace portalium\feedback;

use portalium\feedback\components\BugPin;
use yii\base\Event;
use yii\web\View;

class Module extends \portalium\base\Module
{
    public static $tablePrefix = 'feedback_';

    public static $name = 'feedback';

    public static $description = 'Feedback Module';

    /**
     * The BugPin widget builds its URLs from the site root, so the endpoints answer on /api/widget/...
     */
    public $apiRules = [
        ['pattern' => 'widget/config/<key:[A-Za-z0-9_-]+>', 'route' => 'feedback/widget/config', 'verb' => 'GET'],
        ['pattern' => 'widget/submit', 'route' => 'feedback/widget/submit', 'verb' => 'POST'],
    ];

    public static function moduleInit()
    {
        self::registerTranslation('feedback', __DIR__ . '/messages', [
            'feedback' => 'feedback.php',
        ]);
    }

    public static function t($message, array $params = [])
    {
        return parent::coreT('feedback', $message, $params);
    }

    public function registerEvents()
    {
        Event::on(View::class, View::EVENT_BEGIN_BODY, [BugPin::class, 'onBeginBody']);
    }
}
