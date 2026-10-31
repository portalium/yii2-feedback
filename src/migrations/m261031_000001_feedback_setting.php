<?php

use portalium\site\models\Form;
use portalium\site\Module;
use yii\db\Migration;
use yii\db\Query;

class m261031_000001_feedback_setting extends Migration
{
    private const SETTINGS = [
        'feedback::key' => 'Feedback Widget Key',
        'feedback::mail' => 'Feedback Report Emails',
    ];

    public function safeUp()
    {
        $table = Module::$tablePrefix . 'setting';
        foreach (self::SETTINGS as $name => $label) {
            if ((new Query())->from($table)->where(['name' => $name])->exists($this->db)) {
                continue;
            }
            $this->insert($table, [
                'module' => 'feedback',
                'name' => $name,
                'label' => $label,
                'value' => '',
                'type' => Form::TYPE_INPUTTEXT,
                'config' => '',
                'is_preference' => 0,
            ]);
        }
        return true;
    }

    public function safeDown()
    {
        $this->delete(Module::$tablePrefix . 'setting', ['name' => array_keys(self::SETTINGS)]);
        return true;
    }
}
