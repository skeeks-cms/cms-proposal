<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\components;

use skeeks\cms\base\Component;
use skeeks\yii2\form\fields\BoolField;

/**
 * Settings of the "Предложения" section for the site (cms_component_settings,
 * the standard settings window of a component, "Настройки" in the section).
 */
class ProposalSettings extends Component
{
    /** The rule "задача при первом открытии": a call task for the responsible employee. */
    public $taskOnFirstView = 1;

    /** @var bool[] per site, within one run */
    private static array $taskOnFirstViewBySite = [];

    public function rules()
    {
        return [
            [['taskOnFirstView'], 'boolean'],
        ];
    }

    public function attributeLabels()
    {
        return [
            'taskOnFirstView' => 'Задача менеджеру при первом открытии',
        ];
    }

    public function attributeHints()
    {
        return [
            'taskOnFirstView' => 'Когда клиент впервые открыл КП или презентацию по ссылке, ответственному ставится задача «Позвонить» на сегодня '
                .'(вне рабочего времени — на ближайший рабочий день). Если по предложению уже есть открытая задача, новая не ставится.',
        ];
    }

    public function getConfigFormFields()
    {
        return [
            'taskOnFirstView' => ['class' => BoolField::class, 'allowNull' => false],
        ];
    }

    /** Is the rule on for the site: read in the client request and in jobs alike (component-settings.md). */
    public static function taskOnFirstView(int $siteId): bool
    {
        if (!isset(self::$taskOnFirstViewBySite[$siteId])) {
            $settings = new static();
            if ($site = \skeeks\cms\models\CmsSite::findOne($siteId)) {
                $settings->cmsSite = $site;
            }
            $settings->refresh();
            self::$taskOnFirstViewBySite[$siteId] = (bool)$settings->taskOnFirstView;
        }

        return self::$taskOnFirstViewBySite[$siteId];
    }

    public static function reset(): void
    {
        self::$taskOnFirstViewBySite = [];
    }
}
