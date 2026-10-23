<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal;

use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use skeeks\cms\proposal\helpers\ProposalUi;
use skeeks\cms\proposal\models\CmsProposal;
use yii\base\BootstrapInterface;
use yii\base\Event;

/**
 * Plugs proposals into the CRM: the proposal as a CRM record for links and
 * notifications, and the "КП" block with "Создать КП" in the cards of
 * leads, companies and deals (skeeks/cms CrmCardBlocksWidget).
 */
class ProposalBootstrap implements BootstrapInterface
{
    /** Order of the "КП" block among the blocks of other packages. */
    public const CARD_BLOCK_PRIORITY = 50;

    public function bootstrap($app)
    {
        // Added here, not in the config: configuring the property would replace the core list.
        if ($app->has('skeeks')) {
            $app->skeeks->modelsConfig[CmsProposal::class] = [
                'name' => 'Предложения',
                'name_one' => 'Предложение',
                'controller' => 'cmsProposal/admin-cms-proposal',
            ];
        }

        $widget = '\skeeks\cms\widgets\admin\CrmCardBlocksWidget';
        if (!$app instanceof \yii\web\Application || !class_exists($widget)) {
            return;
        }
        Event::on($widget, $widget::EVENT_BLOCKS, static function ($event) {
            $owner = $event->owner;
            // A client person has the block too; an employee card has none.
            if (!$owner instanceof CmsLead && !$owner instanceof CmsCompany && !$owner instanceof CmsDeal
                && !($owner instanceof CmsUser && !$owner->is_worker)) {
                return;
            }
            $event->blocks['proposals'] = ProposalUi::cardBlock($owner);
            $event->priority['proposals'] = self::CARD_BLOCK_PRIORITY;
        });
        // "Отправить презентацию" and "Сделать КП" in the next step of the card.
        // An older core has no actions event.
        if (defined(ltrim($widget, '\\').'::EVENT_ACTIONS')) {
            Event::on($widget, $widget::EVENT_ACTIONS, static function ($event) {
                if (!\Yii::$app->user->can(\skeeks\cms\rbac\CmsManager::PERMISSION_ADMIN_ACCESS)) {
                    return;
                }
                foreach (ProposalUi::nextStepActions($event->owner) as $id => $action) {
                    $event->actions[$id] = $action;
                    $event->priority[$id] = $action['priority'];
                }
            });
        }
    }
}
